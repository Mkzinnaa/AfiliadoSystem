<?php
declare(strict_types=1);
require_once __DIR__ . '/tenancy.php';
require_once __DIR__ . '/database.php';

function integration_encryption_key(): string
{
    $dir=__DIR__.'/../.runtime/app-data';if(!is_dir($dir))mkdir($dir,0775,true);$path=$dir.'/webhook.key';
    if(is_file($path)){$key=file_get_contents($path);if(is_string($key)&&strlen($key)===32)return $key;throw new RuntimeException('A chave de criptografia de integrações está inválida.');}
    $key=random_bytes(32);$handle=@fopen($path,'x');if($handle===false){if(is_file($path))return integration_encryption_key();throw new RuntimeException('Não foi possível criar a chave de integrações.');}fwrite($handle,$key);fclose($handle);@chmod($path,0600);return $key;
}
function integration_encrypt_secret(string $secret): string
{
    $nonce=random_bytes(12);$tag='';$cipher=openssl_encrypt($secret,'aes-256-gcm',integration_encryption_key(),OPENSSL_RAW_DATA,$nonce,$tag);if($cipher===false)throw new RuntimeException('Não foi possível proteger o segredo da integração.');return base64_encode($nonce.$tag.$cipher);
}
function integration_decrypt_secret(string $encoded): string
{
    $blob=base64_decode($encoded,true);if($blob===false||strlen($blob)<29)throw new RuntimeException('Segredo de integração inválido.');$plain=openssl_decrypt(substr($blob,28),'aes-256-gcm',integration_encryption_key(),OPENSSL_RAW_DATA,substr($blob,0,12),substr($blob,12,16));if($plain===false)throw new RuntimeException('Não foi possível abrir o segredo da integração.');return $plain;
}

function integration_create(string $name): array
{
    $name=trim($name);if($name===''||strlen($name)>80)throw new DomainException('Dê um nome à conexão (até 80 caracteres).');
    $pdo=app_db();$token=rtrim(strtr(base64_encode(random_bytes(32)),'+/','-_'),'=');$id=new_id('conn');
    try{$pdo->prepare("INSERT INTO integration_connections(id,tenant_id,name,platform,token_hash,secret_ciphertext) VALUES(?,?,?,'kiwify',?,?)")->execute([$id,tenant_id(),$name,hash('sha256',$token),integration_encrypt_secret($token)]);}catch(PDOException $e){if(str_contains(strtolower($e->getMessage()),'unique'))throw new DomainException('Já existe uma conexão com esse nome.');throw $e;}
    return ['id'=>$id,'name'=>$name,'token'=>$token];
}

function integration_list(): array
{
    $stmt=app_db()->prepare('SELECT c.id,c.name,c.status,c.last_event_at,c.created_at,COUNT(DISTINCT e.id) AS event_count,COUNT(DISTINCT o.id) AS order_count FROM integration_connections c LEFT JOIN integration_events e ON e.connection_id=c.id LEFT JOIN sales_orders o ON o.connection_id=c.id WHERE c.tenant_id=? GROUP BY c.id ORDER BY c.created_at DESC');
    $stmt->execute([tenant_id()]);return $stmt->fetchAll();
}

function integration_change(string $id,string $action): ?string
{
    $pdo=app_db();$stmt=$pdo->prepare('SELECT id FROM integration_connections WHERE id=? AND tenant_id=?');$stmt->execute([$id,tenant_id()]);if(!$stmt->fetchColumn())throw new DomainException('Conexão não encontrada neste espaço.');
    if($action==='toggle'){$pdo->prepare("UPDATE integration_connections SET status=CASE status WHEN 'active' THEN 'paused' ELSE 'active' END WHERE id=? AND tenant_id=?")->execute([$id,tenant_id()]);return null;}
    if($action==='rotate'){$token=rtrim(strtr(base64_encode(random_bytes(32)),'+/','-_'),'=');$pdo->prepare('UPDATE integration_connections SET token_hash=?,secret_ciphertext=? WHERE id=? AND tenant_id=?')->execute([hash('sha256',$token),integration_encrypt_secret($token),$id,tenant_id()]);return $token;}
    throw new DomainException('Ação de conexão inválida.');
}

function integration_record_ignored_event(array $connection,array $payload):array
{
    $eventName=(string)($payload['webhook_event_type']??'unknown');$orderId=(string)($payload['order_id']??'');$eventId=(string)($payload['webhook_event_id']??($orderId!==''?$orderId.':'.$eventName:new_id('evt')));$pdo=app_db();
    $insert = database_driver($pdo)==='mysql' ? 'INSERT IGNORE' : 'INSERT OR IGNORE';
    $stmt=$pdo->prepare("$insert INTO integration_events(id,tenant_id,connection_id,external_event_id,event_type,result,message) VALUES(?,?,?,?,?,'ignored','Evento válido, sem alteração de faturamento.')");$stmt->execute([new_id('evt'),$connection['tenant_id'],$connection['id'],$eventId,$eventName]);
    if($stmt->rowCount()>0)$pdo->prepare('UPDATE integration_connections SET last_event_at=CURRENT_TIMESTAMP WHERE id=?')->execute([$connection['id']]);
    return ['duplicate'=>$stmt->rowCount()===0,'ignored'=>true,'message'=>$stmt->rowCount()===0?'Evento já registrado.':'Evento recebido e registrado.'];
}

function integration_handle_webhook(string $connectionId,string $token,array $payload): array
{
    $pdo=app_db();$connectionQuery=$pdo->prepare('SELECT id,tenant_id,status,token_hash FROM integration_connections WHERE id=?');$connectionQuery->execute([$connectionId]);$connection=$connectionQuery->fetch();
    if(!$connection||$connection['status']!=='active'||$token===''||!hash_equals($connection['token_hash'],hash('sha256',$token)))throw new DomainException('Conexão não autorizada.');
    $eventId=trim((string)($payload['event_id']??''));$eventType=(string)($payload['type']??'');$order=$payload['order']??null;
    if($eventId===''||strlen($eventId)>160||!is_array($order))throw new DomainException('Evento inválido: são obrigatórios event_id e order.');
    if(!in_array($eventType,['sale.approved','sale.refunded','sale.canceled'],true))throw new DomainException('Tipo de evento não suportado.');
    $externalId=trim((string)($order['id']??''));$currency=strtoupper((string)($order['currency']??'BRL'));$amount=filter_var($order['amount']??null,FILTER_VALIDATE_FLOAT);
    if($externalId===''||strlen($externalId)>160||$amount===false||$amount<0||$amount>100000000||$currency!=='BRL')throw new DomainException('Pedido inválido: informe id, valor em reais e moeda BRL.');
    $affiliateCode=trim((string)($order['affiliate_code']??''));$affiliateEmail=trim((string)($order['affiliate_email']??''));if(strlen($affiliateCode)>100||strlen($affiliateEmail)>254)throw new DomainException('Identificador de afiliado muito longo.');
    $saleDate=(string)($order['created_at']??gmdate('Y-m-d\TH:i:s\Z'));$timestamp=strtotime($saleDate);if($timestamp===false||$timestamp>time()+86400)throw new DomainException('Data da venda inválida.');$saleDate=gmdate('Y-m-d H:i:s',$timestamp);
    $status=match($eventType){'sale.approved'=>'approved','sale.refunded'=>'refunded','sale.canceled'=>'canceled'};
    $pdo->beginTransaction();
    try{
        $duplicate=$pdo->prepare('SELECT id FROM integration_events WHERE connection_id=? AND external_event_id=?');$duplicate->execute([$connectionId,$eventId]);
        if($duplicate->fetchColumn()){$pdo->commit();return ['duplicate'=>true,'message'=>'Evento já processado.'];}
        $affiliateId=null;$storedAffiliateCode=$affiliateCode;if($affiliateCode!==''||$affiliateEmail!==''){$affiliate=$pdo->prepare('SELECT id,code FROM affiliates WHERE tenant_id=? AND (LOWER(code)=LOWER(?) OR LOWER(email)=LOWER(?)) LIMIT 1');$affiliate->execute([$connection['tenant_id'],$affiliateCode,$affiliateEmail]);$matched=$affiliate->fetch();if($matched){$affiliateId=(string)$matched['id'];$storedAffiliateCode=(string)$matched['code'];}elseif($affiliateEmail!=='')$storedAffiliateCode='';}
        $upsertSql = database_driver($pdo)==='mysql'
            ? 'INSERT INTO sales_orders(id,tenant_id,connection_id,external_order_id,affiliate_id,affiliate_code,amount_cents,currency,status,sold_at) VALUES(?,?,?,?,?,?,?,?,?,?) ON DUPLICATE KEY UPDATE affiliate_id=VALUES(affiliate_id),affiliate_code=VALUES(affiliate_code),amount_cents=VALUES(amount_cents),status=VALUES(status),sold_at=VALUES(sold_at),updated_at=CURRENT_TIMESTAMP'
            : 'INSERT INTO sales_orders(id,tenant_id,connection_id,external_order_id,affiliate_id,affiliate_code,amount_cents,currency,status,sold_at) VALUES(?,?,?,?,?,?,?,?,?,?) ON CONFLICT(connection_id,external_order_id) DO UPDATE SET affiliate_id=excluded.affiliate_id,affiliate_code=excluded.affiliate_code,amount_cents=excluded.amount_cents,status=excluded.status,sold_at=excluded.sold_at,updated_at=CURRENT_TIMESTAMP';
        $upsert=$pdo->prepare($upsertSql);
        $upsert->execute([new_id('sale'),$connection['tenant_id'],$connectionId,$externalId,$affiliateId,$storedAffiliateCode,(int)round((float)$amount*100),$currency,$status,$saleDate]);
        $pdo->prepare('INSERT INTO integration_events(id,tenant_id,connection_id,external_event_id,event_type,result,message) VALUES(?,?,?,?,?,?,?)')->execute([new_id('evt'),$connection['tenant_id'],$connectionId,$eventId,$eventType,'processed',($affiliateCode!==''||$affiliateEmail!=='')&&$affiliateId===null?'Pedido recebido sem correspondência de afiliado.':'']);
        $pdo->prepare('UPDATE integration_connections SET last_event_at=CURRENT_TIMESTAMP WHERE id=?')->execute([$connectionId]);
        $refresh=$pdo->prepare("UPDATE affiliates SET sales=COALESCE((SELECT SUM(amount_cents)/100.0 FROM sales_orders WHERE affiliate_id=affiliates.id AND tenant_id=affiliates.tenant_id AND status='approved'),0),orders=(SELECT COUNT(*) FROM sales_orders WHERE affiliate_id=affiliates.id AND tenant_id=affiliates.tenant_id AND status='approved') WHERE tenant_id=? AND id IN (SELECT affiliate_id FROM sales_orders WHERE connection_id=? AND affiliate_id IS NOT NULL)");$refresh->execute([$connection['tenant_id'],$connectionId]);
        $pdo->commit();return ['duplicate'=>false,'message'=>'Evento recebido.'];
    }catch(Throwable $e){if($pdo->inTransaction())$pdo->rollBack();throw $e;}
}

function kiwify_handle_webhook(string $connectionId,string $signature,string $rawBody): array
{
    $pdo=app_db();$stmt=$pdo->prepare("SELECT id,tenant_id,token_hash,secret_ciphertext,status FROM integration_connections WHERE id=? AND platform='kiwify'");$stmt->execute([$connectionId]);$conn=$stmt->fetch();if(!$conn||$conn['status']!=='active')throw new DomainException('Conexão Kiwify não encontrada ou pausada.');
    $signature=trim($signature);if(str_starts_with(strtolower($signature),'sha1='))$signature=substr($signature,5);$secret=integration_decrypt_secret((string)$conn['secret_ciphertext']);
    $valid=hash_equals(hash_hmac('sha1',$rawBody,$secret),strtolower($signature));
    $payload=json_decode($rawBody,true);
    if(!$valid&&is_array($payload)){$canonical=json_encode($payload,JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE);if(is_string($canonical)){$hex=hash_hmac('sha1',$canonical,$secret);$valid=hash_equals($hex,$signature)||hash_equals(base64_encode(hex2bin($hex)),$signature);}}
    if(!$valid)throw new DomainException('Assinatura Kiwify inválida.');if(!is_array($payload))throw new DomainException('O corpo da requisição não contém JSON válido.');
    $eventName=(string)($payload['webhook_event_type']??'');$orderStatus=(string)($payload['order_status']??'');
    $eventType=match($eventName){'order_approved','subscription_renewed'=>'sale.approved','order_refunded'=>'sale.refunded','order_chargeback','order_chargedback','chargeback'=>'sale.refunded','order_canceled','subscription_canceled'=>'sale.canceled',default=>match($orderStatus){'paid'=>'sale.approved','refunded','chargedback'=>'sale.refunded','canceled','cancelled'=>'sale.canceled',default=>''}};
    if($eventType==='')return integration_record_ignored_event($conn,$payload);
    $orderId=trim((string)($payload['order_id']??''));$amountCents=filter_var($payload['Commissions']['charge_amount']??null,FILTER_VALIDATE_INT);$currency=strtoupper((string)($payload['Commissions']['currency']??''));if($amountCents===false||$amountCents===null)throw new DomainException('Evento Kiwify sem o valor Commissions.charge_amount em centavos.');
    $affiliateEmail='';foreach(($payload['Commissions']['commissioned_stores']??[]) as $store){if(is_array($store)&&strtolower((string)($store['type']??''))==='affiliate'){$affiliateEmail=(string)($store['email']??'');break;}}
    $eventSuffix=$eventName!==''?$eventName:$orderStatus;$eventId=(string)($payload['webhook_event_id']??($orderId.':'.$eventSuffix));
    return integration_handle_webhook($connectionId,$secret,['event_id'=>$eventId,'type'=>$eventType,'order'=>['id'=>$orderId,'amount'=>(float)$amountCents/100,'currency'=>$currency,'affiliate_email'=>$affiliateEmail,'created_at'=>$payload['approved_date']??$payload['created_at']??gmdate('c')]]);
}

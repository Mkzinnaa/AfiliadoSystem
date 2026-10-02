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

function integration_create(string $name,string $platform='kiwify',string $providedSecret=''): array
{
    $name=trim($name);$platform=strtolower(trim($platform));
    if($name===''||strlen($name)>80)throw new DomainException('Dê um nome à conexão (até 80 caracteres).');
    if(!in_array($platform,['kiwify','hotmart','eduzz'],true))throw new DomainException('Plataforma de vendas inválida.');
    $token=$platform==='kiwify'?rtrim(strtr(base64_encode(random_bytes(32)),'+/','-_'),'='):trim($providedSecret);
    if($platform==='hotmart'&&($token===''||strlen($token)>140))throw new DomainException('Informe o Hottok da Hotmart (até 140 caracteres).');
    if($platform==='eduzz'&&($token===''||strlen($token)>255))throw new DomainException('Informe a chave de assinatura da Eduzz (até 255 caracteres).');
    $pdo=app_db();$id=new_id('conn');
    try{$pdo->prepare('INSERT INTO integration_connections(id,tenant_id,name,platform,token_hash,secret_ciphertext) VALUES(?,?,?,?,?,?)')->execute([$id,tenant_id(),$name,$platform,hash('sha256',$token),integration_encrypt_secret($token)]);}catch(PDOException $e){if(str_contains(strtolower($e->getMessage()),'unique'))throw new DomainException('Já existe uma conexão com esse nome neste espaço.');throw $e;}
    return ['id'=>$id,'name'=>$name,'platform'=>$platform,'token'=>$platform==='kiwify'?$token:''];
}

function integration_customer_hash(string $tenantId, string $connectionId, string $email, string $externalId): ?string
{
    $email = strtolower(trim($email));
    if ($email !== '' && filter_var($email, FILTER_VALIDATE_EMAIL) !== false) {
        return hash_hmac('sha256', $tenantId . "\0email\0" . $email, integration_encryption_key());
    }
    $externalId = trim($externalId);
    if ($externalId !== '' && strlen($externalId) <= 160) {
        return hash_hmac('sha256', $tenantId . "\0connection\0" . $connectionId . "\0customer\0" . $externalId, integration_encryption_key());
    }
    return null;
}

function integration_list(): array
{
    $stmt=app_db()->prepare('SELECT c.id,c.name,c.platform,c.status,c.last_event_at,c.created_at,COUNT(DISTINCT e.id) AS event_count,COUNT(DISTINCT o.id) AS order_count FROM integration_connections c LEFT JOIN integration_events e ON e.connection_id=c.id LEFT JOIN sales_orders o ON o.connection_id=c.id WHERE c.tenant_id=? GROUP BY c.id ORDER BY c.created_at DESC');
    $stmt->execute([tenant_id()]);return $stmt->fetchAll();
}

function integration_change(string $id,string $action): ?string
{
    $pdo=app_db();$stmt=$pdo->prepare('SELECT id,platform FROM integration_connections WHERE id=? AND tenant_id=?');$stmt->execute([$id,tenant_id()]);$connection=$stmt->fetch();if(!$connection)throw new DomainException('Conexão não encontrada neste espaço.');
    if($action==='toggle'){$pdo->prepare("UPDATE integration_connections SET status=CASE status WHEN 'active' THEN 'paused' ELSE 'active' END WHERE id=? AND tenant_id=?")->execute([$id,tenant_id()]);return null;}
    if($action==='rotate'&&$connection['platform']==='hotmart')throw new DomainException('O Hottok é gerenciado pela Hotmart. Para trocá-lo, atualize a credencial da conexão.');
    if($action==='rotate'){$token=rtrim(strtr(base64_encode(random_bytes(32)),'+/','-_'),'=');$pdo->prepare('UPDATE integration_connections SET token_hash=?,secret_ciphertext=? WHERE id=? AND tenant_id=?')->execute([hash('sha256',$token),integration_encrypt_secret($token),$id,tenant_id()]);return $token;}
    throw new DomainException('Ação de conexão inválida.');
}

function integration_record_ignored_event(array $connection,array $payload,string $message='Evento válido, sem alteração de faturamento.'):array
{
    $eventName=(string)($payload['webhook_event_type']??$payload['event']??'unknown');$orderId=(string)($payload['order_id']??$payload['data']['purchase']['transaction']??'');$eventId=(string)($payload['webhook_event_id']??$payload['id']??($orderId!==''?$orderId.':'.$eventName:new_id('evt')));$pdo=app_db();
    $stmt=$pdo->prepare("INSERT IGNORE INTO integration_events(id,tenant_id,connection_id,external_event_id,event_type,result,message) VALUES(?,?,?,?,?,'ignored',?)");$stmt->execute([new_id('evt'),$connection['tenant_id'],$connection['id'],$eventId,$eventName,$message]);
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
    $affiliateCode=trim((string)($order['affiliate_code']??''));$affiliateEmail=trim((string)($order['affiliate_email']??''));$hotmartAffiliateCode=trim((string)($order['hotmart_affiliate_code']??''));if(strlen($affiliateCode)>100||strlen($affiliateEmail)>254||strlen($hotmartAffiliateCode)>100)throw new DomainException('Identificador de afiliado muito longo.');
    $customerHash=integration_customer_hash((string)$connection['tenant_id'],$connectionId,(string)($order['customer_email']??''),(string)($order['customer_id']??''));
    $saleDate=(string)($order['created_at']??gmdate('Y-m-d\TH:i:s\Z'));$timestamp=strtotime($saleDate);if($timestamp===false||$timestamp>time()+86400)throw new DomainException('Data da venda inválida.');$saleDate=gmdate('Y-m-d H:i:s',$timestamp);
    $status=match($eventType){'sale.approved'=>'approved','sale.refunded'=>'refunded','sale.canceled'=>'canceled'};
    $pdo->beginTransaction();
    try{
        $duplicate=$pdo->prepare('SELECT id FROM integration_events WHERE connection_id=? AND external_event_id=?');$duplicate->execute([$connectionId,$eventId]);
        if($duplicate->fetchColumn()){$pdo->commit();return ['duplicate'=>true,'message'=>'Evento já processado.'];}
        $affiliateId=null;$storedAffiliateCode=$affiliateCode;if($affiliateCode!==''||$affiliateEmail!==''||$hotmartAffiliateCode!==''){$affiliate=$pdo->prepare('SELECT id,code FROM affiliates WHERE tenant_id=? AND (LOWER(code)=LOWER(?) OR LOWER(email)=LOWER(?) OR LOWER(hotmart_code)=LOWER(?)) LIMIT 1');$affiliate->execute([$connection['tenant_id'],$affiliateCode,$affiliateEmail,$hotmartAffiliateCode]);$matched=$affiliate->fetch();if($matched){$affiliateId=(string)$matched['id'];$storedAffiliateCode=(string)$matched['code'];}elseif($affiliateEmail!=='')$storedAffiliateCode='';}
        $upsert=$pdo->prepare('INSERT INTO sales_orders(id,tenant_id,connection_id,external_order_id,affiliate_id,affiliate_code,customer_hash,amount_cents,currency,status,sold_at) VALUES(?,?,?,?,?,?,?,?,?,?,?) ON DUPLICATE KEY UPDATE affiliate_id=VALUES(affiliate_id),affiliate_code=VALUES(affiliate_code),customer_hash=COALESCE(VALUES(customer_hash),customer_hash),amount_cents=VALUES(amount_cents),status=VALUES(status),sold_at=VALUES(sold_at),updated_at=CURRENT_TIMESTAMP');
        $upsert->execute([new_id('sale'),$connection['tenant_id'],$connectionId,$externalId,$affiliateId,$storedAffiliateCode,$customerHash,(int)round((float)$amount*100),$currency,$status,$saleDate]);
        $pdo->prepare('INSERT INTO integration_events(id,tenant_id,connection_id,external_event_id,event_type,result,message) VALUES(?,?,?,?,?,?,?)')->execute([new_id('evt'),$connection['tenant_id'],$connectionId,$eventId,$eventType,'processed',($affiliateCode!==''||$affiliateEmail!==''||$hotmartAffiliateCode!=='')&&$affiliateId===null?'Pedido recebido sem correspondência de afiliado.':'']);
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
    $customer=is_array($payload['Customer']??null)?$payload['Customer']:(is_array($payload['customer']??null)?$payload['customer']:[]);
    $eventSuffix=$eventName!==''?$eventName:$orderStatus;$eventId=(string)($payload['webhook_event_id']??($orderId.':'.$eventSuffix));
    return integration_handle_webhook($connectionId,$secret,['event_id'=>$eventId,'type'=>$eventType,'order'=>['id'=>$orderId,'amount'=>(float)$amountCents/100,'currency'=>$currency,'affiliate_email'=>$affiliateEmail,'customer_email'=>(string)($customer['email']??''),'customer_id'=>(string)($customer['id']??''),'created_at'=>$payload['approved_date']??$payload['created_at']??gmdate('c')]]);
}

function hotmart_handle_webhook(string $connectionId,string $hottok,string $rawBody): array
{
    $stmt=app_db()->prepare("SELECT id,tenant_id,token_hash,status FROM integration_connections WHERE id=? AND platform='hotmart'");$stmt->execute([$connectionId]);$connection=$stmt->fetch();
    if(!$connection||$connection['status']!=='active'||$hottok===''||!hash_equals((string)$connection['token_hash'],hash('sha256',$hottok)))throw new DomainException('Autenticação Hotmart inválida.');
    $payload=json_decode($rawBody,true);if(!is_array($payload))throw new DomainException('O corpo da requisição não contém JSON válido.');
    $eventName=strtoupper(trim((string)($payload['event']??'')));$eventId=trim((string)($payload['id']??''));
    if($eventName===''||$eventId===''||strlen($eventId)>160)throw new DomainException('Evento Hotmart inválido: informe id e event.');
    $eventType=match($eventName){'PURCHASE_APPROVED'=>'sale.approved','PURCHASE_REFUNDED','PURCHASE_CHARGEBACK'=>'sale.refunded','PURCHASE_CANCELED'=>'sale.canceled',default=>''};
    if($eventType==='')return integration_record_ignored_event($connection,$payload);
    $data=$payload['data']??null;$purchase=is_array($data)?($data['purchase']??null):null;
    if(!is_array($purchase))throw new DomainException('Evento Hotmart sem dados de compra.');
    $transaction=trim((string)($purchase['transaction']??$purchase['id']??''));
    if($transaction===''||strlen($transaction)>160)throw new DomainException('Compra Hotmart sem identificador de transação válido.');
    $price=$purchase['full_price']??$purchase['price']??null;
    if(!is_array($price))$price=$purchase['price']??null;
    $amount=filter_var(is_array($price)?($price['value']??null):null,FILTER_VALIDATE_FLOAT);
    $currency=strtoupper((string)(is_array($price)?($price['currency_value']??'BRL'):'BRL'));
    if($amount===false||$amount===null||$amount<0||$amount>100000000)throw new DomainException('Compra Hotmart sem valor válido.');
    if($currency!=='BRL')return integration_record_ignored_event($connection,$payload,'Moeda '.$currency.' não contabilizada; o painel agrega valores em BRL.');
    $affiliates=is_array($data['affiliates']??null)?$data['affiliates']:[];$hotmartCode='';
    foreach($affiliates as $affiliate){if(is_array($affiliate)&&trim((string)($affiliate['affiliate_code']??''))!==''){$hotmartCode=trim((string)$affiliate['affiliate_code']);break;}}
    $buyer=is_array($data['buyer']??null)?$data['buyer']:[];
    if(strlen($hotmartCode)>100)throw new DomainException('Código de afiliado Hotmart muito longo.');
    $dateValue=$purchase['approved_date']??$purchase['order_date']??null;$saleDate=gmdate('Y-m-d H:i:s');
    if(is_numeric($dateValue)){$timestamp=(int)$dateValue;if($timestamp>9999999999)$timestamp=(int)floor($timestamp/1000);if($timestamp<0||$timestamp>time()+86400)throw new DomainException('Data da compra Hotmart inválida.');$saleDate=gmdate('Y-m-d H:i:s',$timestamp);}
    elseif(is_string($dateValue)&&$dateValue!==''){$timestamp=strtotime($dateValue);if($timestamp===false||$timestamp>time()+86400)throw new DomainException('Data da compra Hotmart inválida.');$saleDate=gmdate('Y-m-d H:i:s',$timestamp);}
    return integration_handle_webhook($connectionId,$hottok,['event_id'=>$eventId,'type'=>$eventType,'order'=>['id'=>$transaction,'amount'=>(float)$amount,'currency'=>$currency,'hotmart_affiliate_code'=>$hotmartCode,'customer_email'=>(string)($buyer['email']??''),'customer_id'=>(string)($buyer['id']??''),'created_at'=>$saleDate]]);
}

function eduzz_handle_webhook(string $connectionId,string $signature,string $rawBody): array
{
    $stmt=app_db()->prepare("SELECT id,tenant_id,token_hash,secret_ciphertext,status FROM integration_connections WHERE id=? AND platform='eduzz'");$stmt->execute([$connectionId]);$connection=$stmt->fetch();
    if(!$connection||$connection['status']!=='active')throw new DomainException('Conexão Eduzz não encontrada ou pausada.');
    $secret=integration_decrypt_secret((string)$connection['secret_ciphertext']);$signature=trim($signature);if(str_starts_with(strtolower($signature),'sha256='))$signature=substr($signature,7);
    if($signature===''||!hash_equals(hash_hmac('sha256',$rawBody,$secret),strtolower($signature)))throw new DomainException('Assinatura Eduzz inválida.');
    $payload=json_decode($rawBody,true);if(!is_array($payload))throw new DomainException('O corpo da requisição não contém JSON válido.');
    $eventName=strtolower(trim((string)($payload['event']??'')));$eventId=trim((string)($payload['id']??''));
    if($eventName===''||$eventId===''||strlen($eventId)>160)throw new DomainException('Evento Eduzz inválido: informe id e event.');
    $eventType=match($eventName){'myeduzz.invoice_paid','myeduzz.invoice_negotiated'=>'sale.approved','myeduzz.invoice_refunded','myeduzz.invoice_chargeback'=>'sale.refunded','myeduzz.invoice_canceled'=>'sale.canceled',default=>''};
    if($eventType==='')return integration_record_ignored_event($connection,$payload);
    $data=is_array($payload['data']??null)?$payload['data']:[];$invoiceId=trim((string)($data['id']??''));
    if($invoiceId===''||strlen($invoiceId)>160)throw new DomainException('Fatura Eduzz sem identificador válido.');
    $price=$data['paid']??$data['price']??[];$amount=filter_var(is_array($price)?($price['value']??null):null,FILTER_VALIDATE_FLOAT);$currency=strtoupper((string)(is_array($price)?($price['currency']??'BRL'):'BRL'));
    if($amount===false||$amount===null||$amount<0||$amount>100000000)throw new DomainException('Fatura Eduzz sem valor válido.');
    if($currency!=='BRL')return integration_record_ignored_event($connection,$payload,'Moeda '.$currency.' não contabilizada; o painel agrega valores em BRL.');
    $affiliate=is_array($data['affiliate']??null)?$data['affiliate']:[];$affiliateEmail=trim((string)($affiliate['email']??''));$customer=is_array($data['customer']??null)?$data['customer']:(is_array($data['client']??null)?$data['client']:[]);
    $dateValue=$data['paidAt']??$data['createdAt']??$payload['sentDate']??null;$saleDate=gmdate('Y-m-d H:i:s');
    if(is_string($dateValue)&&$dateValue!==''){$timestamp=strtotime($dateValue);if($timestamp===false||$timestamp>time()+86400)throw new DomainException('Data da fatura Eduzz inválida.');$saleDate=gmdate('Y-m-d H:i:s',$timestamp);}
    return integration_handle_webhook($connectionId,$secret,['event_id'=>$eventId,'type'=>$eventType,'order'=>['id'=>$invoiceId,'amount'=>(float)$amount,'currency'=>$currency,'affiliate_email'=>$affiliateEmail,'customer_email'=>(string)($customer['email']??''),'customer_id'=>(string)($customer['id']??''),'created_at'=>$saleDate]]);
}

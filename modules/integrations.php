<?php
declare(strict_types=1);
require_once __DIR__ . '/tenancy.php';
require_once __DIR__ . '/database.php';
require_once __DIR__ . '/rewards.php';

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
    if(!in_array($platform,['kiwify','hotmart','eduzz','applyfy'],true))throw new DomainException('Plataforma de vendas inválida.');
    $token=in_array($platform,['kiwify','applyfy'],true)?rtrim(strtr(base64_encode(random_bytes(32)),'+/','-_'),'='):trim($providedSecret);
    if($platform==='hotmart'&&($token===''||strlen($token)>140))throw new DomainException('Informe o Hottok da Hotmart (até 140 caracteres).');
    if($platform==='eduzz'&&($token===''||strlen($token)>255))throw new DomainException('Informe a chave de assinatura da Eduzz (até 255 caracteres).');
    $pdo=app_db();$id=new_id('conn');
    try{$pdo->prepare('INSERT INTO integration_connections(id,tenant_id,name,platform,token_hash,secret_ciphertext) VALUES(?,?,?,?,?,?)')->execute([$id,tenant_id(),$name,$platform,hash('sha256',$token),integration_encrypt_secret($token)]);}catch(PDOException $e){if(str_contains(strtolower($e->getMessage()),'unique'))throw new DomainException('Já existe uma conexão com esse nome neste espaço.');throw $e;}
    return ['id'=>$id,'name'=>$name,'platform'=>$platform,'token'=>in_array($platform,['kiwify','applyfy'],true)?$token:''];
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
    $stmt=app_db()->prepare('SELECT c.id,c.name,c.platform,c.status,c.last_event_at,c.created_at,COUNT(DISTINCT e.id) AS event_count,COUNT(DISTINCT o.id) AS order_count,COUNT(DISTINCT p.id) AS product_count,COUNT(DISTINCT l.id) AS affiliate_link_count FROM integration_connections c LEFT JOIN integration_events e ON e.connection_id=c.id LEFT JOIN sales_orders o ON o.connection_id=c.id LEFT JOIN integration_products p ON p.connection_id=c.id LEFT JOIN integration_affiliate_links l ON l.connection_id=c.id WHERE c.tenant_id=? GROUP BY c.id ORDER BY c.created_at DESC');
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
    if(!in_array($eventType,['sale.pending','sale.approved','sale.refunded','sale.canceled'],true))throw new DomainException('Tipo de evento não suportado.');
    $externalId=trim((string)($order['id']??''));$productId=trim((string)($order['product_id']??''));$productName=trim((string)($order['product_name']??''));$currency=strtoupper((string)($order['currency']??'BRL'));$amount=filter_var($order['amount']??null,FILTER_VALIDATE_FLOAT);
    if($externalId===''||strlen($externalId)>160||$amount===false||$amount<0||$amount>100000000||$currency!=='BRL')throw new DomainException('Pedido inválido: informe ID da venda e valor em BRL.');
    if($productId===''||strlen($productId)>120)return integration_record_ignored_event($connection,['id'=>$eventId,'event'=>$eventType,'order_id'=>$externalId],'Evento sem ID oficial de produto; não foi importado.');
    $affiliateCode=trim((string)($order['affiliate_code']??''));$affiliateEmail=strtolower(trim((string)($order['affiliate_email']??'')));$externalAffiliateId=trim((string)($order['external_affiliate_id']??''));$hotmartAffiliateCode=trim((string)($order['hotmart_affiliate_code']??''));
    if(strlen($affiliateCode)>100||strlen($affiliateEmail)>190||strlen($externalAffiliateId)>120||strlen($hotmartAffiliateCode)>100||strlen($productName)>160)throw new DomainException('Identificador comercial muito longo.');
    $eventProduct=$pdo->prepare("SELECT id,name FROM integration_products WHERE tenant_id=? AND connection_id=? AND external_product_id=? AND status='active' LIMIT 1");
    $eventProduct->execute([$connection['tenant_id'],$connectionId,$productId]);$authorizedProduct=$eventProduct->fetch();
    // Continue to accept terminal status changes for a sale that was imported
    // while the product was authorized, even if the producer later pauses it.
    $priorCommissionCents=null;
    if(!$authorizedProduct&&in_array($eventType,['sale.refunded','sale.canceled'],true)){
        $priorSale=$pdo->prepare('SELECT id,product_name,affiliate_id,affiliate_code,external_affiliate_id,commission_cents FROM sales_orders WHERE tenant_id=? AND connection_id=? AND external_order_id=? AND external_product_id=? LIMIT 1');
        $priorSale->execute([$connection['tenant_id'],$connectionId,$externalId,$productId]);$authorizedProduct=$priorSale->fetch();
        if($authorizedProduct){$affiliateId=(string)$authorizedProduct['affiliate_id'];$storedAffiliateCode=(string)$authorizedProduct['affiliate_code'];$externalAffiliateId=(string)$authorizedProduct['external_affiliate_id'];$priorCommissionCents=$authorizedProduct['commission_cents']!==null?(int)$authorizedProduct['commission_cents']:null;}
    }
    if(!$authorizedProduct)return integration_record_ignored_event($connection,['id'=>$eventId,'event'=>$eventType,'order_id'=>$externalId],'Produto não autorizado nesta conexão; o pedido não foi importado.');
    $affiliateId=$affiliateId??null;$storedAffiliateCode=$storedAffiliateCode??'';$affiliateCommission=0.0;
    if($affiliateId===null&&($externalAffiliateId!==''||$affiliateEmail!=='')){
        $link=$pdo->prepare("SELECT a.id,a.code,a.commission FROM integration_affiliate_links l JOIN affiliates a ON a.tenant_id=l.tenant_id AND a.id=l.affiliate_id WHERE l.tenant_id=? AND l.connection_id=? AND ((?<>'' AND l.external_affiliate_id=?) OR (?<>'' AND LOWER(l.external_email)=?)) AND l.status='active' AND a.status='active' LIMIT 1");
        $link->execute([$connection['tenant_id'],$connectionId,$externalAffiliateId,$externalAffiliateId,$affiliateEmail,$affiliateEmail]);$matched=$link->fetch();
        if($matched){$affiliateId=(string)$matched['id'];$storedAffiliateCode=(string)$matched['code'];$affiliateCommission=(float)$matched['commission'];}
    }
    if($affiliateId===null&&($affiliateCode!==''||$hotmartAffiliateCode!=='')){
        // Source affiliate codes are usable only when the producer has already
        // registered that exact code on an AFFILIEY affiliate profile.
        $affiliate=$pdo->prepare("SELECT id,code,commission FROM affiliates WHERE tenant_id=? AND status='active' AND ((?<>'' AND LOWER(code)=LOWER(?)) OR (?<>'' AND LOWER(hotmart_code)=LOWER(?))) LIMIT 1");
        $affiliate->execute([$connection['tenant_id'],$affiliateCode,$affiliateCode,$hotmartAffiliateCode,$hotmartAffiliateCode]);$matched=$affiliate->fetch();
        if($matched){$affiliateId=(string)$matched['id'];$storedAffiliateCode=(string)$matched['code'];$affiliateCommission=(float)$matched['commission'];$externalAffiliateId=$externalAffiliateId!==''?$externalAffiliateId:($hotmartAffiliateCode!==''?$hotmartAffiliateCode:$affiliateCode);}
    }
    if($affiliateId===null)return integration_record_ignored_event($connection,['id'=>$eventId,'event'=>$eventType,'order_id'=>$externalId],'Afiliado sem vínculo ativo e correspondência exata; o pedido não foi importado.');
    $customerHash=integration_customer_hash((string)$connection['tenant_id'],$connectionId,(string)($order['customer_email']??''),(string)($order['customer_id']??''));
    $saleDate=(string)($order['created_at']??gmdate('Y-m-d\\TH:i:s\\Z'));$timestamp=strtotime($saleDate);if($timestamp===false||$timestamp>time()+86400)throw new DomainException('Data da venda inválida.');$saleDate=gmdate('Y-m-d H:i:s',$timestamp);
    $status=match($eventType){'sale.pending'=>'pending','sale.approved'=>'approved','sale.refunded'=>'refunded','sale.canceled'=>'canceled'};
    $externalCommission=filter_var($order['commission_amount']??null,FILTER_VALIDATE_FLOAT);
    if($externalCommission!==false&&$externalCommission!==null&&($externalCommission<0||$externalCommission>100000000))throw new DomainException('Comissão informada pela plataforma inválida.');
    if($priorCommissionCents===null&&($externalCommission===false||$externalCommission===null)){
        $existingCommission=$pdo->prepare('SELECT commission_cents FROM sales_orders WHERE tenant_id=? AND connection_id=? AND external_order_id=? AND external_product_id=? LIMIT 1');
        $existingCommission->execute([$connection['tenant_id'],$connectionId,$externalId,$productId]);$value=$existingCommission->fetchColumn();
        if($value!==false&&$value!==null)$priorCommissionCents=(int)$value;
    }
    $commissionAmount=$externalCommission!==false&&$externalCommission!==null?(float)$externalCommission:($priorCommissionCents!==null?$priorCommissionCents/100:(float)$amount*$affiliateCommission/100);
    $pdo->beginTransaction();
    try{
        $duplicate=$pdo->prepare('SELECT id,result FROM integration_events WHERE connection_id=? AND external_event_id=? FOR UPDATE');$duplicate->execute([$connectionId,$eventId]);$priorEvent=$duplicate->fetch();
        if($priorEvent&&$priorEvent['result']==='processed'){$pdo->commit();return ['duplicate'=>true,'message'=>'Evento já processado.'];}
        $upsert=$pdo->prepare('INSERT INTO sales_orders(id,tenant_id,connection_id,external_order_id,external_product_id,product_name,external_affiliate_id,affiliate_id,affiliate_code,customer_hash,amount_cents,commission_cents,currency,status,sold_at) VALUES(?,?,?,?,?,?,?,?,?,?,?,?,?,?,?) ON DUPLICATE KEY UPDATE external_product_id=VALUES(external_product_id),product_name=VALUES(product_name),external_affiliate_id=VALUES(external_affiliate_id),affiliate_id=VALUES(affiliate_id),affiliate_code=VALUES(affiliate_code),customer_hash=COALESCE(VALUES(customer_hash),customer_hash),amount_cents=VALUES(amount_cents),commission_cents=VALUES(commission_cents),status=VALUES(status),sold_at=VALUES(sold_at),updated_at=CURRENT_TIMESTAMP');
        $upsert->execute([new_id('sale'),$connection['tenant_id'],$connectionId,$externalId,$productId,$productName!==''?$productName:$authorizedProduct['name'],$externalAffiliateId,$affiliateId,$storedAffiliateCode,$customerHash,(int)round((float)$amount*100),(int)round($commissionAmount*100),$currency,$status,$saleDate]);
        if($priorEvent){$pdo->prepare("UPDATE integration_events SET event_type=?,result='processed',message=? WHERE id=? AND tenant_id=?")->execute([$eventType,'Evento reprocessado após validar produto e afiliado autorizados.',$priorEvent['id'],$connection['tenant_id']]);}
        else $pdo->prepare('INSERT INTO integration_events(id,tenant_id,connection_id,external_event_id,event_type,result,message) VALUES(?,?,?,?,?,?,?)')->execute([new_id('evt'),$connection['tenant_id'],$connectionId,$eventId,$eventType,'processed','Venda vinculada ao produto autorizado e ao afiliado correspondente.']);
        $pdo->prepare('UPDATE integration_connections SET last_event_at=CURRENT_TIMESTAMP WHERE id=?')->execute([$connectionId]);
        $refresh=$pdo->prepare("UPDATE affiliates SET sales=COALESCE((SELECT SUM(amount_cents)/100.0 FROM sales_orders WHERE affiliate_id=affiliates.id AND tenant_id=affiliates.tenant_id AND status='approved'),0),orders=(SELECT COUNT(*) FROM sales_orders WHERE affiliate_id=affiliates.id AND tenant_id=affiliates.tenant_id AND status='approved') WHERE tenant_id=? AND id=?");$refresh->execute([$connection['tenant_id'],$affiliateId]);
        if ($eventType==='sale.approved') reward_evaluate_rules($affiliateId,(string)$connection['tenant_id']);
        $pdo->commit();return ['duplicate'=>false,'message'=>'Evento recebido e vinculado ao produto autorizado.'];
    }catch(Throwable $e){if($pdo->inTransaction())$pdo->rollBack();throw $e;}
}

function integration_record_webhook_failure(string $connectionId, string $rawBody, string $message): void
{
    try {
        $pdo = app_db();
        $stmt = $pdo->prepare('SELECT id,tenant_id FROM integration_connections WHERE id=?');
        $stmt->execute([$connectionId]);
        $connection = $stmt->fetch();
        if (!$connection) return;
        $payload = json_decode($rawBody, true);
        $payload = is_array($payload) ? $payload : [];
        $externalId = trim((string)($payload['id'] ?? $payload['webhook_event_id'] ?? ''));
        if ($externalId === '' || strlen($externalId) > 160) $externalId = 'failed:' . hash('sha256', $rawBody);
        $eventType = trim((string)($payload['event'] ?? $payload['webhook_event_type'] ?? 'unknown'));
        $eventType = substr($eventType !== '' ? $eventType : 'unknown', 0, 80);
        $stmt = $pdo->prepare("INSERT IGNORE INTO integration_events(id,tenant_id,connection_id,external_event_id,event_type,result,message) VALUES(?,?,?,?,?,'failed',?)");
        $stmt->execute([new_id('evt'), $connection['tenant_id'], $connectionId, $externalId, $eventType, substr($message, 0, 500)]);
    } catch (Throwable $ignored) {
        // A failure to write the diagnostic must not mask the webhook response.
    }
}


function integration_product_list(string $connectionId): array
{
    $stmt = app_db()->prepare('SELECT id,external_product_id,name,status,created_at FROM integration_products WHERE tenant_id=? AND connection_id=? ORDER BY name');
    $stmt->execute([tenant_id(), $connectionId]);
    return $stmt->fetchAll();
}

function integration_product_save(string $connectionId, string $externalId, string $name): void
{
    $externalId = trim($externalId);
    $name = trim($name);
    if ($externalId === '' || strlen($externalId) > 120 || $name === '' || strlen($name) > 160) {
        throw new DomainException('Informe o ID externo do produto e um nome (até 160 caracteres).');
    }
    $pdo = app_db();
    $connection = $pdo->prepare('SELECT id FROM integration_connections WHERE id=? AND tenant_id=?');
    $connection->execute([$connectionId, tenant_id()]);
    if (!$connection->fetchColumn()) throw new DomainException('Conexão não encontrada neste espaço.');
    $existing = $pdo->prepare('SELECT id FROM integration_products WHERE connection_id=? AND external_product_id=?');
    $existing->execute([$connectionId, $externalId]);
    $id = $existing->fetchColumn();
    if ($id) {
        $pdo->prepare("UPDATE integration_products SET name=?,status='active' WHERE id=? AND tenant_id=?")->execute([$name, $id, tenant_id()]);
        return;
    }
    $pdo->prepare("INSERT INTO integration_products(id,tenant_id,connection_id,external_product_id,name,status) VALUES(?,?,?,?,?,'active')")
        ->execute([new_id('prod'), tenant_id(), $connectionId, $externalId, $name]);
}

function integration_product_toggle(string $productId): void
{
    $stmt = app_db()->prepare("UPDATE integration_products SET status=CASE status WHEN 'active' THEN 'paused' ELSE 'active' END WHERE id=? AND tenant_id=?");
    $stmt->execute([$productId, tenant_id()]);
    if ($stmt->rowCount() !== 1) throw new DomainException('Produto autorizado não encontrado neste espaço.');
}

function integration_affiliate_link_list(string $connectionId): array
{
    $stmt = app_db()->prepare('SELECT l.id,l.external_affiliate_id,l.external_email,l.status,a.id AS affiliate_id,a.name AS affiliate_name,a.email AS affiliate_email FROM integration_affiliate_links l JOIN affiliates a ON a.tenant_id=l.tenant_id AND a.id=l.affiliate_id WHERE l.tenant_id=? AND l.connection_id=? ORDER BY a.name');
    $stmt->execute([tenant_id(), $connectionId]);
    return $stmt->fetchAll();
}

function integration_affiliate_link_save(string $connectionId, string $affiliateId, string $externalAffiliateId, string $externalEmail): void
{
    $externalAffiliateId = trim($externalAffiliateId);
    $externalEmail = strtolower(trim($externalEmail));
    if ($externalAffiliateId === '' || strlen($externalAffiliateId) > 120) throw new DomainException('Informe o ID/código do afiliado na plataforma de vendas.');
    if ($externalEmail !== '' && (strlen($externalEmail) > 190 || filter_var($externalEmail, FILTER_VALIDATE_EMAIL) === false)) throw new DomainException('O e-mail externo do afiliado é inválido.');
    $pdo = app_db();
    $connection = $pdo->prepare('SELECT id FROM integration_connections WHERE id=? AND tenant_id=?');
    $connection->execute([$connectionId, tenant_id()]);
    if (!$connection->fetchColumn()) throw new DomainException('Conexão não encontrada neste espaço.');
    $affiliate = $pdo->prepare("SELECT id FROM affiliates WHERE id=? AND tenant_id=? AND status='active'");
    $affiliate->execute([$affiliateId, tenant_id()]);
    if (!$affiliate->fetchColumn()) throw new DomainException('Selecione um afiliado ativo deste espaço.');
    $existing = $pdo->prepare('SELECT id FROM integration_affiliate_links WHERE connection_id=? AND external_affiliate_id=?');
    $existing->execute([$connectionId, $externalAffiliateId]);
    $existingId = $existing->fetchColumn();
    if ($existingId) {
        $pdo->prepare("UPDATE integration_affiliate_links SET affiliate_id=?,external_email=?,status='active' WHERE id=? AND tenant_id=?")
            ->execute([$affiliateId, $externalEmail, $existingId, tenant_id()]);
        return;
    }
    $pdo->prepare("INSERT INTO integration_affiliate_links(id,tenant_id,connection_id,affiliate_id,external_affiliate_id,external_email,status) VALUES(?,?,?,?,?,?, 'active')")
        ->execute([new_id('alink'), tenant_id(), $connectionId, $affiliateId, $externalAffiliateId, $externalEmail]);
}

function integration_affiliate_link_toggle(string $linkId): void
{
    $stmt = app_db()->prepare("UPDATE integration_affiliate_links SET status=CASE status WHEN 'active' THEN 'paused' ELSE 'active' END WHERE id=? AND tenant_id=?");
    $stmt->execute([$linkId, tenant_id()]);
    if ($stmt->rowCount() !== 1) throw new DomainException('Vínculo de afiliado não encontrado neste espaço.');
}

require_once __DIR__ . '/integrations/kiwify.php';
require_once __DIR__ . '/integrations/hotmart.php';
require_once __DIR__ . '/integrations/eduzz.php';
require_once __DIR__ . '/integrations/applyfy.php';

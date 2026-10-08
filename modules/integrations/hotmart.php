<?php
declare(strict_types=1);

function hotmart_handle_webhook(string $connectionId,string $hottok,string $rawBody): array
{
    $stmt=app_db()->prepare("SELECT id,tenant_id,token_hash,status FROM integration_connections WHERE id=? AND platform='hotmart'");$stmt->execute([$connectionId]);$connection=$stmt->fetch();
    if(!$connection||$connection['status']!=='active'||$hottok===''||!hash_equals((string)$connection['token_hash'],hash('sha256',$hottok)))throw new DomainException('Autenticação Hotmart inválida.');
    $payload=json_decode($rawBody,true);if(!is_array($payload))throw new DomainException('O corpo da requisição não contém JSON válido.');
    $eventName=strtoupper(trim((string)($payload['event']??'')));$eventId=trim((string)($payload['id']??''));
    if($eventName===''||$eventId===''||strlen($eventId)>160)throw new DomainException('Evento Hotmart inválido: informe id e event.');
    $eventType=match($eventName){'PURCHASE_APPROVED','PURCHASE_COMPLETE'=>'sale.approved','PURCHASE_REFUNDED','PURCHASE_CHARGEBACK'=>'sale.refunded','PURCHASE_CANCELED'=>'sale.canceled','PURCHASE_BILLET_PRINTED','PURCHASE_DELAYED','PURCHASE_STARTED'=>'sale.pending',default=>''};
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
    $product=is_array($data['product']??null)?$data['product']:[];$affiliateCommission=null;
    foreach((is_array($data['commissions']??null)?$data['commissions']:[]) as $commission){if(is_array($commission)&&strtoupper((string)($commission['source']??''))==='AFFILIATE'&&strtoupper((string)($commission['currency_value']??'BRL'))==='BRL'){$affiliateCommission=filter_var($commission['value']??null,FILTER_VALIDATE_FLOAT);if($affiliateCommission===false)$affiliateCommission=null;break;}}
    return integration_handle_webhook($connectionId,$hottok,['event_id'=>$eventId,'type'=>$eventType,'order'=>['id'=>$transaction,'product_id'=>(string)($product['id']??''),'product_name'=>(string)($product['name']??''),'amount'=>(float)$amount,'commission_amount'=>$affiliateCommission,'currency'=>$currency,'hotmart_affiliate_code'=>$hotmartCode,'external_affiliate_id'=>$hotmartCode,'customer_email'=>(string)($buyer['email']??''),'customer_id'=>(string)($buyer['ucode']??$buyer['id']??''),'created_at'=>$saleDate]]);
}

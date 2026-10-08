<?php
declare(strict_types=1);

function eduzz_handle_webhook(string $connectionId,string $rawBody): array
{
    $stmt=app_db()->prepare("SELECT id,tenant_id,token_hash,secret_ciphertext,status FROM integration_connections WHERE id=? AND platform='eduzz'");$stmt->execute([$connectionId]);$connection=$stmt->fetch();
    if(!$connection||$connection['status']!=='active')throw new DomainException('Conexão Eduzz não encontrada ou pausada.');
    $secret=integration_decrypt_secret((string)$connection['secret_ciphertext']);
    $payload=json_decode($rawBody,true);if(!is_array($payload))throw new DomainException('O corpo da requisição não contém JSON válido.');
    $data=is_array($payload['data']??null)?$payload['data']:[];$producer=is_array($data['producer']??null)?$data['producer']:[];
    $originSecret=(string)($producer['originSecret']??'');
    if($originSecret===''||!hash_equals($secret,$originSecret))throw new DomainException('Autenticação do webhook Eduzz inválida.');
    $eventName=strtolower(trim((string)($payload['event']??'')));$eventId=trim((string)($payload['id']??''));
    if($eventName===''||$eventId===''||strlen($eventId)>160)throw new DomainException('Evento Eduzz inválido: informe id e event.');
    $eventType=match($eventName){'myeduzz.invoice_paid','myeduzz.invoice_negotiated'=>'sale.approved','myeduzz.invoice_refunded','myeduzz.invoice_chargeback'=>'sale.refunded','myeduzz.invoice_canceled'=>'sale.canceled','myeduzz.invoice_waiting_payment'=>'sale.pending',default=>''};
    if($eventType==='')return integration_record_ignored_event($connection,$payload);
    $invoiceId=trim((string)($data['id']??''));
    if($invoiceId===''||strlen($invoiceId)>160)throw new DomainException('Fatura Eduzz sem identificador válido.');
    $price=$data['paid']??$data['price']??[];$amount=filter_var(is_array($price)?($price['value']??null):null,FILTER_VALIDATE_FLOAT);$currency=strtoupper((string)(is_array($price)?($price['currency']??'BRL'):'BRL'));
    if($amount===false||$amount===null||$amount<0||$amount>100000000)throw new DomainException('Fatura Eduzz sem valor válido.');
    if($currency!=='BRL')return integration_record_ignored_event($connection,$payload,'Moeda '.$currency.' não contabilizada; o painel agrega valores em BRL.');
    $items=is_array($data['items']??null)?$data['items']:[];$itemProductIds=[];$itemProductName='';foreach($items as $item){if(!is_array($item))continue;$id=trim((string)($item['productId']??''));if($id!=='')$itemProductIds[$id]=true;if($itemProductName===''&&isset($item['name']))$itemProductName=(string)$item['name'];}
    if(count($itemProductIds)!==1)return integration_record_ignored_event($connection,$payload,'Fatura com múltiplos produtos ou sem ID único; não importada para evitar misturar produtos não autorizados.');
    $productId=(string)array_key_first($itemProductIds);
    $affiliate=is_array($data['affiliate']??null)?$data['affiliate']:[];$affiliateEmail=trim((string)($affiliate['email']??''));$externalAffiliateId=trim((string)($affiliate['id']??''));$customer=is_array($data['buyer']??null)?$data['buyer']:(is_array($data['customer']??null)?$data['customer']:(is_array($data['client']??null)?$data['client']:[]));
    $gains=is_array($data['gains']??null)?$data['gains']:[];$affiliateGain=is_array($gains['affiliate']??null)?$gains['affiliate']:[];$affiliateCommission=strtoupper((string)($affiliateGain['currency']??'BRL'))==='BRL'?filter_var($affiliateGain['value']??null,FILTER_VALIDATE_FLOAT):null;if($affiliateCommission===false)$affiliateCommission=null;
    $dateValue=$data['paidAt']??$data['createdAt']??$payload['sentDate']??null;$saleDate=gmdate('Y-m-d H:i:s');
    if(is_string($dateValue)&&$dateValue!==''){$timestamp=strtotime($dateValue);if($timestamp===false||$timestamp>time()+86400)throw new DomainException('Data da fatura Eduzz inválida.');$saleDate=gmdate('Y-m-d H:i:s',$timestamp);}
    return integration_handle_webhook($connectionId,$secret,['event_id'=>$eventId,'type'=>$eventType,'order'=>['id'=>$invoiceId,'product_id'=>$productId,'product_name'=>$itemProductName,'amount'=>(float)$amount,'commission_amount'=>$affiliateCommission,'currency'=>$currency,'affiliate_email'=>$affiliateEmail,'external_affiliate_id'=>$externalAffiliateId,'customer_email'=>(string)($customer['email']??''),'customer_id'=>(string)($customer['id']??''),'created_at'=>$saleDate]]);
}

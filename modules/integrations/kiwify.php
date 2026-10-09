<?php
declare(strict_types=1);

function kiwify_handle_webhook(string $connectionId,string $signature,string $rawBody): array
{
    $pdo=app_db();$stmt=$pdo->prepare("SELECT id,tenant_id,token_hash,secret_ciphertext,status FROM integration_connections WHERE id=? AND platform='kiwify'");$stmt->execute([$connectionId]);$conn=$stmt->fetch();if(!$conn||$conn['status']!=='active')throw new DomainException('Conexão Kiwify não encontrada ou pausada.');
    $signature=trim($signature);if(str_starts_with(strtolower($signature),'sha1='))$signature=substr($signature,5);$secret=integration_decrypt_secret((string)$conn['secret_ciphertext'],(string)$conn['tenant_id'].':'.$conn['id']);
    $valid=hash_equals(hash_hmac('sha1',$rawBody,$secret),strtolower($signature));
    $payload=json_decode($rawBody,true);
    if(!$valid&&is_array($payload)){$canonical=json_encode($payload,JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE);if(is_string($canonical)){$hex=hash_hmac('sha1',$canonical,$secret);$valid=hash_equals($hex,$signature)||hash_equals(base64_encode(hex2bin($hex)),$signature);}}
    if(!$valid)throw new DomainException('Assinatura Kiwify inválida.');if(!is_array($payload))throw new DomainException('O corpo da requisição não contém JSON válido.');
    $eventName=(string)($payload['webhook_event_type']??'');$orderStatus=(string)($payload['order_status']??'');
    $eventType=match($eventName){'order_approved','subscription_renewed'=>'sale.approved','order_refunded'=>'sale.refunded','order_chargeback','order_chargedback','chargeback'=>'sale.refunded','order_canceled','subscription_canceled'=>'sale.canceled','order_waiting_payment','order_pending'=>'sale.pending',default=>match($orderStatus){'paid'=>'sale.approved','refunded','chargedback'=>'sale.refunded','canceled','cancelled'=>'sale.canceled','waiting_payment','pending'=>'sale.pending',default=>''}};
    if($eventType==='')return integration_record_ignored_event($conn,$payload);
    $orderId=trim((string)($payload['order_id']??''));$amountCents=filter_var($payload['Commissions']['charge_amount']??null,FILTER_VALIDATE_INT);$currency=strtoupper((string)($payload['Commissions']['currency']??''));if($amountCents===false||$amountCents===null)throw new DomainException('Evento Kiwify sem o valor Commissions.charge_amount em centavos.');
    $affiliateEmail='';$externalAffiliateId='';$commissionAmount=null;foreach(($payload['Commissions']['commissioned_stores']??[]) as $store){if(is_array($store)&&strtolower((string)($store['type']??''))==='affiliate'){$affiliateEmail=(string)($store['email']??'');$externalAffiliateId=(string)($store['id']??$store['code']??'');$commissionAmount=isset($store['commission_amount'])?(float)$store['commission_amount']/100:null;break;}}
    $customer=is_array($payload['Customer']??null)?$payload['Customer']:(is_array($payload['customer']??null)?$payload['customer']:[]);
    $eventSuffix=$eventName!==''?$eventName:$orderStatus;$eventId=(string)($payload['webhook_event_id']??($orderId.':'.$eventSuffix));
    $product=is_array($payload['Product']??null)?$payload['Product']:(is_array($payload['product']??null)?$payload['product']:[]);
    $productId=(string)($product['product_id']??$product['id']??$payload['product_id']??'');
    return integration_handle_webhook($connectionId,$secret,['event_id'=>$eventId,'type'=>$eventType,'order'=>['id'=>$orderId,'product_id'=>$productId,'product_name'=>(string)($product['product_name']??$product['name']??''),'amount'=>(float)$amountCents/100,'commission_amount'=>$commissionAmount,'currency'=>$currency,'affiliate_email'=>$affiliateEmail,'external_affiliate_id'=>$externalAffiliateId,'customer_email'=>(string)($customer['email']??''),'customer_id'=>(string)($customer['id']??''),'created_at'=>$payload['approved_date']??$payload['created_at']??gmdate('c')]]);
}

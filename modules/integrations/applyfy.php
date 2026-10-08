<?php
declare(strict_types=1);

/** Applyfy webhook adapter. Unknown event/payload shapes fail closed. */
function applyfy_handle_webhook(string $connectionId, string $token, string $rawBody): array
{
    $pdo = app_db();
    $stmt = $pdo->prepare("SELECT id,tenant_id,token_hash,status FROM integration_connections WHERE id=? AND platform='applyfy'");
    $stmt->execute([$connectionId]);
    $connection = $stmt->fetch();
    if (!$connection || $connection['status'] !== 'active' || $token === '' || !hash_equals((string)$connection['token_hash'], hash('sha256', $token))) {
        throw new DomainException('Autenticação do webhook Applyfy inválida.');
    }

    $payload = json_decode($rawBody, true);
    if (!is_array($payload)) throw new DomainException('O corpo do webhook Applyfy não contém JSON válido.');
    $eventData = is_array($payload['data'] ?? null) ? $payload['data'] : [];
    $eventName = trim((string)($payload['event'] ?? $payload['event_type'] ?? $payload['type'] ?? $payload['status'] ?? ($eventData['status'] ?? '')));
    $eventId = trim((string)($payload['event_id'] ?? $payload['webhook_event_id'] ?? $payload['id'] ?? ''));
    if ($eventId === '' || strlen($eventId) > 160) throw new DomainException('Evento Applyfy inválido: informe um ID de evento.');

    $normalizedEvent = strtolower(str_replace([' ', '-'], '_', $eventName));
    $eventType = match ($normalizedEvent) {
        'purchase_approved','sale_approved','order_approved','payment_approved','purchase_paid','order_paid','invoice_paid','approved','paid' => 'sale.approved',
        'purchase_pending','sale_pending','order_pending','payment_pending','pending' => 'sale.pending',
        'purchase_refunded','sale_refunded','order_refunded','payment_refunded','refunded','chargeback','purchase_chargeback','order_chargeback' => 'sale.refunded',
        'purchase_canceled','sale_canceled','order_canceled','payment_canceled','canceled','cancelled' => 'sale.canceled',
        default => match (strtolower(trim((string)($payload['status'] ?? ($payload['data']['status'] ?? ''))))) {
            'approved','paid','completed' => 'sale.approved',
            'pending','waiting_payment' => 'sale.pending',
            'refunded','chargeback' => 'sale.refunded',
            'canceled','cancelled' => 'sale.canceled',
            default => '',
        },
    };
    if ($eventType === '') return integration_record_ignored_event($connection, ['id'=>$eventId,'event'=>$eventName]);

    $data = $eventData ?: $payload;
    $order = is_array($data['order'] ?? null) ? $data['order'] : (is_array($data['purchase'] ?? null) ? $data['purchase'] : (is_array($data['invoice'] ?? null) ? $data['invoice'] : $data));
    $product = is_array($order['product'] ?? null) ? $order['product'] : (is_array($data['product'] ?? null) ? $data['product'] : []);
    $items = $order['items'] ?? $data['items'] ?? null;
    if (is_array($items) && $product === []) {
        $itemProducts = [];
        foreach ($items as $item) {
            if (!is_array($item)) continue;
            $itemId = trim((string)($item['product_id'] ?? $item['productId'] ?? $item['id'] ?? ''));
            if ($itemId !== '') $itemProducts[$itemId] = $item;
        }
        if (count($itemProducts) === 1) $product = reset($itemProducts);
        elseif (count($itemProducts) > 1) return integration_record_ignored_event($connection, ['id'=>$eventId,'event'=>$eventName], 'Webhook com vários produtos sem uma venda separada por item; ignorado para não misturar produtos.');
    }
    $affiliate = is_array($order['affiliate'] ?? null) ? $order['affiliate'] : (is_array($data['affiliate'] ?? null) ? $data['affiliate'] : []);
    $customer = is_array($order['customer'] ?? null) ? $order['customer'] : (is_array($order['buyer'] ?? null) ? $order['buyer'] : (is_array($data['customer'] ?? null) ? $data['customer'] : (is_array($data['buyer'] ?? null) ? $data['buyer'] : [])));
    $price = $order['amount'] ?? $order['total'] ?? $order['price'] ?? $order['value'] ?? null;
    if (is_array($price)) $price = $price['value'] ?? null;
    $amount = filter_var($price, FILTER_VALIDATE_FLOAT);
    $currency = strtoupper((string)($order['currency'] ?? $order['currency_code'] ?? 'BRL'));
    $externalOrderId = trim((string)($order['transaction_id'] ?? $order['order_id'] ?? $order['invoice_id'] ?? $order['transaction'] ?? $order['id'] ?? ''));
    $productId = trim((string)($product['product_id'] ?? $product['productId'] ?? $product['id'] ?? $order['product_id'] ?? $order['productId'] ?? $data['product_id'] ?? $data['productId'] ?? ''));
    if ($amount === false || $amount === null || $amount < 0 || $amount > 100000000) throw new DomainException('Evento Applyfy sem valor de venda reconhecido.');
    if ($currency !== 'BRL') return integration_record_ignored_event($connection, ['id'=>$eventId,'event'=>$eventName], 'Moeda '.$currency.' não contabilizada; o painel agrega valores em BRL.');

    return integration_handle_webhook($connectionId, $token, [
        'event_id'=>$eventId,
        'type'=>$eventType,
        'order'=>[
            'id'=>$externalOrderId,
            'product_id'=>$productId,
            'product_name'=>(string)($product['name'] ?? $product['product_name'] ?? $order['product_name'] ?? ''),
            'amount'=>(float)$amount,
            'commission_amount'=>$order['affiliate_commission'] ?? $order['commission'] ?? null,
            'currency'=>$currency,
            'external_affiliate_id'=>(string)($affiliate['id'] ?? $affiliate['code'] ?? $order['affiliate_id'] ?? $order['affiliate_code'] ?? ''),
            'affiliate_code'=>(string)($affiliate['code'] ?? $order['affiliate_code'] ?? ''),
            'affiliate_email'=>(string)($affiliate['email'] ?? ''),
            'customer_email'=>(string)($customer['email'] ?? ''),
            'customer_id'=>(string)($customer['id'] ?? ''),
            'created_at'=>$order['approved_at'] ?? $order['created_at'] ?? $data['created_at'] ?? gmdate('c'),
        ],
    ]);
}

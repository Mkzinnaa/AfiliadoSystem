<?php
declare(strict_types=1);
require_once __DIR__ . '/bootstrap.php';
api_method('GET');
api_require_auth();
$limit = max(1, min(100, (int)($_GET['limit'] ?? 25)));
$offset = max(0, min(100000, (int)($_GET['offset'] ?? 0)));
$status = trim((string)($_GET['status'] ?? ''));
if ($status !== '' && !in_array($status, ['approved','refunded','chargeback','cancelled','pending'], true)) api_fail('Filtro de status inválido.', 422, 'invalid_status');
$where = ['s.tenant_id=?'];
$params = [tenant_id()];
if ($status !== '') { $where[] = 's.status=?'; $params[] = $status; }
$pdo = app_db();
$count = $pdo->prepare('SELECT COUNT(*) FROM sales_orders s WHERE ' . implode(' AND ', $where));
$count->execute($params);
$query = $pdo->prepare('SELECT s.id,s.external_order_id,s.external_product_id,s.product_name,s.external_affiliate_id,s.amount_cents,s.commission_cents,s.currency,s.status,s.sold_at,s.affiliate_id,s.affiliate_code,a.name AS affiliate_name FROM sales_orders s LEFT JOIN affiliates a ON a.tenant_id=s.tenant_id AND a.id=s.affiliate_id WHERE ' . implode(' AND ', $where) . ' ORDER BY s.sold_at DESC LIMIT ' . $limit . ' OFFSET ' . $offset);
$query->execute($params);
$rows = array_map(static function (array $row): array { $row['amount'] = (int)$row['amount_cents'] / 100; $row['commission'] = $row['commission_cents'] === null ? null : (int)$row['commission_cents'] / 100; unset($row['amount_cents'], $row['commission_cents']); return $row; }, $query->fetchAll());
api_ok($rows, ['total' => (int)$count->fetchColumn(), 'limit' => $limit, 'offset' => $offset]);

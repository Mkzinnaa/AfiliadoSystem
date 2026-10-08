<?php
declare(strict_types=1);
require_once __DIR__ . '/bootstrap.php';
api_method('GET');
api_require_auth();
$limit = max(1, min(100, (int)($_GET['limit'] ?? 25)));
$offset = max(0, min(100000, (int)($_GET['offset'] ?? 0)));
$status = trim((string)($_GET['status'] ?? ''));
if ($status !== '' && !in_array($status, ['active','pending','inactive'], true)) api_fail('Filtro de status inválido.', 422, 'invalid_status');
$search = trim((string)($_GET['q'] ?? ''));
if (strlen($search) > 100) api_fail('A busca deve ter até 100 caracteres.', 422, 'query_too_long');
$where = ['tenant_id=?'];
$params = [tenant_id()];
if ($status !== '') { $where[] = 'status=?'; $params[] = $status; }
if ($search !== '') { $where[] = '(name LIKE ? OR email LIKE ? OR code LIKE ?)'; $term = '%' . addcslashes($search, '%_\\') . '%'; array_push($params, $term, $term, $term); }
$pdo = app_db();
$count = $pdo->prepare('SELECT COUNT(*) FROM affiliates WHERE ' . implode(' AND ', $where));
$count->execute($params);
$query = $pdo->prepare('SELECT id,name,email,affiliate_group AS `group`,commission,status,sales,orders,code,hotmart_code,created_at FROM affiliates WHERE ' . implode(' AND ', $where) . ' ORDER BY name LIMIT ' . $limit . ' OFFSET ' . $offset);
$query->execute($params);
api_ok($query->fetchAll(), ['total' => (int)$count->fetchColumn(), 'limit' => $limit, 'offset' => $offset]);

<?php
declare(strict_types=1);
require_once __DIR__ . '/../modules/tracking.php';

$workspaceSlug = trim((string)($_GET['workspace'] ?? ''));
$affiliateCode = trim((string)($_GET['ref'] ?? ''));
if (!preg_match('/^[a-z0-9-]{1,64}$/', $workspaceSlug) || !preg_match('/^[A-Za-z0-9_-]{1,100}$/', $affiliateCode)) {
    http_response_code(404);
    exit('Link de afiliado inválido.');
}
$query = app_db()->prepare("SELECT t.id AS tenant_id,a.id AS affiliate_id FROM tenants t JOIN affiliates a ON a.tenant_id=t.id WHERE t.slug=? AND a.code=? AND a.status='active' LIMIT 1");
$query->execute([$workspaceSlug, $affiliateCode]);
$affiliate = $query->fetch();
if (!$affiliate) { http_response_code(404); exit('Este link de afiliado não está ativo.'); }
$destinationQuery = app_db()->prepare("SELECT setting_value FROM tenant_settings WHERE tenant_id=? AND setting_key='affiliate_destination' LIMIT 1");
$destinationQuery->execute([$affiliate['tenant_id']]);
$destination = (string)($destinationQuery->fetchColumn() ?: '');
if ($destination === '') { http_response_code(503); exit('A página de vendas deste programa ainda não está configurada.'); }

$secure = !empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off';
$visitorToken = (string)($_COOKIE['vertice_affiliate_visitor'] ?? '');
if (!preg_match('/^[a-f0-9]{64}$/', $visitorToken)) {
    $visitorToken = bin2hex(random_bytes(32));
    setcookie('vertice_affiliate_visitor', $visitorToken, [
        'expires' => time() + 60 * 60 * 24 * 365,
        'path' => '/',
        'secure' => $secure,
        'httponly' => true,
        'samesite' => 'Lax',
    ]);
}
try {
    affiliate_record_click((string)$affiliate['tenant_id'], (string)$affiliate['affiliate_id'], $visitorToken);
} catch (Throwable $exception) {
    error_log('[AFFILIEY] Falha ao registrar clique de afiliado: ' . $exception->getMessage());
}
header('Location: ' . $destination, true, 302);
exit;

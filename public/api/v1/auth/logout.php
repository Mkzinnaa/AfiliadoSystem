<?php
declare(strict_types=1);
require_once __DIR__ . '/../bootstrap.php';
api_method('POST');
$token = api_access_token();
if ($token === null) api_fail('Informe um token Bearer válido.', 401, 'unauthorized');
$query = app_db()->prepare('UPDATE api_access_tokens SET revoked_at=UTC_TIMESTAMP() WHERE token_hash=? AND revoked_at IS NULL');
$query->execute([hash('sha256', $token)]);
$revoked=$query->rowCount()>0;
$query=app_db()->prepare('UPDATE affiliate_api_access_tokens SET revoked_at=UTC_TIMESTAMP() WHERE token_hash=? AND revoked_at IS NULL');
$query->execute([hash('sha256',$token)]);
api_ok(['revoked' => $revoked || $query->rowCount()>0]);

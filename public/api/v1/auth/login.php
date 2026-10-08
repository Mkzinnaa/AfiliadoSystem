<?php
declare(strict_types=1);
require_once __DIR__ . '/../bootstrap.php';
api_method('POST');
$body = api_json_body();
$email = filter_var(trim((string)($body['email'] ?? '')), FILTER_VALIDATE_EMAIL);
$password = (string)($body['password'] ?? '');
$device = trim((string)($body['device_name'] ?? 'Celular'));
if (!$email || $password === '' || strlen($password) > 4096) api_fail('Informe e-mail e senha válidos.', 422, 'invalid_credentials');
if (preg_match_all('/./us', $device) > 100) api_fail('O nome do dispositivo deve ter até 100 caracteres.', 422, 'invalid_device_name');
$attemptKey = api_login_attempt_key((string)$email);
if (api_login_is_locked($attemptKey)) {
    header('Retry-After: 900');
    api_fail('Muitas tentativas. Aguarde 15 minutos antes de entrar novamente.', 429, 'too_many_attempts');
}
$user = authenticate_user((string)$email, $password);
if (!$user) {
    api_login_record_failure($attemptKey);
    api_fail('E-mail ou senha incorretos.', 401, 'invalid_credentials');
}
api_login_clear_failures($attemptKey);
$token = api_issue_token((string)$user['id'], (string)$user['tenant_id'], $device);
api_json(['data' => ['user' => $user, ...$token], 'meta' => (object)[], 'error' => null], 201);

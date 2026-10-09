<?php
declare(strict_types=1);

require_once __DIR__ . '/auth.php';

const API_TOKEN_TTL_DAYS = 30;

function api_init(): void
{
    header('Cache-Control: no-store, private');
    header('X-Content-Type-Options: nosniff');
    header('X-Frame-Options: DENY');
    header('Referrer-Policy: no-referrer');
    if ((!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') || (int)($_SERVER['SERVER_PORT'] ?? 0) === 443) {
        header('Strict-Transport-Security: max-age=31536000');
    }
    header('Vary: Origin');

    $origin = trim((string)($_SERVER['HTTP_ORIGIN'] ?? ''));
    if ($origin !== '') {
        $allowed = ['https://afiliados.horizoncafe.com.br', 'https://localhost', 'capacitor://localhost'];
        $host = strtolower((string)parse_url('http://' . ($_SERVER['HTTP_HOST'] ?? ''), PHP_URL_HOST));
        if (in_array($host, ['localhost', '127.0.0.1', '::1'], true)) {
            $scheme = !empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off' ? 'https' : 'http';
            $allowed[] = $scheme . '://' . $host . (isset($_SERVER['SERVER_PORT']) && !in_array((int)$_SERVER['SERVER_PORT'], [80, 443], true) ? ':' . (int)$_SERVER['SERVER_PORT'] : '');
        }
        $configured = trim((string)(getenv('VERTICE_API_ALLOWED_ORIGINS') ?: ''));
        if ($configured !== '') {
            foreach (explode(',', $configured) as $extraOrigin) {
                $extraOrigin = rtrim(trim($extraOrigin), '/');
                if ($extraOrigin !== '' && filter_var($extraOrigin, FILTER_VALIDATE_URL)) $allowed[] = $extraOrigin;
            }
        }
        if (!in_array($origin, $allowed, true)) api_fail('Origem não permitida.', 403, 'origin_not_allowed');
        header('Access-Control-Allow-Origin: ' . $origin);
    }
    header('Access-Control-Allow-Headers: Authorization, Content-Type');
    header('Access-Control-Allow-Methods: GET, POST, OPTIONS');

    if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'OPTIONS') {
        http_response_code(204);
        exit;
    }
}

function api_json(array $payload, int $status = 200): never
{
    http_response_code($status);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE);
    exit;
}

function api_ok(mixed $data, array $meta = []): never
{
    api_json(['data' => $data, 'meta' => (object)$meta, 'error' => null]);
}

function api_fail(string $message, int $status = 400, string $code = 'request_failed'): never
{
    api_json(['data' => null, 'meta' => (object)[], 'error' => ['code' => $code, 'message' => $message]], $status);
}

function api_method(string ...$allowed): void
{
    $method = strtoupper((string)($_SERVER['REQUEST_METHOD'] ?? 'GET'));
    if (!in_array($method, $allowed, true)) {
        header('Allow: ' . implode(', ', $allowed));
        api_fail('Método não permitido.', 405, 'method_not_allowed');
    }
}

function api_json_body(): array
{
    $length = (int)($_SERVER['CONTENT_LENGTH'] ?? 0);
    if ($length > 65536) api_fail('Corpo da requisição muito grande.', 413, 'payload_too_large');
    $raw = file_get_contents('php://input', false, null, 0, 65537);
    if (is_string($raw) && strlen($raw) > 65536) api_fail('Corpo da requisição muito grande.', 413, 'payload_too_large');
    if (!is_string($raw) || trim($raw) === '') return [];
    $body = json_decode($raw, true);
    if (!is_array($body)) api_fail('Envie um JSON válido.', 400, 'invalid_json');
    return $body;
}

function api_access_token(): ?string
{
    $header = (string)($_SERVER['HTTP_AUTHORIZATION'] ?? '');
    if ($header === '' && function_exists('getallheaders')) {
        foreach (getallheaders() as $name => $value) if (strcasecmp((string)$name, 'Authorization') === 0) $header = (string)$value;
    }
    if (!preg_match('/^Bearer\s+([A-Za-z0-9_-]{40,64})$/i', trim($header), $match)) return null;
    return $match[1];
}

function api_require_auth(): array
{
    $token = api_access_token();
    if ($token === null) api_fail('Informe um token Bearer válido.', 401, 'unauthorized');
    $query = app_db()->prepare('SELECT t.id,t.tenant_id,t.user_id,u.name,u.email,m.role,ten.name AS tenant_name FROM api_access_tokens t JOIN memberships m ON m.tenant_id=t.tenant_id AND m.user_id=t.user_id JOIN users u ON u.id=t.user_id JOIN tenants ten ON ten.id=t.tenant_id WHERE t.token_hash=? AND t.revoked_at IS NULL AND t.expires_at>UTC_TIMESTAMP() LIMIT 1');
    $query->execute([hash('sha256', $token)]);
    $user = $query->fetch();
    if (!$user) api_fail('A sessão expirou ou foi revogada. Entre novamente.', 401, 'token_expired');
    app_db()->prepare('UPDATE api_access_tokens SET last_used_at=UTC_TIMESTAMP() WHERE id=?')->execute([$user['id']]);
    unset($user['id']);
    $GLOBALS['VERTICE_API_USER'] = $user;
    return $user;
}

function api_issue_token(string $userId, string $tenantId, string $deviceName): array
{
    $token = rtrim(strtr(base64_encode(random_bytes(32)), '+/', '-_'), '=');
    $id = new_id('api');
    $expires = (new DateTimeImmutable('now', new DateTimeZone('UTC')))->modify('+' . API_TOKEN_TTL_DAYS . ' days')->format('Y-m-d H:i:s');
    $query = app_db()->prepare('INSERT INTO api_access_tokens(id,tenant_id,user_id,token_hash,device_name,expires_at) VALUES(?,?,?,?,?,?)');
    $query->execute([$id, $tenantId, $userId, hash('sha256', $token), $deviceName, $expires]);
    return ['access_token' => $token, 'token_type' => 'Bearer', 'expires_at' => $expires];
}

function api_login_attempt_key(string $email): string
{
    return app_login_attempt_key('api', $email);
}

function api_login_is_locked(string $key): bool
{
    return app_login_is_locked($key);
}

function api_login_record_failure(string $key): void
{
    app_login_record_failure($key);
}

function api_login_clear_failures(string $key): void
{
    app_login_clear_failures($key);
}

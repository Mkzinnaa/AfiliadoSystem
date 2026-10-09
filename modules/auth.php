<?php
declare(strict_types=1);

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/tenancy.php';
require_once __DIR__ . '/database.php';

function start_app_session(): void
{
    if (session_status() !== PHP_SESSION_ACTIVE) {
        $host = strtolower((string)parse_url('http://' . ($_SERVER['HTTP_HOST'] ?? ''), PHP_URL_HOST));
        $isLocal = in_array($host, ['localhost', '127.0.0.1', '::1'], true);
        $secureSetting = getenv('VERTICE_COOKIE_SECURE');
        $secureCookie = $secureSetting !== false
            ? filter_var($secureSetting, FILTER_VALIDATE_BOOLEAN)
            : (!$isLocal || (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') || (int)($_SERVER['SERVER_PORT'] ?? 0) === 443);
        ini_set('session.use_strict_mode', '1');
        ini_set('session.use_only_cookies', '1');
        ini_set('session.use_trans_sid', '0');
        session_set_cookie_params([
            'httponly' => true,
            'secure' => $secureCookie,
            'samesite' => 'Lax',
            'path' => '/',
        ]);
        header('X-Content-Type-Options: nosniff');
        header('X-Frame-Options: DENY');
        header('Referrer-Policy: no-referrer');
        header('Cache-Control: no-store, private');
        if ((!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') || (int)($_SERVER['SERVER_PORT'] ?? 0) === 443) {
            header('Strict-Transport-Security: max-age=31536000');
        }
        session_start();
    }
}

function app_login_attempt_key(string $surface, string $email): string
{
    return hash('sha256', strtolower(trim($surface)) . "\n" . strtolower(trim($email)) . "\n" . (string)($_SERVER['REMOTE_ADDR'] ?? 'unknown'));
}

function app_login_is_locked(string $key): bool
{
    $query = app_db()->prepare('SELECT locked_until>UTC_TIMESTAMP() FROM api_login_attempts WHERE attempt_key=?');
    $query->execute([$key]);
    return (bool)$query->fetchColumn();
}

function app_login_record_failure(string $key): void
{
    app_db()->prepare('INSERT INTO api_login_attempts(attempt_key,attempts,window_started_at) VALUES(?,1,UTC_TIMESTAMP()) ON DUPLICATE KEY UPDATE locked_until=IF(window_started_at<DATE_SUB(UTC_TIMESTAMP(),INTERVAL 15 MINUTE),NULL,IF(attempts>=9,DATE_ADD(UTC_TIMESTAMP(),INTERVAL 15 MINUTE),locked_until)),attempts=IF(window_started_at<DATE_SUB(UTC_TIMESTAMP(),INTERVAL 15 MINUTE),1,attempts+1),window_started_at=IF(window_started_at<DATE_SUB(UTC_TIMESTAMP(),INTERVAL 15 MINUTE),UTC_TIMESTAMP(),window_started_at)')->execute([$key]);
    if (random_int(1, 100) === 1) {
        app_db()->exec('DELETE FROM api_login_attempts WHERE window_started_at<DATE_SUB(UTC_TIMESTAMP(),INTERVAL 1 DAY) AND (locked_until IS NULL OR locked_until<UTC_TIMESTAMP()) LIMIT 500');
    }
}

function app_login_clear_failures(string $key): void
{
    app_db()->prepare('DELETE FROM api_login_attempts WHERE attempt_key=?')->execute([$key]);
}

function current_user(): ?array
{
    if (isset($GLOBALS['VERTICE_API_USER']) && is_array($GLOBALS['VERTICE_API_USER'])) {
        return $GLOBALS['VERTICE_API_USER'];
    }
    start_app_session();
    return $_SESSION['affiliate_user'] ?? null;
}

function attempt_login(string $email, string $password): bool
{
    $user = authenticate_user($email, $password);
    if ($user === null) return false;
    start_app_session();
    session_regenerate_id(true);
    $_SESSION['affiliate_user'] = $user;
    return true;
}

function require_login(): void
{
    if (current_user() === null) {
        header('Location: login.php');
        exit;
    }
}

function logout_user(): void
{
    start_app_session();
    $params = session_get_cookie_params();
    $_SESSION = [];
    if (ini_get('session.use_cookies')) {
        setcookie(session_name(), '', [
            'expires' => time() - 42000,
            'path' => $params['path'] ?: '/',
            'domain' => $params['domain'],
            'secure' => (bool)$params['secure'],
            'httponly' => (bool)$params['httponly'],
            'samesite' => $params['samesite'] ?: 'Lax',
        ]);
    }
    session_destroy();
}

function require_role(array $allowedRoles): void
{
    require_login();
    $role = (string)(current_user()['role'] ?? 'viewer');
    if (!in_array($role, $allowedRoles, true)) {
        http_response_code(403);
        exit('Você não tem permissão para realizar esta ação.');
    }
}

function can_manage_workspace(): bool
{
    return in_array((string)(current_user()['role'] ?? 'viewer'), ['owner','admin','manager'], true);
}

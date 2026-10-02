<?php
declare(strict_types=1);

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/tenancy.php';
require_once __DIR__ . '/database.php';

function start_app_session(): void
{
    if (session_status() !== PHP_SESSION_ACTIVE) {
        session_set_cookie_params([
            'httponly' => true,
            'secure' => !empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off',
            'samesite' => 'Lax',
            'path' => '/',
        ]);
        session_start();
    }
}

function current_user(): ?array
{
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
    $_SESSION = [];
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

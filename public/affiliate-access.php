<?php
declare(strict_types=1);
require_once __DIR__ . '/../modules/auth.php';
start_app_session();
$token = trim((string)($_GET['token'] ?? $_POST['token'] ?? ''));
$details = affiliate_access_invite_details($token);
$error = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (empty($_SESSION['affiliate_access_csrf']) || !hash_equals((string)$_SESSION['affiliate_access_csrf'], (string)($_POST['csrf'] ?? ''))) { http_response_code(403); exit('Sessão expirada. Atualize a página e tente novamente.'); }
    try {
        $user = accept_affiliate_access_invite($token, trim((string)($_POST['name'] ?? '')), (string)($_POST['password'] ?? ''));
        session_regenerate_id(true); $_SESSION['affiliate_user'] = $user;
        header('Location: affiliate-dashboard.php'); exit;
    } catch (DomainException $e) { $error = $e->getMessage(); }
}
if (empty($_SESSION['affiliate_access_csrf'])) $_SESSION['affiliate_access_csrf'] = bin2hex(random_bytes(32));
function ae(string $s): string { return htmlspecialchars($s, ENT_QUOTES, 'UTF-8'); }
?>
<!doctype html><html lang="pt-BR"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>Acesso de aluno — AFFILIEY"><link rel="stylesheet" href="/assets/css/brand.css"><link rel="stylesheet" href="/assets/css/app-theme.css"><link rel="icon" href="/brand/affiliey-favicon.png"></head><body class="access-page"><main class="affiliate-access-card"><img src="/brand/affiliey-logo-dark.png" alt="AFFILIEY" width="190"><h1>Ative seu ambiente de aluno</h1><?php if (!$details): ?><p>Informe o token do convite que o produtor compartilhou com você.</p><form method="get"><label>Token do convite<input name="token" value="<?= ae($token) ?>" autocomplete="off" required></label><button type="submit">Validar convite</button></form><a href="login.php">Ir para entrar</a><?php else: ?><p>Convite para <strong><?= ae((string)$details['name']) ?></strong> no programa <strong><?= ae((string)$details['tenant_name']) ?></strong>.</p><p>Use o e-mail convidado <strong><?= ae((string)$details['email']) ?></strong>. Se já tem conta AFFILIEY, informe sua senha atual para vincular com segurança.</p><?php if ($error): ?><p role="alert" class="message error"><?= ae($error) ?></p><?php endif; ?><form method="post"><input type="hidden" name="csrf" value="<?= ae((string)$_SESSION['affiliate_access_csrf']) ?>"><input type="hidden" name="token" value="<?= ae($token) ?>"><label>Nome (para nova conta)<input name="name" autocomplete="name"></label><label>Senha da conta (mínimo 12 caracteres)<input name="password" type="password" minlength="12" autocomplete="new-password" required></label><button type="submit">Ativar painel</button></form><?php endif; ?></main></body></html>

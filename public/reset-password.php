<?php
declare(strict_types=1);
require_once __DIR__ . '/../modules/auth.php';
start_app_session();
header('Cache-Control: no-store');
header('Referrer-Policy: no-referrer');
$token = (string)($_GET['token'] ?? $_POST['token'] ?? '');
$valid = password_reset_token_valid($token);
$error = '';
$complete = false;
if (empty($_SESSION['reset_password_csrf'])) $_SESSION['reset_password_csrf'] = bin2hex(random_bytes(32));
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!hash_equals((string)$_SESSION['reset_password_csrf'], (string)($_POST['csrf'] ?? ''))) { http_response_code(403); exit('Sessão expirada. Atualize a página.'); }
    $password = (string)($_POST['password'] ?? '');
    $confirm = (string)($_POST['confirm_password'] ?? '');
    if (!$valid) $error = 'Este link expirou ou já foi usado. Solicite uma nova redefinição.';
    elseif (strlen($password) < 12) $error = 'Use uma senha com pelo menos 12 caracteres.';
    elseif ($password !== $confirm) $error = 'As senhas não conferem.';
    else {
        try { $complete = complete_password_reset($token, $password); $valid = false; }
        catch (Throwable $exception) { $error = 'Não foi possível atualizar a senha agora. Tente novamente.'; }
    }
}
function rpe($value): string { return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8'); }
?>
<!doctype html><html lang="pt-BR"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>Redefinir senha — Vértice</title><link rel="preconnect" href="https://fonts.googleapis.com"><link rel="preconnect" href="https://fonts.gstatic.com" crossorigin><link href="https://fonts.googleapis.com/css2?family=DM+Sans:wght@400;500;600;700&family=Manrope:wght@400;500;600;700;800&display=swap" rel="stylesheet"><link rel="stylesheet" href="assets/css/login.css"><link rel="stylesheet" href="assets/css/login-access.css"><link rel="stylesheet" href="assets/css/password-toggle.css"><script src="assets/js/password-toggle.js" defer></script></head><body><main class="layout"><section class="story"><div class="brand"><span class="mark">v</span> vértice<em>.</em></div><div class="copy"><div class="eyebrow">ACESSO SEGURO</div><h1>Proteja sua<br>conta.</h1><p>Escolha uma senha nova para voltar a acessar seu espaço.</p></div></section><section class="form-side"><div class="form-wrap"><h2><?= $complete ? 'Senha atualizada' : 'Redefinir senha' ?></h2><?php if ($complete): ?><p class="sub">Sua senha foi atualizada. Você já pode entrar com a nova senha.</p><a class="submit" style="display:grid;place-items:center;text-decoration:none" href="login.php">Voltar ao login →</a><?php elseif (!$valid): ?><p class="sub">Este link é inválido, expirou ou já foi usado.</p><a class="submit" style="display:grid;place-items:center;text-decoration:none" href="login.php?action=forgot">Solicitar outro link →</a><?php else: ?><p class="sub">O link é válido por uma hora e só pode ser usado uma vez.</p><?php if ($error !== ''): ?><div class="message error" role="alert"><?= rpe($error) ?></div><?php endif; ?><form method="post" autocomplete="on"><input type="hidden" name="csrf" value="<?= rpe($_SESSION['reset_password_csrf']) ?>"><input type="hidden" name="token" value="<?= rpe($token) ?>"><div class="field"><label for="password">Nova senha</label><div class="password-control"><input id="password" name="password" type="password" autocomplete="new-password" minlength="12" required><button class="toggle-password" type="button" data-password-toggle aria-label="Mostrar senha" aria-pressed="false"><svg class="eye-open" viewBox="0 0 24 24" aria-hidden="true"><path d="M2 12s3.6-7 10-7 10 7 10 7-3.6 7-10 7S2 12 2 12Z"/><circle cx="12" cy="12" r="3"/></svg><svg class="eye-closed" viewBox="0 0 24 24" aria-hidden="true"><path d="m3 3 18 18M10.6 10.6a2 2 0 0 0 2.8 2.8"/><path d="M9.9 5.2A10.8 10.8 0 0 1 12 5c6.4 0 10 7 10 7a15 15 0 0 1-3.2 4.1M6.2 6.2C3.5 8 2 12 2 12s3.6 7 10 7c1.5 0 2.8-.4 4-1"/></svg></button></div><small>Pelo menos 12 caracteres.</small></div><div class="field"><label for="confirm_password">Confirmar nova senha</label><div class="password-control"><input id="confirm_password" name="confirm_password" type="password" autocomplete="new-password" minlength="12" required><button class="toggle-password" type="button" data-password-toggle aria-label="Mostrar senha" aria-pressed="false"><svg class="eye-open" viewBox="0 0 24 24" aria-hidden="true"><path d="M2 12s3.6-7 10-7 10 7 10 7-3.6 7-10 7S2 12 2 12Z"/><circle cx="12" cy="12" r="3"/></svg><svg class="eye-closed" viewBox="0 0 24 24" aria-hidden="true"><path d="m3 3 18 18M10.6 10.6a2 2 0 0 0 2.8 2.8"/><path d="M9.9 5.2A10.8 10.8 0 0 1 12 5c6.4 0 10 7 10 7a15 15 0 0 1-3.2 4.1M6.2 6.2C3.5 8 2 12 2 12s3.6 7 10 7c1.5 0 2.8-.4 4-1"/></svg></button></div></div><button class="submit" type="submit">Salvar nova senha →</button></form><?php endif; ?><div class="switch"><a class="link" href="login.php">Voltar para entrar</a></div></div></section></main></body></html>

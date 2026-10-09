<?php
declare(strict_types=1);
require_once __DIR__ . '/../modules/auth.php';
require_once __DIR__ . '/../modules/mailer.php';
start_app_session();
$error = '';
$notice = '';
if (empty($_SESSION['login_csrf'])) $_SESSION['login_csrf'] = bin2hex(random_bytes(32));

if (isset($_GET['logout'])) {
    logout_user();
    header('Location: login.php');
    exit;
}

if (current_user() !== null && !empty(current_user()['id'])) {
    header('Location: dashboard.php');
    exit;
}
if (current_user() !== null) { logout_user(); start_app_session(); $_SESSION['login_csrf'] = bin2hex(random_bytes(32)); }

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!hash_equals((string)($_SESSION['login_csrf'] ?? ''), (string)($_POST['csrf'] ?? ''))) { http_response_code(403); exit('Sessão expirada. Atualize a página e tente novamente.'); }
    $action = $_POST['action'] ?? 'login';
    $login = trim((string)($_POST['email'] ?? ''));
    $email = filter_var($login, FILTER_VALIDATE_EMAIL);
    if (!$email && $action === 'login' && demo_enabled() && hash_equals((string)DEMO_USER['login'], $login)) {
        $email = (string)DEMO_USER['email'];
    }

    if (!$email) {
        $error = 'Informe um e-mail válido.';
    } elseif ($action === 'forgot') {
        if (!app_mail_is_configured()) {
            $notice = 'A recuperação por e-mail ainda não está configurada. Peça ao administrador para configurar o SMTP.';
        } else {
            try {
                $token = issue_password_reset((string)$email);
                if ($token !== null) {
                    $resetUrl = app_public_url() . '/reset-password.php?token=' . rawurlencode($token);
                    $safeUrl = htmlspecialchars($resetUrl, ENT_QUOTES, 'UTF-8');
                    $html = app_email_page('Redefina sua senha', '<p>Recebemos uma solicitação para trocar a senha da sua conta AFFILIEY.</p><p><a href="' . $safeUrl . '" style="display:inline-block;background:var(--brand-primary-dark);color:#fff;padding:12px 18px;border-radius:8px;text-decoration:none">Criar nova senha</a></p><p>O link expira em uma hora e só pode ser usado uma vez.</p>');
                    app_send_email((string)$email, 'Redefinição de senha — AFFILIEY', $html, "Recebemos uma solicitação para trocar a senha da sua conta AFFILIEY.\n\nAcesse este link para criar uma nova senha: $resetUrl\n\nO link expira em uma hora e só pode ser usado uma vez.");
                }
                $notice = 'Se o e-mail estiver cadastrado, você receberá instruções para redefinir sua senha.';
            } catch (Throwable $mailError) {
                error_log('[AFFILIEY] Falha no envio de recuperação de senha: ' . $mailError->getMessage());
                $notice = 'Não foi possível enviar o e-mail agora. Tente novamente mais tarde ou fale com o administrador.';
            }
        }
    } elseif ($action === 'signup') {
        header('Location: register.php');
        exit;
    } else {
        $password = (string)($_POST['password'] ?? '');
        if (attempt_login((string)$email, $password)) {
            header('Location: dashboard.php');
            exit;
        }
        $error = 'E-mail ou senha incorretos. Confira os dados e tente novamente.';
    }
}

$mode = ($_POST['action'] ?? $_GET['action'] ?? 'login') === 'forgot' ? 'forgot' : 'login';
?>
<!doctype html>
<html lang="pt-BR">
<head>
  <meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">
  <title>Entrar — AFFILIEY</title>
  <link rel="preconnect" href="https://fonts.googleapis.com"><link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
  <link href="https://fonts.googleapis.com/css2?family=DM+Sans:wght@400;500;600;700&family=Manrope:wght@400;500;600;700;800&display=swap" rel="stylesheet">
  <link rel="stylesheet" href="assets/css/login.css">
  <link rel="stylesheet" href="assets/css/login-access.css">
  <link rel="stylesheet" href="assets/css/password-toggle.css">
  <script src="assets/js/password-toggle.js" defer></script>
<link rel="manifest" href="/manifest.webmanifest"><meta name="apple-mobile-web-app-capable" content="yes"><meta name="apple-mobile-web-app-status-bar-style" content="default"><link rel="apple-touch-icon" sizes="180x180" href="/brand/affiliey-apple-touch-icon.png"><link rel="stylesheet" href="/assets/css/pwa.css"><script src="/assets/js/pwa.js" defer></script><link rel="icon" type="image/png" href="/brand/affiliey-favicon.png"><link rel="stylesheet" href="/assets/css/brand.css?v=affiliey4"><meta name="theme-color" content="#111827"><meta property="og:site_name" content="AFFILIEY"><meta name="description" content="Gestão de afiliados, vendas, metas e campanhas em uma plataforma."><meta property="og:description" content="Gestão de afiliados, vendas, metas e campanhas em uma plataforma."><meta property="og:type" content="website"><meta property="og:title" content="AFFILIEY | Plataforma de afiliados"><meta property="og:image" content="https://afiliados.horizoncafe.com.br/brand/affiliey-logo-light.png"><link rel="stylesheet" href="/assets/css/page-transitions.css?v=2"><script src="/assets/js/page-transitions.js?v=2" defer></script><link rel="stylesheet" href="/assets/css/typography.css?v=1"></head>
<body class="access-page"><main class="layout">
  <section class="story"><div class="brand"><picture class="brand-picture brand-picture--dark"><source media="(max-width: 640px)" srcset="/brand/affiliey-symbol.png"><img class="brand-logo" src="/brand/affiliey-logo-dark.png" alt="AFFILIEY"></picture></div><div class="copy"><div class="eyebrow">SUA OPERAÇÃO, EM UM SÓ LUGAR</div><h1>Boas parcerias<br>fazem crescer.</h1><p>Gerencie seus afiliados, acompanhe resultados e transforme metas em conquistas.</p></div><div class="quote">“Finalmente consigo enxergar toda a operação em um só lugar.”<b>Mariana Costa · NovaVida Store</b></div></section>
  <section class="form-side"><div class="form-wrap">
    <?php if ($mode === 'forgot'): ?><h2>Recuperar senha</h2><p class="sub">Informe seu e-mail. Se o SMTP estiver configurado, enviaremos um link seguro.</p>
    <?php elseif ($mode === 'signup'): ?><h2>Crie sua conta</h2><p class="sub">Comece a organizar sua operação de afiliados.</p>
    <?php else: ?><h2>Bem-vinda de volta</h2><p class="sub">Acesse sua conta para continuar.</p><?php endif; ?>
    <?php if ($error !== ''): ?><div class="message error" role="alert"><?= htmlspecialchars($error, ENT_QUOTES, 'UTF-8') ?></div><?php endif; ?>
    <?php if ($notice !== ''): ?><div class="message notice" role="status"><?= htmlspecialchars($notice, ENT_QUOTES, 'UTF-8') ?></div><?php endif; ?>
    <form method="post" action="login.php" autocomplete="on"><input type="hidden" name="csrf" value="<?= htmlspecialchars($_SESSION['login_csrf'], ENT_QUOTES, 'UTF-8') ?>">
      <input type="hidden" name="action" value="<?= htmlspecialchars($mode, ENT_QUOTES, 'UTF-8') ?>">
      <div class="field"><label for="email"><?= $mode === 'login' && demo_enabled() ? 'E-mail ou login demo' : 'E-mail' ?></label><input id="email" type="<?= $mode === 'login' && demo_enabled() ? 'text' : 'email' ?>" name="email" autocomplete="<?= $mode === 'login' && demo_enabled() ? 'username' : 'email' ?>" placeholder="<?= $mode === 'login' && demo_enabled() ? 'voce@empresa.com ou 123' : 'voce@empresa.com' ?>" value="<?= htmlspecialchars((string)($_POST['email'] ?? ''), ENT_QUOTES, 'UTF-8') ?>" required></div>
      <?php if ($mode !== 'forgot'): ?><div class="field"><label for="password">Senha</label><div class="password-control"><input id="password" type="password" name="password" autocomplete="current-password" placeholder="Sua senha" minlength="6" required><button class="toggle-password" type="button" data-password-toggle aria-label="Mostrar senha" aria-pressed="false"><svg class="eye-open" viewBox="0 0 24 24" aria-hidden="true"><path d="M2 12s3.6-7 10-7 10 7 10 7-3.6 7-10 7S2 12 2 12Z"/><circle cx="12" cy="12" r="3"/></svg><svg class="eye-closed" viewBox="0 0 24 24" aria-hidden="true"><path d="m3 3 18 18M10.6 10.6a2 2 0 0 0 2.8 2.8"/><path d="M9.9 5.2A10.8 10.8 0 0 1 12 5c6.4 0 10 7 10 7a15 15 0 0 1-3.2 4.1M6.2 6.2C3.5 8 2 12 2 12s3.6 7 10 7c1.5 0 2.8-.4 4-1"/></svg></button></div></div><?php endif; ?>
      <?php if ($mode === 'login'): ?><div class="options"><label><input type="checkbox" name="remember" value="1" checked> Manter conectado</label><button class="link" name="action" value="forgot" formnovalidate>Esqueci minha senha</button></div><?php endif; ?>
      <button class="submit" type="submit"><?= $mode === 'forgot' ? 'Enviar instruções  →' : 'Entrar na plataforma  →' ?></button>
    </form>
    <?php if ($mode === 'login' && demo_enabled()): ?><div class="demo">Acesso de demonstração: Login <b><?= htmlspecialchars((string)DEMO_USER['login'], ENT_QUOTES, 'UTF-8') ?></b> · Senha <b><?= htmlspecialchars((string)DEMO_USER['password'], ENT_QUOTES, 'UTF-8') ?></b></div><?php endif; ?>
    <div class="switch"><?php if ($mode === 'forgot'): ?><a class="link" href="login.php">← Voltar para entrar</a><?php else: ?>Ainda não tem um espaço? <a class="link" href="register.php">Criar conta grátis</a><?php endif; ?></div>
    <div class="terms">Ao continuar, você concorda com nossos <a href="#">Termos de Uso</a> e <a href="#">Política de Privacidade</a>.</div><div class="hint">Protótipo demonstrativo · sessão PHP</div><div class="admin-access"><a href="platform-admin-login.php">Acessar painel SaaS <span aria-hidden="true">→</span></a></div>
  </div></section>
</main></body></html>

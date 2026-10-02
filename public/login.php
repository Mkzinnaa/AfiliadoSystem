<?php
declare(strict_types=1);
require_once __DIR__ . '/../modules/auth.php';
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
    $email = filter_var(trim((string)($_POST['email'] ?? '')), FILTER_VALIDATE_EMAIL);

    if (!$email) {
        $error = 'Informe um e-mail válido.';
    } elseif ($action === 'forgot') {
        // Exibe uma resposta neutra para não revelar se um e-mail existe.
        $notice = 'Se o e-mail estiver cadastrado, você receberá instruções para redefinir sua senha.';
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
  <title>Entrar — Vértice</title>
  <link rel="preconnect" href="https://fonts.googleapis.com"><link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
  <link href="https://fonts.googleapis.com/css2?family=DM+Sans:wght@400;500;600;700&family=Manrope:wght@400;500;600;700;800&display=swap" rel="stylesheet">
  <link rel="stylesheet" href="assets/css/login.css">
  <link rel="stylesheet" href="assets/css/login-access.css">
</head>
<body><main class="layout">
  <section class="story"><div class="brand"><span class="mark">v</span> vértice<em>.</em></div><div class="copy"><div class="eyebrow">SUA OPERAÇÃO, EM UM SÓ LUGAR</div><h1>Boas parcerias<br>fazem crescer.</h1><p>Gerencie seus afiliados, acompanhe resultados e transforme metas em conquistas.</p></div><div class="quote">“Finalmente consigo enxergar toda a operação em um só lugar.”<b>Mariana Costa · NovaVida Store</b></div></section>
  <section class="form-side"><div class="form-wrap">
    <?php if ($mode === 'forgot'): ?><h2>Recuperar senha</h2><p class="sub">Vamos enviar as instruções para o seu e-mail.</p>
    <?php elseif ($mode === 'signup'): ?><h2>Crie sua conta</h2><p class="sub">Comece a organizar sua operação de afiliados.</p>
    <?php else: ?><h2>Bem-vinda de volta</h2><p class="sub">Acesse sua conta para continuar.</p><?php endif; ?>
    <?php if ($error !== ''): ?><div class="message error" role="alert"><?= htmlspecialchars($error, ENT_QUOTES, 'UTF-8') ?></div><?php endif; ?>
    <?php if ($notice !== ''): ?><div class="message notice" role="status"><?= htmlspecialchars($notice, ENT_QUOTES, 'UTF-8') ?></div><?php endif; ?>
    <form method="post" action="login.php" autocomplete="on"><input type="hidden" name="csrf" value="<?= htmlspecialchars($_SESSION['login_csrf'], ENT_QUOTES, 'UTF-8') ?>">
      <input type="hidden" name="action" value="<?= htmlspecialchars($mode, ENT_QUOTES, 'UTF-8') ?>">
      <div class="field"><label for="email">E-mail</label><input id="email" type="email" name="email" autocomplete="email" placeholder="voce@empresa.com" value="<?= htmlspecialchars((string)($_POST['email'] ?? ''), ENT_QUOTES, 'UTF-8') ?>" required></div>
      <?php if ($mode !== 'forgot'): ?><div class="field"><label for="password">Senha</label><input id="password" type="password" name="password" autocomplete="current-password" placeholder="Sua senha" minlength="6" required></div><?php endif; ?>
      <?php if ($mode === 'login'): ?><div class="options"><label><input type="checkbox" name="remember" value="1" checked> Manter conectado</label><button class="link" name="action" value="forgot" formnovalidate>Esqueci minha senha</button></div><?php endif; ?>
      <button class="submit" type="submit"><?= $mode === 'forgot' ? 'Enviar instruções  →' : 'Entrar na plataforma  →' ?></button>
    </form>
    <?php if ($mode === 'login' && demo_enabled()): ?><div class="demo">Acesso de demonstração: <b>mariana@novavida.com</b> · Senha: <b>Vertice2026!</b></div><?php endif; ?>
    <div class="switch"><?php if ($mode === 'forgot'): ?><a class="link" href="login.php">← Voltar para entrar</a><?php else: ?>Ainda não tem um espaço? <a class="link" href="register.php">Criar conta grátis</a><?php endif; ?></div>
    <div class="terms">Ao continuar, você concorda com nossos <a href="#">Termos de Uso</a> e <a href="#">Política de Privacidade</a>.</div><div class="hint">Protótipo demonstrativo · sessão PHP</div><div class="admin-access"><a href="platform-admin-login.php">Acessar painel SaaS <span aria-hidden="true">→</span></a></div>
  </div></section>
</main></body></html>

<?php
declare(strict_types=1);
require_once __DIR__ . '/../modules/auth.php';
start_app_session();$pdo=app_db();
if((int)$pdo->query('SELECT COUNT(*) FROM platform_admins')->fetchColumn()===0){header('Location: platform-admin-setup.php');exit;}
if(!empty($_SESSION['platform_admin_id'])){header('Location: platform-admin.php');exit;}
if(empty($_SESSION['platform_login_csrf']))$_SESSION['platform_login_csrf']=bin2hex(random_bytes(32));$error='';
if($_SERVER['REQUEST_METHOD']==='POST'){
    if(!hash_equals((string)$_SESSION['platform_login_csrf'],(string)($_POST['csrf']??''))){http_response_code(403);exit('Sessão expirada. Atualize a página.');}
    $now=time();$attempts=$_SESSION['platform_login_attempts']??['count'=>0,'until'=>0];
    if(($attempts['until']??0)>$now)$error='Muitas tentativas. Aguarde alguns minutos e tente novamente.';
    else{$email=trim((string)($_POST['email']??''));$password=(string)($_POST['password']??'');$stmt=$pdo->prepare('SELECT id,name,email,password_hash FROM platform_admins WHERE email=?');$stmt->execute([$email]);$admin=$stmt->fetch();
        if($admin&&password_verify($password,$admin['password_hash'])){session_regenerate_id(true);$_SESSION['platform_admin_id']=$admin['id'];$_SESSION['platform_login_attempts']=['count'=>0,'until'=>0];$pdo->prepare('UPDATE platform_admins SET last_login=CURRENT_TIMESTAMP WHERE id=?')->execute([$admin['id']]);header('Location: platform-admin.php');exit;}
        $attempts['count']=(int)($attempts['count']??0)+1;if($attempts['count']>=5){$attempts=['count'=>0,'until'=>$now+300];$error='Muitas tentativas. Aguarde cinco minutos e tente novamente.';}else $error='E-mail ou senha incorretos.';$_SESSION['platform_login_attempts']=$attempts;
    }
}
function ple($v):string{return htmlspecialchars((string)$v,ENT_QUOTES,'UTF-8');}
?>
<!doctype html>
<html lang="pt-BR">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width,initial-scale=1">
  <title>Entrar no painel SaaS — Vértice</title>
  <link rel="preconnect" href="https://fonts.googleapis.com">
  <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
  <link href="https://fonts.googleapis.com/css2?family=DM+Sans:wght@400;500;600;700&family=Manrope:wght@400;500;600;700;800&display=swap" rel="stylesheet">
  <link rel="stylesheet" href="assets/css/login.css">
  <link rel="stylesheet" href="assets/css/login-access.css">
  <link rel="stylesheet" href="assets/css/password-toggle.css">
  <script src="assets/js/password-toggle.js" defer></script>
<link rel="manifest" href="/manifest.webmanifest"><meta name="theme-color" content="#176b50"><meta name="apple-mobile-web-app-capable" content="yes"><meta name="apple-mobile-web-app-status-bar-style" content="default"><link rel="apple-touch-icon" sizes="192x192" href="/assets/icons/icon-192.png"><link rel="stylesheet" href="/assets/css/pwa.css"><script src="/assets/js/pwa.js" defer></script></head>
<body>
  <main class="layout">
    <section class="story">
      <div class="brand"><span class="mark">v</span> vértice<em>.</em></div>
      <div class="copy">
        <div class="eyebrow">ADMINISTRAÇÃO DO SAAS</div>
        <h1>Uma visão completa<br>da plataforma.</h1>
        <p>Gerencie espaços, planos e assinaturas em uma área administrativa protegida.</p>
      </div>
      <div class="quote">Acesso exclusivo para a equipe responsável pela plataforma.</div>
    </section>
    <section class="form-side">
      <div class="form-wrap">
        <h2>Entrar no painel SaaS</h2>
        <p class="sub">Use suas credenciais administrativas.</p>
        <?php if($error): ?><div class="message error" role="alert"><?= ple($error) ?></div><?php endif; ?>
        <form method="post" autocomplete="on">
          <input type="hidden" name="csrf" value="<?= ple($_SESSION['platform_login_csrf']) ?>">
          <div class="field"><label for="email">E-mail administrativo</label><input id="email" type="email" name="email" autocomplete="username" required autofocus></div>
          <div class="field"><label for="password">Senha</label><div class="password-control"><input id="password" type="password" name="password" autocomplete="current-password" required><button class="toggle-password" type="button" data-password-toggle aria-label="Mostrar senha" aria-pressed="false"><svg class="eye-open" viewBox="0 0 24 24" aria-hidden="true"><path d="M2 12s3.6-7 10-7 10 7 10 7-3.6 7-10 7S2 12 2 12Z"/><circle cx="12" cy="12" r="3"/></svg><svg class="eye-closed" viewBox="0 0 24 24" aria-hidden="true"><path d="m3 3 18 18M10.6 10.6a2 2 0 0 0 2.8 2.8"/><path d="M9.9 5.2A10.8 10.8 0 0 1 12 5c6.4 0 10 7 10 7a15 15 0 0 1-3.2 4.1M6.2 6.2C3.5 8 2 12 2 12s3.6 7 10 7c1.5 0 2.8-.4 4-1"/></svg></button></div></div>
          <button class="submit" type="submit">Acessar painel SaaS →</button>
        </form>
        <div class="switch"><a href="login.php" class="link">← Voltar ao acesso do produtor</a></div>
      </div>
    </section>
  </main>
</body>
</html>

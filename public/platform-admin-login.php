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
<!doctype html><html lang="pt-BR"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>Administração da plataforma — Vértice</title><link rel="preconnect" href="https://fonts.googleapis.com"><link rel="preconnect" href="https://fonts.gstatic.com" crossorigin><link href="https://fonts.googleapis.com/css2?family=DM+Sans:wght@400;500;600;700&family=Manrope:wght@500;600;700;800&display=swap" rel="stylesheet"><link rel="stylesheet" href="assets/css/platform-admin.css"></head><body class="auth-page"><main class="auth-card"><a class="brand" href="login.php"><i>v</i> vértice<span>.</span></a><div class="eyebrow">ADMINISTRAÇÃO DA PLATAFORMA</div><h1>Entrar no painel comercial</h1><p class="muted">Acesso separado das contas dos produtores.</p><?php if($error): ?><div class="alert"><?= ple($error) ?></div><?php endif; ?><form method="post"><input type="hidden" name="csrf" value="<?= ple($_SESSION['platform_login_csrf']) ?>"><label>E-mail administrativo<input type="email" name="email" autocomplete="username" required autofocus></label><label>Senha<input type="password" name="password" autocomplete="current-password" required></label><button class="button primary full" type="submit">Entrar no painel</button></form><a class="back" href="login.php">← Voltar ao acesso de produtor</a></main></body></html>

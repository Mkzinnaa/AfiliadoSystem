<?php
declare(strict_types=1);
require_once __DIR__ . '/../modules/auth.php';
start_app_session();
$pdo=app_db();
if((int)$pdo->query('SELECT COUNT(*) FROM platform_admins')->fetchColumn()>0){header('Location: platform-admin-login.php');exit;}
$setupKey=getenv('VERTICE_SETUP_KEY');
if(!is_string($setupKey)||strlen($setupKey)<32){http_response_code(503);exit('Configuração inicial indisponível. Defina VERTICE_SETUP_KEY com pelo menos 32 caracteres no ambiente privado do servidor e remova essa variável após criar o administrador.');}
if(empty($_SESSION['platform_setup_csrf']))$_SESSION['platform_setup_csrf']=bin2hex(random_bytes(32));
$error='';$name='';$email='';
if($_SERVER['REQUEST_METHOD']==='POST'){
    if(!hash_equals((string)$_SESSION['platform_setup_csrf'],(string)($_POST['csrf']??''))){http_response_code(403);exit('Sessão expirada. Atualize a página.');}
    $name=trim((string)($_POST['name']??''));$email=filter_var(trim((string)($_POST['email']??'')),FILTER_VALIDATE_EMAIL);$password=(string)($_POST['password']??'');$confirm=(string)($_POST['confirm_password']??'');
    if(!hash_equals($setupKey,(string)($_POST['setup_key']??'')))$error='Chave de configuração inválida.';
    elseif($name===''||!$email)$error='Informe seu nome e um e-mail válido.';
    elseif(strlen($password)<14)$error='Use uma senha com pelo menos 14 caracteres.';
    elseif($password!==$confirm)$error='As senhas não conferem.';
    else{try{$pdo->beginTransaction();if((int)$pdo->query('SELECT COUNT(*) FROM platform_admins')->fetchColumn()>0)throw new DomainException('A configuração inicial já foi concluída.');$adminId=new_id('padmin');$stmt=$pdo->prepare('INSERT INTO platform_admins(id,name,email,password_hash) VALUES(?,?,?,?)');$stmt->execute([$adminId,$name,$email,password_hash($password,PASSWORD_DEFAULT)]);$pdo->commit();session_regenerate_id(true);$_SESSION['platform_admin_id']=$adminId;header('Location: platform-admin.php');exit;}catch(DomainException $e){if($pdo->inTransaction())$pdo->rollBack();$error=$e->getMessage();}catch(Throwable $e){if($pdo->inTransaction())$pdo->rollBack();$error='Não foi possível concluir a configuração. Tente novamente.';}}
}
function pse($v):string{return htmlspecialchars((string)$v,ENT_QUOTES,'UTF-8');}
?>
<!doctype html><html lang="pt-BR"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>Configurar administração — Vértice</title><link rel="preconnect" href="https://fonts.googleapis.com"><link rel="preconnect" href="https://fonts.gstatic.com" crossorigin><link href="https://fonts.googleapis.com/css2?family=DM+Sans:wght@400;500;600;700&family=Manrope:wght@500;600;700;800&display=swap" rel="stylesheet"><link rel="stylesheet" href="assets/css/platform-admin.css"></head><body class="auth-page"><main class="auth-card"><a class="brand" href="login.php"><i>v</i> vértice<span>.</span></a><div class="eyebrow">CONFIGURAÇÃO INICIAL</div><h1>Crie seu acesso administrativo</h1><p class="muted">Este acesso controla os clientes e assinaturas da plataforma inteira. Configure uma senha exclusiva.</p><?php if($error): ?><div class="alert"><?= pse($error) ?></div><?php endif; ?><form method="post"><input type="hidden" name="csrf" value="<?= pse($_SESSION['platform_setup_csrf']) ?>"><label>Chave de configuração<input type="password" name="setup_key" autocomplete="off" required></label><label>Seu nome<input name="name" value="<?= pse($name) ?>" autocomplete="name" required></label><label>E-mail administrativo<input type="email" name="email" value="<?= pse($email) ?>" autocomplete="email" required></label><label>Senha exclusiva<input type="password" name="password" minlength="14" autocomplete="new-password" required><small>Pelo menos 14 caracteres. Não use a senha da conta de produtor.</small></label><label>Confirmar senha<input type="password" name="confirm_password" minlength="14" autocomplete="new-password" required></label><button class="button primary full" type="submit">Criar administrador da plataforma</button></form><p class="foot">A configuração só fica disponível enquanto não existir administrador e VERTICE_SETUP_KEY estiver definida no servidor.</p></main></body></html>

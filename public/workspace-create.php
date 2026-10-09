<?php
declare(strict_types=1);
require_once __DIR__.'/../modules/auth.php';
require_login();start_app_session();$user=current_user();$profiles=app_enabled_profiles($user??[]);
$membershipCheck=app_db()->prepare('SELECT 1 FROM memberships WHERE user_id=? LIMIT 1');$membershipCheck->execute([(string)($user['id']??'')]);
if($membershipCheck->fetchColumn()){header('Location: dashboard.php');exit;}
if(empty($_SESSION['workspace_create_csrf']))$_SESSION['workspace_create_csrf']=bin2hex(random_bytes(32));$error='';$name='';
if($_SERVER['REQUEST_METHOD']==='POST'){
    if(!hash_equals((string)$_SESSION['workspace_create_csrf'],(string)($_POST['csrf']??''))){http_response_code(403);exit('Sessão expirada. Atualize a página.');}
    $name=trim((string)($_POST['workspace_name']??''));
    try{$producer=create_workspace_for_existing_user((string)$user['id'],$name);$_SESSION['affiliate_user']=[...$user,...$producer,'profiles'=>array_values(array_unique([...$profiles,'producer','affiliate'])),'active_profile'=>'producer'];session_regenerate_id(true);header('Location: dashboard.php');exit;}catch(DomainException $e){$error=$e->getMessage();}catch(Throwable $e){$error='Não foi possível criar o espaço. Tente novamente.';error_log('[AFFILIEY] workspace create: '.$e->getMessage());}
}
function wc_e(mixed $value):string{return htmlspecialchars((string)$value,ENT_QUOTES,'UTF-8');}
?>
<!doctype html><html lang="pt-BR"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>Configurar ambiente Produtor — AFFILIEY</title><link rel="stylesheet" href="/assets/css/app-theme.css"><link rel="stylesheet" href="/assets/css/brand.css"><link rel="stylesheet" href="/assets/css/profile-environments.css"></head><body class="access-page"><main class="affiliate-access-card"><img src="/brand/affiliey-logo-dark.png" width="190" alt="AFFILIEY"><h1>Configure seu ambiente Produtor</h1><p>Crie um espaço de trabalho nesta conta. Seu acesso de Aluno e os grupos dos quais participa serão preservados.</p><?php if($error): ?><p class="message error" role="alert"><?= wc_e($error) ?></p><?php endif; ?><form method="post"><input type="hidden" name="csrf" value="<?= wc_e($_SESSION['workspace_create_csrf']) ?>"><label>Nome do espaço<input name="workspace_name" maxlength="120" value="<?= wc_e($name) ?>" required></label><button type="submit">Criar meu espaço</button></form><p><a href="affiliate-dashboard.php">Voltar ao ambiente Aluno</a></p></main></body></html>

<?php
declare(strict_types=1);
require_once __DIR__ . '/../modules/platform-admin-security.php';
start_app_session();
$pdo=app_db();$setup=!empty($_GET['setup']);$error='';$admin=null;$secret='';$pendingId='';
if($setup){
    $pendingId=(string)($_SESSION['platform_mfa_setup_admin_id']??'');$issued=(int)($_SESSION['platform_mfa_setup_at']??0);$secret=(string)($_SESSION['platform_mfa_setup_secret']??'');
    if($pendingId===''||$issued<time()-600||$secret===''){unset($_SESSION['platform_mfa_setup_admin_id'],$_SESSION['platform_mfa_setup_secret']);http_response_code(403);exit('A configuração MFA expirou. Reinicie a configuração administrativa.');}
    $q=$pdo->prepare('SELECT id,name,email,mfa_enabled_at FROM platform_admins WHERE id=? AND enabled=1 AND role_key=\'super_admin\'');$q->execute([$pendingId]);$admin=$q->fetch();if(!$admin||$admin['mfa_enabled_at']){http_response_code(403);exit('A configuração MFA não está disponível.');}
}else{
    $pendingId=(string)($_SESSION['platform_mfa_challenge_admin_id']??'');$issued=(int)($_SESSION['platform_mfa_challenge_at']??0);
    if($pendingId===''||$issued<time()-300){unset($_SESSION['platform_mfa_challenge_admin_id'],$_SESSION['platform_mfa_challenge_at'],$_SESSION['platform_mfa_challenge_email']);header('Location: platform-admin-login.php');exit;}
    $q=$pdo->prepare('SELECT id,name,email,enabled,role_key,mfa_secret_ciphertext,mfa_enabled_at FROM platform_admins WHERE id=?');$q->execute([$pendingId]);$admin=$q->fetch();if(!$admin||!(int)$admin['enabled']||$admin['role_key']!=='super_admin'){http_response_code(403);exit('Acesso negado.');}
    if(empty($admin['mfa_enabled_at'])){$_SESSION['platform_mfa_setup_admin_id']=$pendingId;$_SESSION['platform_mfa_setup_at']=time();$_SESSION['platform_mfa_setup_secret']=platform_totp_secret();$_SESSION['platform_mfa_csrf']=bin2hex(random_bytes(32));header('Location: platform-admin-mfa.php?setup=1');exit;}
    $secret=platform_admin_decrypt_totp((string)$admin['mfa_secret_ciphertext'],$pendingId);
}
if(empty($_SESSION['platform_mfa_csrf']))$_SESSION['platform_mfa_csrf']=bin2hex(random_bytes(32));
if($_SERVER['REQUEST_METHOD']==='POST'){
    if(!hash_equals((string)$_SESSION['platform_mfa_csrf'],(string)($_POST['csrf']??''))){http_response_code(403);exit('Sessão expirada. Atualize a página.');}
    $code=preg_replace('/\D/','',(string)($_POST['code']??''))??'';
    $mfaAttemptKey=app_login_attempt_key('platform-admin-mfa',(string)$admin['email']);
    if(app_login_is_locked($mfaAttemptKey)){$error='Muitas tentativas de código. Aguarde 15 minutos antes de tentar novamente.';try{platform_admin_audit('admin.mfa.rate_limited','denied','admin-mfa',$pendingId,[],null,(string)$admin['email']);}catch(Throwable $e){}}
    elseif(!platform_totp_verify($secret,$code)){
        app_login_record_failure($mfaAttemptKey);try{platform_admin_audit('admin.mfa.failed','denied','admin-mfa',$pendingId,[],null,(string)$admin['email']);}catch(Throwable $e){}
        $error='Código inválido ou expirado. Confira o relógio do autenticador e tente novamente.';
    }elseif($setup){
        app_login_clear_failures($mfaAttemptKey);
        $cipher=platform_admin_encrypt_totp($secret,$pendingId);
        $q=$pdo->prepare('UPDATE platform_admins SET mfa_secret_ciphertext=?,mfa_enabled_at=UTC_TIMESTAMP(),last_login=UTC_TIMESTAMP() WHERE id=? AND mfa_enabled_at IS NULL AND enabled=1 AND role_key=\'super_admin\'');$q->execute([$cipher,$pendingId]);
        if($q->rowCount()!==1){http_response_code(409);exit('Não foi possível ativar o MFA. Recarregue e tente novamente.');}
        session_regenerate_id(true);$_SESSION['platform_admin_id']=$pendingId;$_SESSION['platform_admin_last_activity']=time();$_SESSION['platform_request_id']=bin2hex(random_bytes(16));unset($_SESSION['platform_mfa_setup_admin_id'],$_SESSION['platform_mfa_setup_at'],$_SESSION['platform_mfa_setup_secret'],$_SESSION['platform_mfa_challenge_admin_id'],$_SESSION['platform_mfa_challenge_at'],$_SESSION['platform_mfa_challenge_email']);
        platform_admin_audit('admin.mfa.enabled','success','admin',$pendingId);header('Location: platform-admin-master.php');exit;
    }else{
        app_login_clear_failures($mfaAttemptKey);
        session_regenerate_id(true);$_SESSION['platform_admin_id']=$pendingId;$_SESSION['platform_admin_last_activity']=time();$_SESSION['platform_request_id']=bin2hex(random_bytes(16));unset($_SESSION['platform_mfa_challenge_admin_id'],$_SESSION['platform_mfa_challenge_at'],$_SESSION['platform_mfa_challenge_email']);
        $pdo->prepare('UPDATE platform_admins SET last_login=UTC_TIMESTAMP(),last_activity_at=UTC_TIMESTAMP() WHERE id=?')->execute([$pendingId]);
        platform_admin_audit('admin.auth.succeeded','success','admin',$pendingId);header('Location: platform-admin-master.php');exit;
    }
}
$issuer='AFFILIEY';$otpUri='otpauth://totp/'.rawurlencode($issuer.':'.$admin['email']).'?secret='.rawurlencode($secret).'&issuer='.rawurlencode($issuer).'&algorithm=SHA1&digits=6&period=30';
function pmfe($v):string{return htmlspecialchars((string)$v,ENT_QUOTES,'UTF-8');}
?>
<!doctype html><html lang="pt-BR"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title><?= $setup?'Ativar MFA':'Verificação MFA' ?> — AFFILIEY</title><link rel="stylesheet" href="assets/css/platform-admin.css"><link rel="stylesheet" href="/assets/css/brand.css?v=affiliey4"><style>body{min-height:100vh;display:grid;place-items:center;background:#f5f7fb}.mfa-card{width:min(520px,calc(100% - 32px));background:#fff;border:1px solid #e5e7eb;border-radius:24px;padding:clamp(24px,5vw,42px);box-shadow:0 24px 70px #11182718}.mfa-card h1{font-size:1.65rem;margin:.5rem 0}.mfa-card p{color:#5b6472;line-height:1.6}.mfa-secret{display:block;overflow-wrap:anywhere;padding:14px;border:1px dashed #d97706;background:#fff8e8;border-radius:12px;color:#111827;font:600 1.1rem monospace}.mfa-card label{display:block;margin:22px 0 8px}.mfa-card input{width:100%;padding:14px;border:1px solid #cfd5df;border-radius:12px;font-size:1.2rem;letter-spacing:.2em}.mfa-card button{width:100%;margin-top:16px}.mfa-error{padding:12px;border-radius:10px;background:#fff0f0;color:#a4262c}</style></head><body><main class="mfa-card"><div class="eyebrow">AFFILIEY · ADMINISTRAÇÃO MASTER</div><h1><?= $setup?'Ative a autenticação multifator':'Confirme sua identidade' ?></h1><p><?= $setup?'Adicione esta chave no seu aplicativo autenticador (Google Authenticator, Microsoft Authenticator ou 1Password). Ela só será exibida durante esta configuração.':'Digite o código de 6 dígitos do seu aplicativo autenticador.' ?></p><?php if($setup): ?><p>Chave de configuração manual:</p><code class="mfa-secret"><?= pmfe($secret) ?></code><p>URI para importação: <code class="mfa-secret"><?= pmfe($otpUri) ?></code></p><?php endif; ?><?php if($error): ?><div class="mfa-error" role="alert"><?= pmfe($error) ?></div><?php endif; ?><form method="post" autocomplete="off"><input type="hidden" name="csrf" value="<?= pmfe($_SESSION['platform_mfa_csrf']) ?>"><label for="code">Código de autenticação</label><input id="code" name="code" inputmode="numeric" pattern="[0-9 ]{6,8}" maxlength="8" autocomplete="one-time-code" required autofocus placeholder="000000"><button class="button primary full" type="submit"><?= $setup?'Ativar MFA e continuar':'Confirmar e acessar' ?></button></form></main></body></html>

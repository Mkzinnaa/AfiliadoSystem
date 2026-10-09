<?php
declare(strict_types=1);
require_once __DIR__ . '/../modules/community-groups.php';
start_app_session();
$token=(string)($_GET['token']??$_POST['token']??$_SESSION['pending_group_invite']??'');
if(preg_match('/^[a-f0-9]{64}$/',$token))$_SESSION['pending_group_invite']=$token;
$details=community_group_invite_details($token);$error='';$done='';
if(!$details){http_response_code(404);$error='Este convite não está mais disponível. Peça um novo link ao produtor.';}
if($details&&current_user()!==null&&$_SERVER['REQUEST_METHOD']==='POST'){
    if(empty($_SESSION['group_join_csrf'])||!hash_equals((string)$_SESSION['group_join_csrf'],(string)($_POST['csrf']??''))){http_response_code(403);exit('Sessão expirada. Atualize a página.');}
        try{$status=community_group_join($token,(string)current_user()['id']);unset($_SESSION['pending_group_invite']);app_db()->prepare("UPDATE users SET active_profile='affiliate' WHERE id=?")->execute([(string)current_user()['id']]);$_SESSION['affiliate_user']['active_profile']='affiliate';header('Location: student-dashboard.php?view=groups&joined='.rawurlencode($status));exit;}catch(DomainException $e){$error=$e->getMessage();}
}
if(current_user()===null&&$details){header('Location: login.php');exit;}
if(empty($_SESSION['group_join_csrf']))$_SESSION['group_join_csrf']=bin2hex(random_bytes(32));
function gj_e(mixed $v):string{return htmlspecialchars((string)$v,ENT_QUOTES,'UTF-8');}
?>
<!doctype html><html lang="pt-BR"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>Convite para grupo — AFFILIEY</title><link rel="stylesheet" href="/assets/css/app-theme.css"><link rel="stylesheet" href="/assets/css/brand.css"><link rel="stylesheet" href="/assets/css/profile-environments.css?v=3"></head><body class="access-page"><main class="affiliate-access-card"><img src="/brand/affiliey-logo-dark.png" width="190" alt="AFFILIEY"><h1>Convite para grupo</h1><?php if($error): ?><p class="message error" role="alert"><?= gj_e($error) ?></p><?php elseif($details): ?><p><strong><?= gj_e($details['name']) ?></strong> · <?= gj_e($details['producer_name']) ?></p><p><?= nl2br(gj_e($details['description'])) ?></p><p><?= $details['join_policy']==='approval'?'Sua participação será enviada para aprovação.':'Sua entrada será confirmada imediatamente.' ?></p><form method="post"><input type="hidden" name="csrf" value="<?= gj_e($_SESSION['group_join_csrf']) ?>"><input type="hidden" name="token" value="<?= gj_e($token) ?>"><button type="submit">Participar do grupo</button></form><?php else: ?><p>Não foi possível validar este convite.</p><?php endif; ?></main></body></html>

<?php
declare(strict_types=1);
require_once __DIR__ . '/../modules/auth.php';
require_login();
start_app_session();
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST' || !hash_equals((string)($_SESSION['profile_switch_csrf'] ?? ''), (string)($_POST['csrf'] ?? ''))) {
    http_response_code(403); exit('Sessão expirada. Atualize a página e tente novamente.');
}
$profile = (string)($_POST['profile'] ?? '');
if (!in_array($profile, app_enabled_profiles(current_user() ?? []), true)) { http_response_code(403); exit('Este ambiente não está habilitado para sua conta.'); }
$oldProfile=(string)(current_user()['active_profile']??'producer');
$_SESSION['affiliate_user']['active_profile'] = $profile;
if($oldProfile!==$profile) app_audit_record((string)current_user()['id'],$profile==='producer'?(string)current_user()['tenant_id']:null,'profile.switched','profile',$profile,['from'=>$oldProfile]);
header('Location: ' . ($profile === 'affiliate' ? 'affiliate-dashboard.php' : 'dashboard.php'));
exit;

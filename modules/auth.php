<?php
declare(strict_types=1);

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/tenancy.php';
require_once __DIR__ . '/database.php';
require_once __DIR__ . '/permissions.php';

function start_app_session(): void
{
    if (session_status() !== PHP_SESSION_ACTIVE) {
        $host = strtolower((string)parse_url('http://' . ($_SERVER['HTTP_HOST'] ?? ''), PHP_URL_HOST));
        $isLocal = in_array($host, ['localhost', '127.0.0.1', '::1'], true);
        $secureSetting = getenv('VERTICE_COOKIE_SECURE');
        $secureCookie = $secureSetting !== false
            ? filter_var($secureSetting, FILTER_VALIDATE_BOOLEAN)
            : (!$isLocal || (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') || (int)($_SERVER['SERVER_PORT'] ?? 0) === 443);
        ini_set('session.use_strict_mode', '1');
        ini_set('session.use_only_cookies', '1');
        ini_set('session.use_trans_sid', '0');
        session_set_cookie_params([
            'httponly' => true,
            'secure' => $secureCookie,
            'samesite' => 'Lax',
            'path' => '/',
        ]);
        header('X-Content-Type-Options: nosniff');
        header('X-Frame-Options: DENY');
        header('Referrer-Policy: no-referrer');
        header('Cache-Control: no-store, private');
        if ((!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') || (int)($_SERVER['SERVER_PORT'] ?? 0) === 443) {
            header('Strict-Transport-Security: max-age=31536000');
        }
        session_start();
    }
}

function app_login_attempt_key(string $surface, string $email): string
{
    return hash('sha256', strtolower(trim($surface)) . "\n" . strtolower(trim($email)) . "\n" . (string)($_SERVER['REMOTE_ADDR'] ?? 'unknown'));
}

function app_login_is_locked(string $key): bool
{
    $query = app_db()->prepare('SELECT locked_until>UTC_TIMESTAMP() FROM api_login_attempts WHERE attempt_key=?');
    $query->execute([$key]);
    return (bool)$query->fetchColumn();
}

function app_login_record_failure(string $key): void
{
    app_db()->prepare('INSERT INTO api_login_attempts(attempt_key,attempts,window_started_at) VALUES(?,1,UTC_TIMESTAMP()) ON DUPLICATE KEY UPDATE locked_until=IF(window_started_at<DATE_SUB(UTC_TIMESTAMP(),INTERVAL 15 MINUTE),NULL,IF(attempts>=9,DATE_ADD(UTC_TIMESTAMP(),INTERVAL 15 MINUTE),locked_until)),attempts=IF(window_started_at<DATE_SUB(UTC_TIMESTAMP(),INTERVAL 15 MINUTE),1,attempts+1),window_started_at=IF(window_started_at<DATE_SUB(UTC_TIMESTAMP(),INTERVAL 15 MINUTE),UTC_TIMESTAMP(),window_started_at)')->execute([$key]);
    if (random_int(1, 100) === 1) {
        app_db()->exec('DELETE FROM api_login_attempts WHERE window_started_at<DATE_SUB(UTC_TIMESTAMP(),INTERVAL 1 DAY) AND (locked_until IS NULL OR locked_until<UTC_TIMESTAMP()) LIMIT 500');
    }
}

function app_login_clear_failures(string $key): void
{
    app_db()->prepare('DELETE FROM api_login_attempts WHERE attempt_key=?')->execute([$key]);
}

function current_user(): ?array
{
    if (isset($GLOBALS['VERTICE_API_USER']) && is_array($GLOBALS['VERTICE_API_USER'])) {
        return $GLOBALS['VERTICE_API_USER'];
    }
    start_app_session();
    return $_SESSION['affiliate_user'] ?? null;
}

function attempt_login(string $email, string $password): bool
{
    $user = authenticate_user($email, $password);
    if ($user === null) return false;
    start_app_session();
    session_regenerate_id(true);
    $_SESSION['affiliate_user'] = $user;
    return true;
}

function require_login(): void
{
    $user = current_user();
    if ($user === null) {
        header('Location: login.php');
        exit;
    }
    if (!isset($GLOBALS['VERTICE_API_USER'])) {
        $user = app_refresh_session_membership($user);
    }
    $profiles = app_enabled_profiles($user);
    $script = strtolower(basename((string)($_SERVER['SCRIPT_NAME'] ?? '')));
    $requiredProfile = (str_starts_with($script, 'affiliate-') && $script !== 'affiliate-apply.php') || $script === 'student-dashboard.php' ? 'affiliate' : (app_permission_module_for_request() !== null ? 'producer' : null);
    if ($requiredProfile !== null && !in_array($requiredProfile, $profiles, true)) {
        http_response_code(403);
        exit('Este ambiente não está habilitado para sua conta.');
    }
    $active = (string)($user['active_profile'] ?? 'affiliate');
    if ($requiredProfile !== null && $active !== $requiredProfile) {
        header('Location: ' . ($active === 'affiliate' ? 'affiliate-dashboard.php' : 'dashboard.php'));
        exit;
    }
    if ($requiredProfile === 'producer' && empty($user['tenant_id'])) {
        header('Location: workspace-create.php');
        exit;
    }
    $module = app_permission_module_for_request();
    if ($module !== null) {
        $action = app_permission_action_for_request($module);
        if (!app_user_can($module, $action, $user)) {
            http_response_code(403);
            exit('Seu acesso não permite realizar esta ação. Peça ao proprietário do espaço para ajustar suas permissões.');
        }
    }
}

function app_refresh_session_membership(array $user): array
{
    if (empty($user['id']) || session_status() !== PHP_SESSION_ACTIVE) return $user;
    $tenantId = trim((string)($user['tenant_id'] ?? ''));
    if ($tenantId !== '') {
        $query = app_db()->prepare('SELECT m.role,t.name AS tenant_name FROM memberships m JOIN tenants t ON t.id=m.tenant_id WHERE m.tenant_id=? AND m.user_id=? LIMIT 1');
        $query->execute([$tenantId,(string)$user['id']]);
        $membership = $query->fetch();
        if ($membership) {
            $_SESSION['affiliate_user']['role'] = (string)$membership['role'];
            $_SESSION['affiliate_user']['tenant_name'] = (string)$membership['tenant_name'];
        } else {
            unset($_SESSION['affiliate_user']['tenant_id'],$_SESSION['affiliate_user']['tenant_name']);
            $_SESSION['affiliate_user']['role'] = 'student';
        }
    }
    $savedProfile=app_db()->prepare('SELECT active_profile FROM users WHERE id=? LIMIT 1');
    $savedProfile->execute([(string)$user['id']]);
    $persisted=(string)($savedProfile->fetchColumn()?:'affiliate');
    $_SESSION['affiliate_user']['active_profile']=$persisted;
    $user = $_SESSION['affiliate_user'];
    $profiles = app_enabled_profiles($user);
    if (!in_array((string)($user['active_profile'] ?? ''),$profiles,true) && $profiles) {
        $_SESSION['affiliate_user']['active_profile'] = $profiles[0];
        $user['active_profile'] = $profiles[0];
    }
    return $user;
}

function app_enabled_profiles(array $user): array
{
    $profiles = [];
    if (!empty($user['id'])) {
        $userId=(string)$user['id'];
        $stored=app_user_profiles($userId);
        // A profile enables an environment; tenant/group relationships below
        // continue to authorize access to actual data and producer features.
        if (in_array('producer',$stored,true)) $profiles[]='producer';
        if(in_array('affiliate',$stored,true)){
            $linked=app_db()->prepare("SELECT 1 FROM affiliate_account_links l JOIN affiliates a ON a.tenant_id=l.tenant_id AND a.id=l.affiliate_id WHERE l.user_id=? AND l.status='active' AND a.status='active' LIMIT 1");
            $linked->execute([$userId]);if($linked->fetchColumn() || in_array('producer',$stored,true))$profiles[]='affiliate';
        }
        if (isset($GLOBALS['VERTICE_API_USER']['profile'])) $profiles[] = (string)$GLOBALS['VERTICE_API_USER']['profile'];
    }
    return array_values(array_unique(array_intersect($profiles, ['producer','affiliate'])));
}

function app_profile_switcher(): string
{
    $user = current_user();
    if (!$user) return '';
    $profiles = app_enabled_profiles($user);
    if (!$profiles) return '';
    start_app_session();
    if (empty($_SESSION['profile_switch_csrf'])) $_SESSION['profile_switch_csrf'] = bin2hex(random_bytes(32));
    $active = (string)($user['active_profile'] ?? 'affiliate');
    // Keep the historical database key; the user-facing environment is Aluno.
    $label = $active === 'affiliate' ? 'Aluno' : 'Produtor';
    $html = '<details class="profile-switcher"><summary>Alternar ambiente: <strong>' . strtoupper($label) . '</strong></summary><div class="profile-switcher-menu">';
    foreach (['producer'=>'Entrar como Produtor','affiliate'=>'Entrar como Aluno'] as $profile=>$text) {
        if (!in_array($profile,$profiles,true)) continue;
        $html .= '<form method="post" action="profile-switch.php"><input type="hidden" name="csrf" value="' . htmlspecialchars((string)$_SESSION['profile_switch_csrf'],ENT_QUOTES,'UTF-8') . '"><input type="hidden" name="profile" value="' . $profile . '"><button type="submit"' . ($active===$profile?' aria-current="true"':'') . '>' . $text . '</button></form>';
    }
    if(!in_array('affiliate',$profiles,true))$html.='<a class="profile-switcher-action" href="affiliate-access.php">Entrar como ALUNO · Tenho um convite</a>';
    if(!in_array('producer',$profiles,true))$html.='<a class="profile-switcher-action" href="workspace-create.php">Criar meu espaço de produtor</a>';
    return $html . '</div></details>';
}

function app_header_account_tools(): string
{
    $user=current_user();
    if(!$user)return '';
    $profiles=app_enabled_profiles($user);
    $active=(string)($user['active_profile']??'affiliate');
    $isStudent=$active==='affiliate';
    $amount=0.0;$target=0.0;$metric='revenue';$goalTitle='';$periodLabel='Resultados deste mês';
    try {
        if($isStudent){
            $q=app_db()->prepare("SELECT COALESCE(SUM(o.commission_cents),0) FROM sales_orders o JOIN affiliate_account_links l ON l.tenant_id=o.tenant_id AND l.affiliate_id=o.affiliate_id AND l.user_id=? AND l.status='active' JOIN affiliates a ON a.tenant_id=l.tenant_id AND a.id=l.affiliate_id AND a.status='active' JOIN integration_connections c ON c.tenant_id=o.tenant_id AND c.id=o.connection_id AND c.status='active' JOIN integration_products p ON p.tenant_id=o.tenant_id AND p.connection_id=o.connection_id AND p.external_product_id=o.external_product_id AND p.status='active' WHERE o.status='approved' AND o.commission_source='platform' AND o.sold_at>=DATE_FORMAT(UTC_DATE(),'%Y-%m-01') AND o.sold_at<DATE_ADD(DATE_FORMAT(UTC_DATE(),'%Y-%m-01'),INTERVAL 1 MONTH)");
            $q->execute([(string)$user['id']]);$amount=(int)$q->fetchColumn()/100;$periodLabel='Comissões confirmadas neste mês';
        } elseif(!empty($user['tenant_id'])) {
            require_once __DIR__.'/affiliates.php';
            require_once __DIR__.'/campaigns.php';
            $affiliates=affiliate_read_all();$today=new DateTimeImmutable('today');
            foreach(campaigns_read_all() as $campaign){
                if(empty($campaign['active'])||(string)$campaign['start']>$today->format('Y-m-d')||(string)$campaign['end']<$today->format('Y-m-d'))continue;
                $metric=(string)($campaign['metric']??'revenue');$amount=campaign_progress($campaign,$affiliates);$target=(float)($campaign['target']??0);$goalTitle=(string)$campaign['title'];$periodLabel='Meta ativa · '.$goalTitle;break;
            }
            if($target<=0){
                $q=app_db()->prepare("SELECT COALESCE(SUM(amount_cents),0)/100 FROM sales_orders WHERE tenant_id=? AND status='approved' AND sold_at>=DATE_FORMAT(UTC_DATE(),'%Y-%m-01') AND sold_at<DATE_ADD(DATE_FORMAT(UTC_DATE(),'%Y-%m-01'),INTERVAL 1 MONTH)");$q->execute([(string)$user['tenant_id']]);$amount=(float)$q->fetchColumn();$periodLabel='Vendas aprovadas neste mês';
            }
        }
    } catch(Throwable $error) {
        error_log('[AFFILIEY] account header metrics unavailable: '.$error->getMessage());
    }
    $percent=$target>0?max(0,min(100,$amount/$target*100)):0;
    $format=static function(float $value)use($metric):string{return match($metric){'orders'=>number_format($value,0,',','.').' vendas','new_customers'=>number_format($value,0,',','.').' clientes','conversion'=>number_format($value,2,',','.').'%',default=>'R$ '.number_format($value,2,',','.')};};
    $name=trim((string)($user['name']??'Conta AFFILIEY'));$initials='';foreach(array_slice(preg_split('/\s+/u',$name)?:[],0,2) as $part){$chars=preg_split('//u',$part,-1,PREG_SPLIT_NO_EMPTY)?:[];$initial=$chars[0]??'';$initials.=function_exists('mb_strtoupper')?mb_strtoupper($initial,'UTF-8'):strtoupper($initial);}
    start_app_session();if(empty($_SESSION['profile_switch_csrf']))$_SESSION['profile_switch_csrf']=bin2hex(random_bytes(32));
    $html='<div class="affiliey-account-tools"><section class="affiliey-earnings" aria-label="Progresso de ganhos"><div class="affiliey-earnings-copy"><span>'.htmlspecialchars($periodLabel,ENT_QUOTES,'UTF-8').'</span><strong>'.$format($amount).($target>0?' <i>/ '.$format($target).'</i>':'').'</strong></div><div class="affiliey-earnings-track" role="progressbar" aria-label="Progresso da meta" aria-valuemin="0" aria-valuemax="100" aria-valuenow="'.(int)round($percent).'"><span style="width:'.number_format($percent,1,'.','').'%"></span></div><small>'.($target>0?number_format($percent,1,',','.').'% da meta':'Acompanhe seus resultados reais').'</small></section>';
    $email=htmlspecialchars((string)($user['email']??''),ENT_QUOTES,'UTF-8');
    $html.='<details class="affiliey-account-menu"><summary aria-label="Abrir menu da conta"><span class="affiliey-avatar">'.htmlspecialchars($initials?:'AF',ENT_QUOTES,'UTF-8').'</span><span class="affiliey-account-name">'.htmlspecialchars($name,ENT_QUOTES,'UTF-8').'<small>'.($isStudent?'ALUNO':'PRODUTOR').'</small></span><span class="affiliey-account-chevron" aria-hidden="true">⌄</span></summary><div class="affiliey-account-popover"><div class="affiliey-account-identity"><strong>'.$email.'</strong><span>Conta AFFILIEY</span></div><div class="affiliey-menu-label">Alternar ambiente</div>';
    foreach(['producer'=>'Produtor','affiliate'=>'Aluno'] as $profile=>$label){if(!in_array($profile,$profiles,true))continue;$html.='<form method="post" action="profile-switch.php"><input type="hidden" name="csrf" value="'.htmlspecialchars((string)$_SESSION['profile_switch_csrf'],ENT_QUOTES,'UTF-8').'"><input type="hidden" name="profile" value="'.$profile.'"><button type="submit"'.($active===$profile?' aria-current="true"':'').'><span>'.($profile==='producer'?'✦':'◈').'</span> Mudar para '.htmlspecialchars($label,ENT_QUOTES,'UTF-8').($active===$profile?'<b>Atual</b>':'').'</button></form>';}
    if($isStudent||app_user_can('announcements','view',$user)){$noticeHref=$isStudent?'student-dashboard.php?view=announcements':'announcements.php';$html.='<a class="affiliey-account-link" href="'.$noticeHref.'"><span>♧</span> Comunicados e notificações</a>';}
    $html.='<a class="affiliey-account-link" href="login.php?logout=1"><span>↪</span> Sair da conta</a></div></details></div>';
    return $html;
}

function logout_user(): void
{
    start_app_session();
    $params = session_get_cookie_params();
    $_SESSION = [];
    if (ini_get('session.use_cookies')) {
        setcookie(session_name(), '', [
            'expires' => time() - 42000,
            'path' => $params['path'] ?: '/',
            'domain' => $params['domain'],
            'secure' => (bool)$params['secure'],
            'httponly' => (bool)$params['httponly'],
            'samesite' => $params['samesite'] ?: 'Lax',
        ]);
    }
    session_destroy();
}

function require_role(array $allowedRoles): void
{
    // Keep legacy call sites compatible; require_login enforces the current route/action permission.
    require_login();
}

function can_manage_workspace(?string $module = null, string $action = 'create'): bool
{
    $module ??= app_permission_module_for_request();
    if ($module === null) return in_array((string)(current_user()['role'] ?? 'viewer'), ['owner','admin','manager'], true);
    return app_user_can($module, $action);
}

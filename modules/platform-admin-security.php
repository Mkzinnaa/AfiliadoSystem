<?php
declare(strict_types=1);
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/integrations.php';

function platform_admin_audit(string $event, string $result = 'success', string $resource = '', string $resourceId = '', array $details = [], ?string $adminId = null, string $actorEmail = ''): void
{
    $pdo = app_db();
    $adminId ??= (string)($_SESSION['platform_admin_id'] ?? '');
    if ($actorEmail === '' && $adminId !== '') {
        $q = $pdo->prepare('SELECT email FROM platform_admins WHERE id=?'); $q->execute([$adminId]);
        $actorEmail = (string)($q->fetchColumn() ?: '');
    }
    $safe = json_encode($details, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);
    if ($safe === false) $safe = '{}';
    $ip = (string)($_SERVER['REMOTE_ADDR'] ?? '');
    $ua = substr((string)($_SERVER['HTTP_USER_AGENT'] ?? ''), 0, 512);
    $requestId = substr((string)($_SERVER['HTTP_X_REQUEST_ID'] ?? ($_SESSION['platform_request_id'] ?? '')),0,64);
    $event = substr($event,0,80); $result = substr($result,0,24); $resource = substr($resource,0,60); $resourceId = substr($resourceId,0,100); $actorEmail = substr($actorEmail,0,190);
    $key = hash_hkdf('sha256', integration_encryption_key(), 32, 'AFFILIEY master audit chain v1');
    $locked = (int)$pdo->query("SELECT GET_LOCK('affiliey_platform_admin_audit', 5)")->fetchColumn();
    if ($locked !== 1) throw new RuntimeException('Não foi possível bloquear a trilha de auditoria administrativa.');
    $ownsTransaction = !$pdo->inTransaction();
    try {
        if ($ownsTransaction) $pdo->beginTransaction();
        $last = $pdo->query('SELECT event_hash FROM platform_admin_audit ORDER BY id DESC LIMIT 1 FOR UPDATE')->fetchColumn();
        $previous = is_string($last) ? $last : str_repeat('0', 64);
        $createdAt = gmdate('Y-m-d H:i:s');
        $ipHash = $ip !== '' ? hash_hmac('sha256',$ip,$key) : '';
        $uaHash = $ua !== '' ? hash_hmac('sha256',$ua,$key) : '';
        $canonical = json_encode([$previous, $createdAt, $adminId, $actorEmail, $event, $result, $resource, $resourceId, $safe, $requestId, $ipHash, $uaHash], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);
        $hash = hash_hmac('sha256', (string)$canonical, $key);
        $stmt = $pdo->prepare('INSERT INTO platform_admin_audit(admin_id,actor_email,event_type,result,resource_type,resource_id,details,request_id,ip_hash,user_agent_hash,previous_hash,event_hash,created_at) VALUES(?,?,?,?,?,?,?,?,?,?,?,?,?)');
        $stmt->execute([$adminId !== '' ? $adminId : null, $actorEmail, $event, $result, $resource, $resourceId, $safe, $requestId, $ipHash, $uaHash, $previous, $hash, $createdAt]);
        if ($ownsTransaction) $pdo->commit();
    } catch (Throwable $e) {
        if ($ownsTransaction && $pdo->inTransaction()) $pdo->rollBack();
        throw $e;
    } finally {
        if ($ownsTransaction) $pdo->query("SELECT RELEASE_LOCK('affiliey_platform_admin_audit')");
    }
}

function platform_admin_audit_release_lock(): void
{
    app_db()->query("SELECT RELEASE_LOCK('affiliey_platform_admin_audit')");
}

function platform_admin_audit_verify_chain(int $maxRows = 100000): array
{
    $maxRows = max(1,min(100000,$maxRows));$key=hash_hkdf('sha256',integration_encryption_key(),32,'AFFILIEY master audit chain v1');
    $q=app_db()->query('SELECT admin_id,actor_email,event_type,result,resource_type,resource_id,details,request_id,ip_hash,user_agent_hash,previous_hash,event_hash,created_at FROM platform_admin_audit ORDER BY id ASC LIMIT '.(int)$maxRows);
    $previous=str_repeat('0',64);$checked=0;
    while($row=$q->fetch()){
        $expectedPrevious=$previous;
        if(!hash_equals($expectedPrevious,(string)$row['previous_hash']))return ['valid'=>false,'checked'=>$checked,'reason'=>'previous_hash_mismatch'];
        $canonical=json_encode([$expectedPrevious,(string)$row['created_at'],(string)($row['admin_id']??''),(string)$row['actor_email'],(string)$row['event_type'],(string)$row['result'],(string)$row['resource_type'],(string)$row['resource_id'],(string)$row['details'],(string)$row['request_id'],(string)$row['ip_hash'],(string)$row['user_agent_hash']],JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE|JSON_INVALID_UTF8_SUBSTITUTE);
        $calculated=hash_hmac('sha256',(string)$canonical,$key);if(!hash_equals($calculated,(string)$row['event_hash']))return ['valid'=>false,'checked'=>$checked,'reason'=>'event_hash_mismatch'];
        $previous=(string)$row['event_hash'];$checked++;
    }
    $total=(int)app_db()->query('SELECT COUNT(*) FROM platform_admin_audit')->fetchColumn();
    return ['valid'=>true,'checked'=>$checked,'truncated'=>$total>$checked,'reason'=>$total>$checked?'verification_limit_reached':'verified'];
}

function platform_admin_require(): array
{
    start_app_session();
    $id = (string)($_SESSION['platform_admin_id'] ?? '');
    $admin = null;
    if ($id !== '') {
        $q = app_db()->prepare("SELECT id,name,email,role_key,enabled,mfa_enabled_at,last_activity_at FROM platform_admins WHERE id=? LIMIT 1");
        $q->execute([$id]); $admin = $q->fetch() ?: null;
    }
    $now = time(); $last = (int)($_SESSION['platform_admin_last_activity'] ?? 0);
    if (!$admin || !(int)$admin['enabled'] || $admin['role_key'] !== 'super_admin' || empty($admin['mfa_enabled_at']) || ($last > 0 && $now - $last > 1200)) {
        if ($id !== '') {
            try { platform_admin_audit('admin.session.denied','denied','admin',$id,['reason'=>!$admin?'missing_admin':(($last > 0 && $now - $last > 1200)?'idle_timeout':'role_or_mfa')]); } catch (Throwable $e) {}
        } else {
            try { platform_admin_audit('admin.route.denied','denied','master-console',(string)basename((string)($_SERVER['SCRIPT_NAME']??'')),['reason'=>'authentication_required']); } catch (Throwable $e) {}
        }
        unset($_SESSION['platform_admin_id'], $_SESSION['platform_admin_last_activity']);
        session_regenerate_id(true);
        header('Location: platform-admin-login.php'); exit;
    }
    $_SESSION['platform_admin_last_activity'] = $now;
    if ($last === 0 || $now - $last > 300) app_db()->prepare('UPDATE platform_admins SET last_activity_at=UTC_TIMESTAMP() WHERE id=?')->execute([$id]);
    return $admin;
}

function platform_totp_secret(int $bytes = 20): string
{
    $raw = random_bytes($bytes); $alphabet = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567'; $bits = '';
    foreach (str_split($raw) as $c) $bits .= str_pad(decbin(ord($c)), 8, '0', STR_PAD_LEFT);
    $out = ''; foreach (str_split($bits, 5) as $chunk) $out .= $alphabet[bindec(str_pad($chunk,5,'0'))];
    return $out;
}

function platform_totp_decode(string $secret): string
{
    $alphabet = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567'; $bits = '';
    foreach (str_split(strtoupper(preg_replace('/[^A-Z2-7]/i','',$secret) ?? '')) as $c) { $v = strpos($alphabet,$c); if ($v === false) throw new RuntimeException('Chave MFA inválida.'); $bits .= str_pad(decbin($v),5,'0',STR_PAD_LEFT); }
    $raw = ''; foreach (str_split($bits,8) as $byte) if (strlen($byte)===8) $raw .= chr(bindec($byte));
    return $raw;
}

function platform_totp_verify(string $secret, string $code): bool
{
    if (!preg_match('/^\d{6}$/', $code)) return false;
    $key = platform_totp_decode($secret); $counter = (int)floor(time()/30);
    for ($offset=-1; $offset<=1; $offset++) {
        $value = $counter + $offset; $hash = hash_hmac('sha1', pack('N2',0,$value), $key, true); $index = ord($hash[19]) & 0x0f;
        $number = ((ord($hash[$index]) & 0x7f) << 24) | ((ord($hash[$index+1]) & 0xff) << 16) | ((ord($hash[$index+2]) & 0xff) << 8) | (ord($hash[$index+3]) & 0xff);
        if (hash_equals(str_pad((string)($number % 1000000),6,'0',STR_PAD_LEFT),$code)) return true;
    }
    return false;
}

function platform_admin_encrypt_totp(string $secret, string $adminId): string
{
    return integration_encrypt_secret($secret, 'platform-admin-totp:' . $adminId);
}

function platform_admin_decrypt_totp(string $encoded, string $adminId): string
{
    return integration_decrypt_secret($encoded, 'platform-admin-totp:' . $adminId);
}

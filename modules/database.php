<?php
declare(strict_types=1);
require_once __DIR__ . '/config.php';

function app_db(): PDO
{
    static $pdo = null;
    if ($pdo instanceof PDO) return $pdo;
    if (!in_array('sqlite', PDO::getAvailableDrivers(), true)) throw new RuntimeException('SQLite não está habilitado no PHP.');
    // Keep the database outside the web root. The PHP development server does not
    // honor .htaccess, so a SQLite file under /storage could otherwise be downloaded.
    $storage = __DIR__ . '/../.runtime/app-data';
    if (!is_dir($storage)) mkdir($storage, 0775, true);
    $pdo = new PDO('sqlite:' . $storage . '/app.sqlite', null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]);
    $pdo->exec('PRAGMA foreign_keys = ON');
    $pdo->exec('PRAGMA journal_mode = WAL');
    $pdo->exec("CREATE TABLE IF NOT EXISTS tenants (id TEXT PRIMARY KEY, name TEXT NOT NULL, slug TEXT NOT NULL UNIQUE, created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP)");
    $pdo->exec("CREATE TABLE IF NOT EXISTS users (id TEXT PRIMARY KEY, name TEXT NOT NULL, email TEXT NOT NULL COLLATE NOCASE UNIQUE, password_hash TEXT NOT NULL, created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP)");
    $pdo->exec("CREATE TABLE IF NOT EXISTS memberships (tenant_id TEXT NOT NULL REFERENCES tenants(id) ON DELETE CASCADE, user_id TEXT NOT NULL REFERENCES users(id) ON DELETE CASCADE, role TEXT NOT NULL CHECK(role IN ('owner','admin','manager','viewer')), created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP, PRIMARY KEY(tenant_id,user_id))");
    $pdo->exec("CREATE TABLE IF NOT EXISTS invitations (id TEXT PRIMARY KEY, tenant_id TEXT NOT NULL REFERENCES tenants(id) ON DELETE CASCADE, email TEXT NOT NULL COLLATE NOCASE, role TEXT NOT NULL CHECK(role IN ('admin','manager','viewer')), token_hash TEXT NOT NULL UNIQUE, expires_at TEXT NOT NULL, accepted_at TEXT, created_by TEXT NOT NULL REFERENCES users(id), created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP)");
    $pdo->exec("CREATE INDEX IF NOT EXISTS invitations_tenant_email_idx ON invitations(tenant_id,email)");
    $pdo->exec("CREATE TABLE IF NOT EXISTS affiliates (id TEXT NOT NULL, tenant_id TEXT NOT NULL REFERENCES tenants(id) ON DELETE CASCADE, name TEXT NOT NULL, email TEXT NOT NULL COLLATE NOCASE, affiliate_group TEXT NOT NULL, commission REAL NOT NULL DEFAULT 20, status TEXT NOT NULL DEFAULT 'active', sales REAL NOT NULL DEFAULT 0, orders INTEGER NOT NULL DEFAULT 0, code TEXT NOT NULL, created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP, PRIMARY KEY(tenant_id,id), UNIQUE(tenant_id,email), UNIQUE(tenant_id,code))");
    $pdo->exec("CREATE TABLE IF NOT EXISTS campaigns (id TEXT NOT NULL, tenant_id TEXT NOT NULL REFERENCES tenants(id) ON DELETE CASCADE, title TEXT NOT NULL, metric TEXT NOT NULL CHECK(metric IN ('revenue','orders')), target REAL NOT NULL, affiliate_group TEXT NOT NULL DEFAULT 'all', reward TEXT NOT NULL DEFAULT '', start_date TEXT NOT NULL, end_date TEXT NOT NULL, active INTEGER NOT NULL DEFAULT 1, created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP, PRIMARY KEY(tenant_id,id))");
    $pdo->exec("CREATE TABLE IF NOT EXISTS platform_admins (id TEXT PRIMARY KEY, name TEXT NOT NULL, email TEXT NOT NULL COLLATE NOCASE UNIQUE, password_hash TEXT NOT NULL, created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP, last_login TEXT)");
    $pdo->exec("CREATE TABLE IF NOT EXISTS subscription_plans (code TEXT PRIMARY KEY, name TEXT NOT NULL, monthly_price_cents INTEGER NOT NULL DEFAULT 0 CHECK(monthly_price_cents >= 0), active INTEGER NOT NULL DEFAULT 1, created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP)");
    $pdo->exec("CREATE TABLE IF NOT EXISTS subscriptions (tenant_id TEXT PRIMARY KEY REFERENCES tenants(id) ON DELETE CASCADE, plan_code TEXT NOT NULL REFERENCES subscription_plans(code), status TEXT NOT NULL CHECK(status IN ('trial','active','past_due','canceled')), started_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP, current_period_end TEXT, updated_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP, notes TEXT NOT NULL DEFAULT '')");
    $pdo->exec("CREATE TABLE IF NOT EXISTS subscription_events (id TEXT PRIMARY KEY, tenant_id TEXT NOT NULL REFERENCES tenants(id) ON DELETE CASCADE, admin_id TEXT NOT NULL REFERENCES platform_admins(id), event_type TEXT NOT NULL, details TEXT NOT NULL, created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP)");
    $pdo->exec("CREATE TABLE IF NOT EXISTS integration_connections (id TEXT PRIMARY KEY, tenant_id TEXT NOT NULL REFERENCES tenants(id) ON DELETE CASCADE, name TEXT NOT NULL, platform TEXT NOT NULL DEFAULT 'kiwify', token_hash TEXT NOT NULL, secret_ciphertext TEXT NOT NULL DEFAULT '', status TEXT NOT NULL DEFAULT 'active' CHECK(status IN ('active','paused')), last_event_at TEXT, created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP, UNIQUE(tenant_id,name))");
    $connectionColumns=$pdo->query('PRAGMA table_info(integration_connections)')->fetchAll();
    if(!in_array('secret_ciphertext',array_column($connectionColumns,'name'),true))$pdo->exec("ALTER TABLE integration_connections ADD COLUMN secret_ciphertext TEXT NOT NULL DEFAULT ''");
    $pdo->exec("CREATE TABLE IF NOT EXISTS sales_orders (id TEXT PRIMARY KEY, tenant_id TEXT NOT NULL REFERENCES tenants(id) ON DELETE CASCADE, connection_id TEXT NOT NULL REFERENCES integration_connections(id) ON DELETE CASCADE, external_order_id TEXT NOT NULL, affiliate_id TEXT, affiliate_code TEXT NOT NULL DEFAULT '', amount_cents INTEGER NOT NULL CHECK(amount_cents >= 0), currency TEXT NOT NULL DEFAULT 'BRL', status TEXT NOT NULL CHECK(status IN ('approved','refunded','canceled')), sold_at TEXT NOT NULL, updated_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP, UNIQUE(connection_id,external_order_id))");
    $pdo->exec("CREATE INDEX IF NOT EXISTS sales_orders_tenant_status_idx ON sales_orders(tenant_id,status,sold_at)");
    $pdo->exec("CREATE TABLE IF NOT EXISTS integration_events (id TEXT PRIMARY KEY, tenant_id TEXT NOT NULL REFERENCES tenants(id) ON DELETE CASCADE, connection_id TEXT NOT NULL REFERENCES integration_connections(id) ON DELETE CASCADE, external_event_id TEXT NOT NULL, event_type TEXT NOT NULL, result TEXT NOT NULL, message TEXT NOT NULL DEFAULT '', created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP, UNIQUE(connection_id,external_event_id))");
    $pdo->exec("INSERT OR IGNORE INTO subscription_plans(code,name,monthly_price_cents,active) VALUES('starter','Inicial',0,1),('growth','Crescimento',0,1),('scale','Escala',0,1)");
    $existing = $pdo->prepare('SELECT id FROM tenants WHERE id = ?');
    $existing->execute([DEMO_USER['tenant_id']]);
    if (demo_enabled() && !$existing->fetch()) {
        $pdo->beginTransaction();
        try {
            $slug = 'novavida-demo';
            $pdo->prepare('INSERT INTO tenants(id,name,slug) VALUES(?,?,?)')->execute([DEMO_USER['tenant_id'], 'NovaVida Store', $slug]);
            $userId = 'user-demo-owner';
            $pdo->prepare('INSERT INTO users(id,name,email,password_hash) VALUES(?,?,?,?)')->execute([$userId, DEMO_USER['name'], DEMO_USER['email'], password_hash(DEMO_USER['password'], PASSWORD_DEFAULT)]);
            $pdo->prepare('INSERT INTO memberships(tenant_id,user_id,role) VALUES(?,?,?)')->execute([DEMO_USER['tenant_id'], $userId, 'owner']);
            $pdo->commit();
        } catch (Throwable $error) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            throw $error;
        }
    }
    $trialEnd = (new DateTimeImmutable('+14 days'))->format('Y-m-d');
    $pdo->prepare("INSERT OR IGNORE INTO subscriptions(tenant_id,plan_code,status,current_period_end) SELECT id,'starter','trial',? FROM tenants")->execute([$trialEnd]);
    return $pdo;
}

function new_id(string $prefix): string { return $prefix . '-' . bin2hex(random_bytes(12)); }

function create_workspace(string $ownerName, string $workspaceName, string $email, string $password): array
{
    $pdo = app_db(); $pdo->beginTransaction();
    try {
        $exists = $pdo->prepare('SELECT id FROM users WHERE email = ?'); $exists->execute([$email]);
        if ($exists->fetch()) throw new DomainException('Este e-mail já tem uma conta. Entre ou aceite um convite de equipe.');
        $tenantId = new_id('org'); $userId = new_id('usr');
        $base = strtolower(trim(preg_replace('/[^a-zA-Z0-9]+/', '-', iconv('UTF-8','ASCII//TRANSLIT//IGNORE',$workspaceName) ?: $workspaceName), '-'));
        $slug = ($base !== '' ? substr($base,0,40) : 'espaco') . '-' . substr(bin2hex(random_bytes(4)),0,8);
        $pdo->prepare('INSERT INTO tenants(id,name,slug) VALUES(?,?,?)')->execute([$tenantId,$workspaceName,$slug]);
        $pdo->prepare('INSERT INTO users(id,name,email,password_hash) VALUES(?,?,?,?)')->execute([$userId,$ownerName,$email,password_hash($password,PASSWORD_DEFAULT)]);
        $pdo->prepare('INSERT INTO memberships(tenant_id,user_id,role) VALUES(?,?,?)')->execute([$tenantId,$userId,'owner']);
        $trialEnd = (new DateTimeImmutable('+14 days'))->format('Y-m-d');
        $pdo->prepare("INSERT INTO subscriptions(tenant_id,plan_code,status,current_period_end) VALUES(?,'starter','trial',?)")->execute([$tenantId,$trialEnd]);
        $pdo->commit();
        return ['id'=>$userId,'name'=>$ownerName,'email'=>$email,'tenant_id'=>$tenantId,'tenant_name'=>$workspaceName,'role'=>'owner'];
    } catch (Throwable $error) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        throw $error;
    }
}

function authenticate_user(string $email, string $password): ?array
{
    $pdo=app_db(); $query=$pdo->prepare('SELECT u.id,u.name,u.email,u.password_hash,m.tenant_id,m.role,t.name AS tenant_name FROM users u JOIN memberships m ON m.user_id=u.id JOIN tenants t ON t.id=m.tenant_id WHERE u.email=? ORDER BY m.created_at LIMIT 1');
    $query->execute([$email]); $row=$query->fetch();
    if (!$row || !password_verify($password,$row['password_hash'])) return null;
    unset($row['password_hash']); return $row;
}

function accept_team_invitation(string $token, string $name, string $password): array
{
    $pdo=app_db(); $hash=hash('sha256',$token); $pdo->beginTransaction();
    try {
        $query=$pdo->prepare('SELECT * FROM invitations WHERE token_hash=? AND accepted_at IS NULL AND expires_at>CURRENT_TIMESTAMP');
        $query->execute([$hash]); $invite=$query->fetch();
        if (!$invite) throw new DomainException('Este convite expirou ou já foi utilizado. Peça um novo convite ao administrador.');
        $find=$pdo->prepare('SELECT id,name,email,password_hash FROM users WHERE email=?'); $find->execute([$invite['email']]); $user=$find->fetch();
        if ($user) {
            if (!password_verify($password,$user['password_hash'])) throw new DomainException('Esta conta já existe. Informe a senha atual da sua conta para aceitar o convite.');
            $userId=$user['id']; $userName=$user['name'];
        } else {
            if (trim($name)==='') throw new DomainException('Informe seu nome para criar a conta.');
            $userId=new_id('usr'); $userName=trim($name);
            $pdo->prepare('INSERT INTO users(id,name,email,password_hash) VALUES(?,?,?,?)')->execute([$userId,$userName,$invite['email'],password_hash($password,PASSWORD_DEFAULT)]);
        }
        $pdo->prepare('INSERT INTO memberships(tenant_id,user_id,role) VALUES(?,?,?)')->execute([$invite['tenant_id'],$userId,$invite['role']]);
        $pdo->prepare('UPDATE invitations SET accepted_at=CURRENT_TIMESTAMP WHERE id=?')->execute([$invite['id']]);
        $tenant=$pdo->prepare('SELECT name FROM tenants WHERE id=?'); $tenant->execute([$invite['tenant_id']]); $tenantName=(string)$tenant->fetchColumn();
        $pdo->commit();
        return ['id'=>$userId,'name'=>$userName,'email'=>$invite['email'],'tenant_id'=>$invite['tenant_id'],'tenant_name'=>$tenantName,'role'=>$invite['role']];
    } catch (Throwable $error) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        throw $error;
    }
}

function issue_team_invitation(string $tenantId, string $email, string $role, string $createdBy): string
{
    if (!in_array($role,['admin','manager','viewer'],true)) throw new DomainException('Papel de equipe inválido.');
    $pdo=app_db();
    $membership=$pdo->prepare('SELECT 1 FROM memberships m JOIN users u ON u.id=m.user_id WHERE m.tenant_id=? AND u.email=?');
    $membership->execute([$tenantId,$email]);
    if ($membership->fetchColumn()) throw new DomainException('Este e-mail já faz parte do espaço.');
    $existing=$pdo->prepare('SELECT id FROM invitations WHERE tenant_id=? AND email=? AND accepted_at IS NULL AND expires_at>CURRENT_TIMESTAMP');
    $existing->execute([$tenantId,$email]);
    if ($existing->fetchColumn()) throw new DomainException('Já existe um convite válido para este e-mail.');
    $token=bin2hex(random_bytes(32));
    $pdo->prepare("INSERT INTO invitations(id,tenant_id,email,role,token_hash,expires_at,created_by) VALUES(?,?,?,?,?,datetime('now','+7 days'),?)")
        ->execute([new_id('inv'),$tenantId,$email,$role,hash('sha256',$token),$createdBy]);
    return $token;
}

function invitation_details(string $token): ?array
{
    $stmt=app_db()->prepare('SELECT i.email,i.role,i.expires_at,t.name AS tenant_name FROM invitations i JOIN tenants t ON t.id=i.tenant_id WHERE i.token_hash=? AND i.accepted_at IS NULL AND i.expires_at>CURRENT_TIMESTAMP');
    $stmt->execute([hash('sha256',$token)]); $row=$stmt->fetch(); return $row ?: null;
}

function tenant_members(): array
{
    $stmt=app_db()->prepare('SELECT u.name,u.email,m.role,m.created_at FROM memberships m JOIN users u ON u.id=m.user_id WHERE m.tenant_id=? ORDER BY CASE m.role WHEN "owner" THEN 0 WHEN "admin" THEN 1 WHEN "manager" THEN 2 ELSE 3 END,u.name');
    $stmt->execute([tenant_id()]); return $stmt->fetchAll();
}

function tenant_invitations(): array
{
    $stmt=app_db()->prepare('SELECT email,role,expires_at,created_at FROM invitations WHERE tenant_id=? AND accepted_at IS NULL AND expires_at>CURRENT_TIMESTAMP ORDER BY created_at DESC');
    $stmt->execute([tenant_id()]); return $stmt->fetchAll();
}

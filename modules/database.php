<?php
declare(strict_types=1);
require_once __DIR__ . '/config.php';

function app_db(): PDO
{
    static $pdo = null;
    if ($pdo instanceof PDO) return $pdo;
    $storage = __DIR__ . '/../.runtime/app-data';
    if (!is_dir($storage)) mkdir($storage, 0775, true);
    $settings = database_settings();
    $driver = $settings['driver'];
    $options = [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC];
    if ($driver === 'mysql') {
        if (!in_array('mysql', PDO::getAvailableDrivers(), true)) throw new RuntimeException('O driver pdo_mysql não está habilitado no PHP.');
        foreach (['host','database','username','password'] as $required) if ($settings[$required] === '') throw new RuntimeException('Configure host, nome do banco, usuário e senha do MySQL em VERTICE_DB_* ou no arquivo privado de configuração.');
        $charset = preg_match('/^[a-zA-Z0-9_]+$/', (string)$settings['charset']) ? $settings['charset'] : 'utf8mb4';
        $dsn = 'mysql:host=' . $settings['host'] . ';port=' . (int)$settings['port'] . ';dbname=' . $settings['database'] . ';charset=' . $charset;
        $pdo = new PDO($dsn, $settings['username'], $settings['password'], $options + [PDO::ATTR_EMULATE_PREPARES => false]);
        $schema = mysql_schema();
    } elseif ($driver === 'sqlite') {
        if (!in_array('sqlite', PDO::getAvailableDrivers(), true)) throw new RuntimeException('SQLite não está habilitado no PHP.');
        $pdo = new PDO('sqlite:' . $storage . '/app.sqlite', null, null, $options);
        $pdo->exec('PRAGMA foreign_keys = ON');
        $pdo->exec('PRAGMA journal_mode = WAL');
        $schema = sqlite_schema();
    } else {
        throw new RuntimeException('Driver de banco inválido. Use mysql ou sqlite.');
    }
    foreach ($schema as $statement) $pdo->exec($statement);
    ensure_integration_secret_column($pdo, $driver);
    seed_subscription_plans($pdo, $driver);
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
    $tenants = $pdo->query('SELECT id FROM tenants')->fetchAll(PDO::FETCH_COLUMN);
    $subscriptionSql = $driver === 'mysql'
        ? "INSERT IGNORE INTO subscriptions(tenant_id,plan_code,status,current_period_end) VALUES(?,'starter','trial',?)"
        : "INSERT OR IGNORE INTO subscriptions(tenant_id,plan_code,status,current_period_end) VALUES(?,'starter','trial',?)";
    $subscription = $pdo->prepare($subscriptionSql);
    foreach ($tenants as $tenant) $subscription->execute([$tenant, $trialEnd]);
    return $pdo;
}

function sqlite_schema(): array
{
    return [
        "CREATE TABLE IF NOT EXISTS tenants (id TEXT PRIMARY KEY, name TEXT NOT NULL, slug TEXT NOT NULL UNIQUE, created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP)",
        "CREATE TABLE IF NOT EXISTS users (id TEXT PRIMARY KEY, name TEXT NOT NULL, email TEXT NOT NULL COLLATE NOCASE UNIQUE, password_hash TEXT NOT NULL, created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP)",
        "CREATE TABLE IF NOT EXISTS memberships (tenant_id TEXT NOT NULL REFERENCES tenants(id) ON DELETE CASCADE, user_id TEXT NOT NULL REFERENCES users(id) ON DELETE CASCADE, role TEXT NOT NULL CHECK(role IN ('owner','admin','manager','viewer')), created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP, PRIMARY KEY(tenant_id,user_id))",
        "CREATE TABLE IF NOT EXISTS invitations (id TEXT PRIMARY KEY, tenant_id TEXT NOT NULL REFERENCES tenants(id) ON DELETE CASCADE, email TEXT NOT NULL COLLATE NOCASE, role TEXT NOT NULL CHECK(role IN ('admin','manager','viewer')), token_hash TEXT NOT NULL UNIQUE, expires_at TEXT NOT NULL, accepted_at TEXT, created_by TEXT NOT NULL REFERENCES users(id), created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP)",
        "CREATE INDEX IF NOT EXISTS invitations_tenant_email_idx ON invitations(tenant_id,email)",
        "CREATE TABLE IF NOT EXISTS affiliates (id TEXT NOT NULL, tenant_id TEXT NOT NULL REFERENCES tenants(id) ON DELETE CASCADE, name TEXT NOT NULL, email TEXT NOT NULL COLLATE NOCASE, affiliate_group TEXT NOT NULL, commission REAL NOT NULL DEFAULT 20, status TEXT NOT NULL DEFAULT 'active', sales REAL NOT NULL DEFAULT 0, orders INTEGER NOT NULL DEFAULT 0, code TEXT NOT NULL, created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP, PRIMARY KEY(tenant_id,id), UNIQUE(tenant_id,email), UNIQUE(tenant_id,code))",
        "CREATE TABLE IF NOT EXISTS campaigns (id TEXT NOT NULL, tenant_id TEXT NOT NULL REFERENCES tenants(id) ON DELETE CASCADE, title TEXT NOT NULL, metric TEXT NOT NULL CHECK(metric IN ('revenue','orders')), target REAL NOT NULL, affiliate_group TEXT NOT NULL DEFAULT 'all', reward TEXT NOT NULL DEFAULT '', start_date TEXT NOT NULL, end_date TEXT NOT NULL, active INTEGER NOT NULL DEFAULT 1, created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP, PRIMARY KEY(tenant_id,id))",
        "CREATE TABLE IF NOT EXISTS platform_admins (id TEXT PRIMARY KEY, name TEXT NOT NULL, email TEXT NOT NULL COLLATE NOCASE UNIQUE, password_hash TEXT NOT NULL, created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP, last_login TEXT)",
        "CREATE TABLE IF NOT EXISTS subscription_plans (code TEXT PRIMARY KEY, name TEXT NOT NULL, monthly_price_cents INTEGER NOT NULL DEFAULT 0 CHECK(monthly_price_cents >= 0), active INTEGER NOT NULL DEFAULT 1, created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP)",
        "CREATE TABLE IF NOT EXISTS subscriptions (tenant_id TEXT PRIMARY KEY REFERENCES tenants(id) ON DELETE CASCADE, plan_code TEXT NOT NULL REFERENCES subscription_plans(code), status TEXT NOT NULL CHECK(status IN ('trial','active','past_due','canceled')), started_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP, current_period_end TEXT, updated_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP, notes TEXT NOT NULL DEFAULT '')",
        "CREATE TABLE IF NOT EXISTS subscription_events (id TEXT PRIMARY KEY, tenant_id TEXT NOT NULL REFERENCES tenants(id) ON DELETE CASCADE, admin_id TEXT NOT NULL REFERENCES platform_admins(id), event_type TEXT NOT NULL, details TEXT NOT NULL, created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP)",
        "CREATE TABLE IF NOT EXISTS integration_connections (id TEXT PRIMARY KEY, tenant_id TEXT NOT NULL REFERENCES tenants(id) ON DELETE CASCADE, name TEXT NOT NULL, platform TEXT NOT NULL DEFAULT 'kiwify', token_hash TEXT NOT NULL, secret_ciphertext TEXT NOT NULL DEFAULT '', status TEXT NOT NULL DEFAULT 'active' CHECK(status IN ('active','paused')), last_event_at TEXT, created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP, UNIQUE(tenant_id,name))",
        "CREATE TABLE IF NOT EXISTS sales_orders (id TEXT PRIMARY KEY, tenant_id TEXT NOT NULL REFERENCES tenants(id) ON DELETE CASCADE, connection_id TEXT NOT NULL REFERENCES integration_connections(id) ON DELETE CASCADE, external_order_id TEXT NOT NULL, affiliate_id TEXT, affiliate_code TEXT NOT NULL DEFAULT '', amount_cents INTEGER NOT NULL CHECK(amount_cents >= 0), currency TEXT NOT NULL DEFAULT 'BRL', status TEXT NOT NULL CHECK(status IN ('approved','refunded','canceled')), sold_at TEXT NOT NULL, updated_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP, UNIQUE(connection_id,external_order_id))",
        "CREATE INDEX IF NOT EXISTS sales_orders_tenant_status_idx ON sales_orders(tenant_id,status,sold_at)",
        "CREATE TABLE IF NOT EXISTS integration_events (id TEXT PRIMARY KEY, tenant_id TEXT NOT NULL REFERENCES tenants(id) ON DELETE CASCADE, connection_id TEXT NOT NULL REFERENCES integration_connections(id) ON DELETE CASCADE, external_event_id TEXT NOT NULL, event_type TEXT NOT NULL, result TEXT NOT NULL, message TEXT NOT NULL DEFAULT '', created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP, UNIQUE(connection_id,external_event_id))",
    ];
}

function mysql_schema(): array
{
    $suffix = ' ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci';
    return [
        "CREATE TABLE IF NOT EXISTS tenants (id VARCHAR(64) NOT NULL PRIMARY KEY, name VARCHAR(160) NOT NULL, slug VARCHAR(64) NOT NULL UNIQUE, created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP)$suffix",
        "CREATE TABLE IF NOT EXISTS users (id VARCHAR(64) NOT NULL PRIMARY KEY, name VARCHAR(160) NOT NULL, email VARCHAR(190) NOT NULL UNIQUE, password_hash VARCHAR(255) NOT NULL, created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP)$suffix",
        "CREATE TABLE IF NOT EXISTS memberships (tenant_id VARCHAR(64) NOT NULL, user_id VARCHAR(64) NOT NULL, role VARCHAR(20) NOT NULL, created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP, PRIMARY KEY(tenant_id,user_id), CONSTRAINT memberships_tenant_fk FOREIGN KEY(tenant_id) REFERENCES tenants(id) ON DELETE CASCADE, CONSTRAINT memberships_user_fk FOREIGN KEY(user_id) REFERENCES users(id) ON DELETE CASCADE)$suffix",
        "CREATE TABLE IF NOT EXISTS invitations (id VARCHAR(64) NOT NULL PRIMARY KEY, tenant_id VARCHAR(64) NOT NULL, email VARCHAR(190) NOT NULL, role VARCHAR(20) NOT NULL, token_hash CHAR(64) NOT NULL UNIQUE, expires_at DATETIME NOT NULL, accepted_at DATETIME NULL, created_by VARCHAR(64) NOT NULL, created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP, KEY invitations_tenant_email_idx(tenant_id,email), CONSTRAINT invitations_tenant_fk FOREIGN KEY(tenant_id) REFERENCES tenants(id) ON DELETE CASCADE, CONSTRAINT invitations_creator_fk FOREIGN KEY(created_by) REFERENCES users(id) ON DELETE CASCADE)$suffix",
        "CREATE TABLE IF NOT EXISTS affiliates (id VARCHAR(64) NOT NULL, tenant_id VARCHAR(64) NOT NULL, name VARCHAR(160) NOT NULL, email VARCHAR(190) NOT NULL, affiliate_group VARCHAR(100) NOT NULL, commission DECIMAL(7,3) NOT NULL DEFAULT 20, status VARCHAR(20) NOT NULL DEFAULT 'active', sales DECIMAL(14,2) NOT NULL DEFAULT 0, orders INT NOT NULL DEFAULT 0, code VARCHAR(100) NOT NULL, created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP, PRIMARY KEY(tenant_id,id), UNIQUE KEY affiliates_tenant_email_uq(tenant_id,email), UNIQUE KEY affiliates_tenant_code_uq(tenant_id,code), CONSTRAINT affiliates_tenant_fk FOREIGN KEY(tenant_id) REFERENCES tenants(id) ON DELETE CASCADE)$suffix",
        "CREATE TABLE IF NOT EXISTS campaigns (id VARCHAR(64) NOT NULL, tenant_id VARCHAR(64) NOT NULL, title VARCHAR(160) NOT NULL, metric VARCHAR(20) NOT NULL, target DECIMAL(14,2) NOT NULL, affiliate_group VARCHAR(100) NOT NULL DEFAULT 'all', reward VARCHAR(255) NOT NULL DEFAULT '', start_date DATE NOT NULL, end_date DATE NOT NULL, active TINYINT(1) NOT NULL DEFAULT 1, created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP, PRIMARY KEY(tenant_id,id), CONSTRAINT campaigns_tenant_fk FOREIGN KEY(tenant_id) REFERENCES tenants(id) ON DELETE CASCADE)$suffix",
        "CREATE TABLE IF NOT EXISTS platform_admins (id VARCHAR(64) NOT NULL PRIMARY KEY, name VARCHAR(160) NOT NULL, email VARCHAR(190) NOT NULL UNIQUE, password_hash VARCHAR(255) NOT NULL, created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP, last_login DATETIME NULL)$suffix",
        "CREATE TABLE IF NOT EXISTS subscription_plans (code VARCHAR(32) NOT NULL PRIMARY KEY, name VARCHAR(80) NOT NULL, monthly_price_cents INT NOT NULL DEFAULT 0, active TINYINT(1) NOT NULL DEFAULT 1, created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP)$suffix",
        "CREATE TABLE IF NOT EXISTS subscriptions (tenant_id VARCHAR(64) NOT NULL PRIMARY KEY, plan_code VARCHAR(32) NOT NULL, status VARCHAR(20) NOT NULL, started_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP, current_period_end DATE NULL, updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP, notes VARCHAR(500) NOT NULL DEFAULT '', CONSTRAINT subscriptions_tenant_fk FOREIGN KEY(tenant_id) REFERENCES tenants(id) ON DELETE CASCADE, CONSTRAINT subscriptions_plan_fk FOREIGN KEY(plan_code) REFERENCES subscription_plans(code))$suffix",
        "CREATE TABLE IF NOT EXISTS subscription_events (id VARCHAR(64) NOT NULL PRIMARY KEY, tenant_id VARCHAR(64) NOT NULL, admin_id VARCHAR(64) NOT NULL, event_type VARCHAR(50) NOT NULL, details TEXT NOT NULL, created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP, KEY subscription_events_tenant_idx(tenant_id,created_at), CONSTRAINT subscription_events_tenant_fk FOREIGN KEY(tenant_id) REFERENCES tenants(id) ON DELETE CASCADE, CONSTRAINT subscription_events_admin_fk FOREIGN KEY(admin_id) REFERENCES platform_admins(id) ON DELETE CASCADE)$suffix",
        "CREATE TABLE IF NOT EXISTS integration_connections (id VARCHAR(64) NOT NULL PRIMARY KEY, tenant_id VARCHAR(64) NOT NULL, name VARCHAR(80) NOT NULL, platform VARCHAR(32) NOT NULL DEFAULT 'kiwify', token_hash CHAR(64) NOT NULL, secret_ciphertext VARCHAR(255) NOT NULL DEFAULT '', status VARCHAR(20) NOT NULL DEFAULT 'active', last_event_at DATETIME NULL, created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP, UNIQUE KEY integrations_tenant_name_uq(tenant_id,name), KEY integrations_tenant_idx(tenant_id), CONSTRAINT integrations_tenant_fk FOREIGN KEY(tenant_id) REFERENCES tenants(id) ON DELETE CASCADE)$suffix",
        "CREATE TABLE IF NOT EXISTS sales_orders (id VARCHAR(64) NOT NULL PRIMARY KEY, tenant_id VARCHAR(64) NOT NULL, connection_id VARCHAR(64) NOT NULL, external_order_id VARCHAR(160) NOT NULL, affiliate_id VARCHAR(64) NULL, affiliate_code VARCHAR(100) NOT NULL DEFAULT '', amount_cents BIGINT NOT NULL, currency CHAR(3) NOT NULL DEFAULT 'BRL', status VARCHAR(20) NOT NULL, sold_at DATETIME NOT NULL, updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP, UNIQUE KEY sales_connection_order_uq(connection_id,external_order_id), KEY sales_orders_tenant_status_idx(tenant_id,status,sold_at), KEY sales_orders_affiliate_idx(tenant_id,affiliate_id), CONSTRAINT sales_orders_tenant_fk FOREIGN KEY(tenant_id) REFERENCES tenants(id) ON DELETE CASCADE, CONSTRAINT sales_orders_connection_fk FOREIGN KEY(connection_id) REFERENCES integration_connections(id) ON DELETE CASCADE)$suffix",
        "CREATE TABLE IF NOT EXISTS integration_events (id VARCHAR(64) NOT NULL PRIMARY KEY, tenant_id VARCHAR(64) NOT NULL, connection_id VARCHAR(64) NOT NULL, external_event_id VARCHAR(160) NOT NULL, event_type VARCHAR(80) NOT NULL, result VARCHAR(32) NOT NULL, message VARCHAR(500) NOT NULL DEFAULT '', created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP, UNIQUE KEY integration_connection_event_uq(connection_id,external_event_id), KEY integration_events_tenant_created_idx(tenant_id,created_at), CONSTRAINT integration_events_tenant_fk FOREIGN KEY(tenant_id) REFERENCES tenants(id) ON DELETE CASCADE, CONSTRAINT integration_events_connection_fk FOREIGN KEY(connection_id) REFERENCES integration_connections(id) ON DELETE CASCADE)$suffix",
    ];
}

function ensure_integration_secret_column(PDO $pdo, string $driver): void
{
    if ($driver === 'sqlite') {
        $columns = $pdo->query('PRAGMA table_info(integration_connections)')->fetchAll();
        $exists = in_array('secret_ciphertext', array_column($columns, 'name'), true);
    } else {
        $check = $pdo->query("SHOW COLUMNS FROM integration_connections LIKE 'secret_ciphertext'");
        $exists = (bool)$check->fetch();
    }
    if (!$exists) $pdo->exec("ALTER TABLE integration_connections ADD COLUMN secret_ciphertext " . ($driver === 'mysql' ? "VARCHAR(255) NOT NULL DEFAULT ''" : "TEXT NOT NULL DEFAULT ''"));
}

function seed_subscription_plans(PDO $pdo, string $driver): void
{
    $plans = [['starter','Inicial'],['growth','Crescimento'],['scale','Escala']];
    $sql = $driver === 'mysql'
        ? 'INSERT IGNORE INTO subscription_plans(code,name,monthly_price_cents,active) VALUES(?,?,0,1)'
        : 'INSERT OR IGNORE INTO subscription_plans(code,name,monthly_price_cents,active) VALUES(?,?,0,1)';
    $stmt = $pdo->prepare($sql);
    foreach ($plans as $plan) $stmt->execute($plan);
}

function new_id(string $prefix): string { return $prefix . '-' . bin2hex(random_bytes(12)); }

function database_driver(PDO $pdo): string
{
    return (string)$pdo->getAttribute(PDO::ATTR_DRIVER_NAME);
}

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
    $expiresAt=(new DateTimeImmutable('+7 days'))->format('Y-m-d H:i:s');
    $pdo->prepare('INSERT INTO invitations(id,tenant_id,email,role,token_hash,expires_at,created_by) VALUES(?,?,?,?,?,?,?)')
        ->execute([new_id('inv'),$tenantId,$email,$role,hash('sha256',$token),$expiresAt,$createdBy]);
    return $token;
}

function invitation_details(string $token): ?array
{
    $stmt=app_db()->prepare('SELECT i.email,i.role,i.expires_at,t.name AS tenant_name FROM invitations i JOIN tenants t ON t.id=i.tenant_id WHERE i.token_hash=? AND i.accepted_at IS NULL AND i.expires_at>CURRENT_TIMESTAMP');
    $stmt->execute([hash('sha256',$token)]); $row=$stmt->fetch(); return $row ?: null;
}

function tenant_members(): array
{
    $stmt=app_db()->prepare("SELECT u.name,u.email,m.role,m.created_at FROM memberships m JOIN users u ON u.id=m.user_id WHERE m.tenant_id=? ORDER BY CASE m.role WHEN 'owner' THEN 0 WHEN 'admin' THEN 1 WHEN 'manager' THEN 2 ELSE 3 END,u.name");
    $stmt->execute([tenant_id()]); return $stmt->fetchAll();
}

function tenant_invitations(): array
{
    $stmt=app_db()->prepare('SELECT email,role,expires_at,created_at FROM invitations WHERE tenant_id=? AND accepted_at IS NULL AND expires_at>CURRENT_TIMESTAMP ORDER BY created_at DESC');
    $stmt->execute([tenant_id()]); return $stmt->fetchAll();
}

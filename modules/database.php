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
    if ($settings['driver'] !== 'mysql') throw new RuntimeException('Este sistema está configurado para usar MySQL.');
    $options = [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC];
    if (!in_array('mysql', PDO::getAvailableDrivers(), true)) throw new RuntimeException('O driver pdo_mysql não está habilitado no PHP.');
    foreach (['host','database','username','password'] as $required) if ($settings[$required] === '') throw new RuntimeException('Configure host, nome do banco, usuário e senha do MySQL em VERTICE_DB_* ou no arquivo privado .runtime/app-data/database.php.');
    $charset = preg_match('/^[a-zA-Z0-9_]+$/', (string)$settings['charset']) ? $settings['charset'] : 'utf8mb4';
    $dsn = 'mysql:host=' . $settings['host'] . ';port=' . (int)$settings['port'] . ';dbname=' . $settings['database'] . ';charset=' . $charset;
    $pdo = new PDO($dsn, $settings['username'], $settings['password'], $options + [PDO::ATTR_EMULATE_PREPARES => false]);
    foreach (mysql_schema() as $statement) $pdo->exec($statement);
    ensure_integration_secret_column($pdo);
    ensure_affiliate_hotmart_code_column($pdo);
    ensure_campaign_target_affiliate_column($pdo);
    ensure_sales_customer_hash_column($pdo);
    ensure_product_scoped_integration_schema($pdo);
    $pdo->exec("INSERT IGNORE INTO user_profiles(user_id,profile_key,enabled) SELECT user_id,'producer',1 FROM memberships");
    seed_subscription_plans($pdo);
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
            $pdo->prepare("INSERT INTO user_profiles(user_id,profile_key,enabled) VALUES(?,'producer',1)")->execute([$userId]);
            $pdo->commit();
        } catch (Throwable $error) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            throw $error;
        }
    }
    $trialEnd = (new DateTimeImmutable('+14 days'))->format('Y-m-d');
    $tenants = $pdo->query('SELECT id FROM tenants')->fetchAll(PDO::FETCH_COLUMN);
    $subscription = $pdo->prepare("INSERT IGNORE INTO subscriptions(tenant_id,plan_code,status,current_period_end) VALUES(?,'starter','trial',?)");
    foreach ($tenants as $tenant) $subscription->execute([$tenant, $trialEnd]);
    return $pdo;
}

function mysql_schema(): array
{
    $suffix = ' ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci';
    return [
        "CREATE TABLE IF NOT EXISTS tenants (id VARCHAR(64) NOT NULL PRIMARY KEY, name VARCHAR(160) NOT NULL, slug VARCHAR(64) NOT NULL UNIQUE, created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP)$suffix",
        "CREATE TABLE IF NOT EXISTS users (id VARCHAR(64) NOT NULL PRIMARY KEY, name VARCHAR(160) NOT NULL, email VARCHAR(190) NOT NULL UNIQUE, password_hash VARCHAR(255) NOT NULL, created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP)$suffix",
        "CREATE TABLE IF NOT EXISTS memberships (tenant_id VARCHAR(64) NOT NULL, user_id VARCHAR(64) NOT NULL, role VARCHAR(20) NOT NULL, created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP, PRIMARY KEY(tenant_id,user_id), CONSTRAINT memberships_tenant_fk FOREIGN KEY(tenant_id) REFERENCES tenants(id) ON DELETE CASCADE, CONSTRAINT memberships_user_fk FOREIGN KEY(user_id) REFERENCES users(id) ON DELETE CASCADE)$suffix",
        "CREATE TABLE IF NOT EXISTS user_profiles (user_id VARCHAR(64) NOT NULL, profile_key VARCHAR(24) NOT NULL, enabled TINYINT(1) NOT NULL DEFAULT 1, created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP, PRIMARY KEY(user_id,profile_key), CONSTRAINT user_profiles_user_fk FOREIGN KEY(user_id) REFERENCES users(id) ON DELETE CASCADE)$suffix",
        "CREATE TABLE IF NOT EXISTS audit_logs (id VARCHAR(64) NOT NULL PRIMARY KEY, tenant_id VARCHAR(64) NULL, actor_user_id VARCHAR(64) NOT NULL, action_key VARCHAR(64) NOT NULL, subject_type VARCHAR(40) NOT NULL DEFAULT '', subject_id VARCHAR(64) NOT NULL DEFAULT '', details TEXT NOT NULL, created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP, KEY audit_logs_tenant_time_idx(tenant_id,created_at), KEY audit_logs_actor_idx(actor_user_id,created_at), CONSTRAINT audit_logs_tenant_fk FOREIGN KEY(tenant_id) REFERENCES tenants(id) ON DELETE SET NULL, CONSTRAINT audit_logs_actor_fk FOREIGN KEY(actor_user_id) REFERENCES users(id) ON DELETE CASCADE)$suffix",
        "CREATE TABLE IF NOT EXISTS membership_permissions (tenant_id VARCHAR(64) NOT NULL, user_id VARCHAR(64) NOT NULL, module_key VARCHAR(40) NOT NULL, action_key VARCHAR(32) NOT NULL, allowed TINYINT(1) NOT NULL DEFAULT 0, updated_by VARCHAR(64) NOT NULL, updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP, PRIMARY KEY(tenant_id,user_id,module_key,action_key), KEY membership_permissions_updated_by_idx(updated_by), CONSTRAINT membership_permissions_member_fk FOREIGN KEY(tenant_id,user_id) REFERENCES memberships(tenant_id,user_id) ON DELETE CASCADE, CONSTRAINT membership_permissions_editor_fk FOREIGN KEY(updated_by) REFERENCES users(id) ON DELETE CASCADE)$suffix",
        "CREATE TABLE IF NOT EXISTS invitations (id VARCHAR(64) NOT NULL PRIMARY KEY, tenant_id VARCHAR(64) NOT NULL, email VARCHAR(190) NOT NULL, role VARCHAR(20) NOT NULL, token_hash CHAR(64) NOT NULL UNIQUE, expires_at DATETIME NOT NULL, accepted_at DATETIME NULL, created_by VARCHAR(64) NOT NULL, created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP, KEY invitations_tenant_email_idx(tenant_id,email), CONSTRAINT invitations_tenant_fk FOREIGN KEY(tenant_id) REFERENCES tenants(id) ON DELETE CASCADE, CONSTRAINT invitations_creator_fk FOREIGN KEY(created_by) REFERENCES users(id) ON DELETE CASCADE)$suffix",
        "CREATE TABLE IF NOT EXISTS password_reset_tokens (id VARCHAR(64) NOT NULL PRIMARY KEY, user_id VARCHAR(64) NOT NULL, token_hash CHAR(64) NOT NULL UNIQUE, expires_at DATETIME NOT NULL, used_at DATETIME NULL, created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP, KEY password_reset_user_created_idx(user_id,created_at), CONSTRAINT password_reset_user_fk FOREIGN KEY(user_id) REFERENCES users(id) ON DELETE CASCADE)$suffix",
        "CREATE TABLE IF NOT EXISTS api_access_tokens (id VARCHAR(64) NOT NULL PRIMARY KEY, tenant_id VARCHAR(64) NOT NULL, user_id VARCHAR(64) NOT NULL, token_hash CHAR(64) NOT NULL UNIQUE, device_name VARCHAR(100) NOT NULL DEFAULT '', expires_at DATETIME NOT NULL, last_used_at DATETIME NULL, revoked_at DATETIME NULL, created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP, KEY api_tokens_tenant_user_idx(tenant_id,user_id,revoked_at), KEY api_tokens_expires_idx(expires_at), CONSTRAINT api_tokens_membership_fk FOREIGN KEY(tenant_id,user_id) REFERENCES memberships(tenant_id,user_id) ON DELETE CASCADE)$suffix",
        "CREATE TABLE IF NOT EXISTS affiliate_api_access_tokens (id VARCHAR(64) NOT NULL PRIMARY KEY, user_id VARCHAR(64) NOT NULL, token_hash CHAR(64) NOT NULL UNIQUE, device_name VARCHAR(100) NOT NULL DEFAULT '', expires_at DATETIME NOT NULL, last_used_at DATETIME NULL, revoked_at DATETIME NULL, created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP, KEY affiliate_api_tokens_user_idx(user_id,revoked_at), KEY affiliate_api_tokens_expiry_idx(expires_at), CONSTRAINT affiliate_api_tokens_user_fk FOREIGN KEY(user_id) REFERENCES users(id) ON DELETE CASCADE)$suffix",
        "CREATE TABLE IF NOT EXISTS api_login_attempts (attempt_key CHAR(64) NOT NULL PRIMARY KEY, attempts SMALLINT UNSIGNED NOT NULL DEFAULT 0, window_started_at DATETIME NOT NULL, locked_until DATETIME NULL, KEY api_login_locked_idx(locked_until))$suffix",
        "CREATE TABLE IF NOT EXISTS affiliates (id VARCHAR(64) NOT NULL, tenant_id VARCHAR(64) NOT NULL, name VARCHAR(160) NOT NULL, email VARCHAR(190) NOT NULL, affiliate_group VARCHAR(100) NOT NULL, commission DECIMAL(7,3) NOT NULL DEFAULT 20, status VARCHAR(20) NOT NULL DEFAULT 'active', sales DECIMAL(14,2) NOT NULL DEFAULT 0, orders INT NOT NULL DEFAULT 0, code VARCHAR(100) NOT NULL, hotmart_code VARCHAR(100) NULL DEFAULT NULL, created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP, PRIMARY KEY(tenant_id,id), UNIQUE KEY affiliates_tenant_email_uq(tenant_id,email), UNIQUE KEY affiliates_tenant_code_uq(tenant_id,code), UNIQUE KEY affiliates_tenant_hotmart_code_uq(tenant_id,hotmart_code), CONSTRAINT affiliates_tenant_fk FOREIGN KEY(tenant_id) REFERENCES tenants(id) ON DELETE CASCADE)$suffix",
        "CREATE TABLE IF NOT EXISTS affiliate_account_links (tenant_id VARCHAR(64) NOT NULL, affiliate_id VARCHAR(64) NOT NULL, user_id VARCHAR(64) NOT NULL, linked_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP, status VARCHAR(20) NOT NULL DEFAULT 'active', PRIMARY KEY(tenant_id,affiliate_id), KEY affiliate_links_user_idx(user_id,status), CONSTRAINT affiliate_account_link_affiliate_fk FOREIGN KEY(tenant_id,affiliate_id) REFERENCES affiliates(tenant_id,id) ON DELETE CASCADE, CONSTRAINT affiliate_account_link_user_fk FOREIGN KEY(user_id) REFERENCES users(id) ON DELETE CASCADE)$suffix",
        "CREATE TABLE IF NOT EXISTS affiliate_access_invites (id VARCHAR(64) NOT NULL PRIMARY KEY, tenant_id VARCHAR(64) NOT NULL, affiliate_id VARCHAR(64) NOT NULL, token_hash CHAR(64) NOT NULL UNIQUE, expires_at DATETIME NOT NULL, accepted_at DATETIME NULL, created_by VARCHAR(64) NOT NULL, created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP, KEY affiliate_invite_scope_idx(tenant_id,affiliate_id,accepted_at), CONSTRAINT affiliate_access_invite_affiliate_fk FOREIGN KEY(tenant_id,affiliate_id) REFERENCES affiliates(tenant_id,id) ON DELETE CASCADE, CONSTRAINT affiliate_access_invite_creator_fk FOREIGN KEY(created_by) REFERENCES users(id) ON DELETE CASCADE)$suffix",
        "CREATE TABLE IF NOT EXISTS affiliate_groups (id VARCHAR(64) NOT NULL, tenant_id VARCHAR(64) NOT NULL, name VARCHAR(100) NOT NULL, description VARCHAR(255) NOT NULL DEFAULT '', created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP, PRIMARY KEY(tenant_id,id), UNIQUE KEY affiliate_groups_tenant_name_uq(tenant_id,name), CONSTRAINT affiliate_groups_tenant_fk FOREIGN KEY(tenant_id) REFERENCES tenants(id) ON DELETE CASCADE)$suffix",
        "CREATE TABLE IF NOT EXISTS announcements (id VARCHAR(64) NOT NULL, tenant_id VARCHAR(64) NOT NULL, title VARCHAR(120) NOT NULL, body TEXT NOT NULL, audience_type VARCHAR(20) NOT NULL, audience_group VARCHAR(100) NOT NULL DEFAULT '', audience_affiliate_id VARCHAR(64) NULL, recipient_count INT NOT NULL DEFAULT 0, created_by VARCHAR(64) NOT NULL, created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP, PRIMARY KEY(tenant_id,id), KEY announcements_tenant_created_idx(tenant_id,created_at), CONSTRAINT announcements_tenant_fk FOREIGN KEY(tenant_id) REFERENCES tenants(id) ON DELETE CASCADE)$suffix",
        "CREATE TABLE IF NOT EXISTS announcement_recipients (id VARCHAR(64) NOT NULL PRIMARY KEY, tenant_id VARCHAR(64) NOT NULL, announcement_id VARCHAR(64) NOT NULL, affiliate_id VARCHAR(64) NOT NULL, email VARCHAR(190) NOT NULL, status VARCHAR(16) NOT NULL DEFAULT 'queued', attempts TINYINT UNSIGNED NOT NULL DEFAULT 0, last_error VARCHAR(500) NOT NULL DEFAULT '', created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP, updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP, sent_at DATETIME NULL, UNIQUE KEY announcement_recipient_uq(tenant_id,announcement_id,affiliate_id), KEY announcement_queue_idx(status,attempts,created_at), CONSTRAINT announcement_recipient_announcement_fk FOREIGN KEY(tenant_id,announcement_id) REFERENCES announcements(tenant_id,id) ON DELETE CASCADE, CONSTRAINT announcement_recipient_tenant_fk FOREIGN KEY(tenant_id) REFERENCES tenants(id) ON DELETE CASCADE)$suffix",
        "CREATE TABLE IF NOT EXISTS affiliate_rewards (id VARCHAR(64) NOT NULL, tenant_id VARCHAR(64) NOT NULL, title VARCHAR(120) NOT NULL, metric VARCHAR(20) NOT NULL, target DECIMAL(14,2) NOT NULL, period_type VARCHAR(20) NOT NULL, affiliate_group VARCHAR(100) NOT NULL DEFAULT 'all', reward_type VARCHAR(24) NOT NULL, reward_value VARCHAR(255) NOT NULL, active TINYINT(1) NOT NULL DEFAULT 1, created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP, PRIMARY KEY(tenant_id,id), KEY affiliate_rewards_tenant_active_idx(tenant_id,active), CONSTRAINT affiliate_rewards_tenant_fk FOREIGN KEY(tenant_id) REFERENCES tenants(id) ON DELETE CASCADE)$suffix",
        "CREATE TABLE IF NOT EXISTS affiliate_reward_awards (id VARCHAR(64) NOT NULL PRIMARY KEY, tenant_id VARCHAR(64) NOT NULL, reward_id VARCHAR(64) NOT NULL, affiliate_id VARCHAR(64) NOT NULL, period_start DATE NOT NULL, period_end DATE NOT NULL, metric_value DECIMAL(14,2) NOT NULL, status VARCHAR(20) NOT NULL DEFAULT 'unlocked', unlocked_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP, delivered_at DATETIME NULL, UNIQUE KEY affiliate_reward_award_period_uq(tenant_id,reward_id,affiliate_id,period_start), KEY affiliate_reward_awards_tenant_status_idx(tenant_id,status,unlocked_at), CONSTRAINT affiliate_reward_awards_rule_fk FOREIGN KEY(tenant_id,reward_id) REFERENCES affiliate_rewards(tenant_id,id) ON DELETE CASCADE, CONSTRAINT affiliate_reward_awards_affiliate_fk FOREIGN KEY(tenant_id,affiliate_id) REFERENCES affiliates(tenant_id,id) ON DELETE CASCADE)$suffix",
        "CREATE TABLE IF NOT EXISTS tenant_settings (tenant_id VARCHAR(64) NOT NULL, setting_key VARCHAR(80) NOT NULL, setting_value TEXT NOT NULL, updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP, PRIMARY KEY(tenant_id,setting_key), CONSTRAINT tenant_settings_tenant_fk FOREIGN KEY(tenant_id) REFERENCES tenants(id) ON DELETE CASCADE)$suffix",
        "CREATE TABLE IF NOT EXISTS affiliate_clicks (id VARCHAR(64) NOT NULL PRIMARY KEY, tenant_id VARCHAR(64) NOT NULL, affiliate_id VARCHAR(64) NOT NULL, visitor_hash CHAR(64) NOT NULL, clicked_at DATETIME NOT NULL, click_date DATE NOT NULL, UNIQUE KEY affiliate_click_unique_day_uq(tenant_id,affiliate_id,visitor_hash,click_date), KEY affiliate_clicks_date_idx(tenant_id,affiliate_id,clicked_at), CONSTRAINT affiliate_clicks_affiliate_fk FOREIGN KEY(tenant_id,affiliate_id) REFERENCES affiliates(tenant_id,id) ON DELETE CASCADE)$suffix",
        "CREATE TABLE IF NOT EXISTS campaigns (id VARCHAR(64) NOT NULL, tenant_id VARCHAR(64) NOT NULL, title VARCHAR(160) NOT NULL, metric VARCHAR(20) NOT NULL, target DECIMAL(14,2) NOT NULL, affiliate_group VARCHAR(100) NOT NULL DEFAULT 'all', target_affiliate_id VARCHAR(64) NULL, reward VARCHAR(255) NOT NULL DEFAULT '', start_date DATE NOT NULL, end_date DATE NOT NULL, active TINYINT(1) NOT NULL DEFAULT 1, created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP, PRIMARY KEY(tenant_id,id), KEY campaigns_affiliate_target_idx(tenant_id,target_affiliate_id), CONSTRAINT campaigns_tenant_fk FOREIGN KEY(tenant_id) REFERENCES tenants(id) ON DELETE CASCADE)$suffix",
        "CREATE TABLE IF NOT EXISTS affiliate_materials (id VARCHAR(64) NOT NULL, tenant_id VARCHAR(64) NOT NULL, title VARCHAR(160) NOT NULL, description TEXT NOT NULL, resource_url VARCHAR(2048) NOT NULL, audience_type VARCHAR(20) NOT NULL DEFAULT 'all', audience_group VARCHAR(100) NOT NULL DEFAULT '', audience_affiliate_id VARCHAR(64) NULL, active TINYINT(1) NOT NULL DEFAULT 1, created_by VARCHAR(64) NOT NULL, created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP, PRIMARY KEY(tenant_id,id), KEY affiliate_materials_audience_idx(tenant_id,active,audience_type,audience_group), CONSTRAINT affiliate_materials_tenant_fk FOREIGN KEY(tenant_id) REFERENCES tenants(id) ON DELETE CASCADE, CONSTRAINT affiliate_materials_creator_fk FOREIGN KEY(created_by) REFERENCES users(id) ON DELETE CASCADE, CONSTRAINT affiliate_materials_affiliate_fk FOREIGN KEY(tenant_id,audience_affiliate_id) REFERENCES affiliates(tenant_id,id) ON DELETE CASCADE)$suffix",
        "CREATE TABLE IF NOT EXISTS affiliate_events (id VARCHAR(64) NOT NULL, tenant_id VARCHAR(64) NOT NULL, title VARCHAR(160) NOT NULL, description TEXT NOT NULL, starts_at DATETIME NOT NULL, meeting_url VARCHAR(2048) NOT NULL, audience_type VARCHAR(20) NOT NULL DEFAULT 'all', audience_group VARCHAR(100) NOT NULL DEFAULT '', audience_affiliate_id VARCHAR(64) NULL, created_by VARCHAR(64) NOT NULL, created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP, PRIMARY KEY(tenant_id,id), KEY affiliate_events_schedule_idx(tenant_id,starts_at), CONSTRAINT affiliate_events_tenant_fk FOREIGN KEY(tenant_id) REFERENCES tenants(id) ON DELETE CASCADE, CONSTRAINT affiliate_events_creator_fk FOREIGN KEY(created_by) REFERENCES users(id) ON DELETE CASCADE, CONSTRAINT affiliate_events_affiliate_fk FOREIGN KEY(tenant_id,audience_affiliate_id) REFERENCES affiliates(tenant_id,id) ON DELETE CASCADE)$suffix",
        "CREATE TABLE IF NOT EXISTS affiliate_event_rsvps (tenant_id VARCHAR(64) NOT NULL, event_id VARCHAR(64) NOT NULL, affiliate_id VARCHAR(64) NOT NULL, response VARCHAR(16) NOT NULL DEFAULT 'pending', responded_at DATETIME NULL, PRIMARY KEY(tenant_id,event_id,affiliate_id), CONSTRAINT affiliate_event_rsvp_event_fk FOREIGN KEY(tenant_id,event_id) REFERENCES affiliate_events(tenant_id,id) ON DELETE CASCADE, CONSTRAINT affiliate_event_rsvp_affiliate_fk FOREIGN KEY(tenant_id,affiliate_id) REFERENCES affiliates(tenant_id,id) ON DELETE CASCADE)$suffix",
        "CREATE TABLE IF NOT EXISTS affiliate_support_threads (id VARCHAR(64) NOT NULL PRIMARY KEY, tenant_id VARCHAR(64) NOT NULL, affiliate_id VARCHAR(64) NOT NULL, subject VARCHAR(160) NOT NULL, status VARCHAR(20) NOT NULL DEFAULT 'open', created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP, updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP, KEY affiliate_support_scope_idx(tenant_id,affiliate_id,status,updated_at), CONSTRAINT affiliate_support_thread_affiliate_fk FOREIGN KEY(tenant_id,affiliate_id) REFERENCES affiliates(tenant_id,id) ON DELETE CASCADE)$suffix",
        "CREATE TABLE IF NOT EXISTS affiliate_support_messages (id VARCHAR(64) NOT NULL PRIMARY KEY, thread_id VARCHAR(64) NOT NULL, sender_user_id VARCHAR(64) NOT NULL, sender_profile VARCHAR(20) NOT NULL, body TEXT NOT NULL, created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP, read_at DATETIME NULL, KEY affiliate_support_messages_idx(thread_id,created_at), CONSTRAINT affiliate_support_message_thread_fk FOREIGN KEY(thread_id) REFERENCES affiliate_support_threads(id) ON DELETE CASCADE, CONSTRAINT affiliate_support_message_user_fk FOREIGN KEY(sender_user_id) REFERENCES users(id) ON DELETE CASCADE)$suffix",
        "CREATE TABLE IF NOT EXISTS affiliate_personal_goals (id VARCHAR(64) NOT NULL PRIMARY KEY, user_id VARCHAR(64) NOT NULL, title VARCHAR(120) NOT NULL, metric VARCHAR(24) NOT NULL, target DECIMAL(14,2) NOT NULL, start_date DATE NOT NULL, end_date DATE NOT NULL, created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP, KEY affiliate_personal_goals_user_dates_idx(user_id,start_date,end_date), CONSTRAINT affiliate_personal_goals_user_fk FOREIGN KEY(user_id) REFERENCES users(id) ON DELETE CASCADE)$suffix",
        "CREATE TABLE IF NOT EXISTS platform_admins (id VARCHAR(64) NOT NULL PRIMARY KEY, name VARCHAR(160) NOT NULL, email VARCHAR(190) NOT NULL UNIQUE, password_hash VARCHAR(255) NOT NULL, created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP, last_login DATETIME NULL)$suffix",
        "CREATE TABLE IF NOT EXISTS subscription_plans (code VARCHAR(32) NOT NULL PRIMARY KEY, name VARCHAR(80) NOT NULL, monthly_price_cents INT NOT NULL DEFAULT 0, active TINYINT(1) NOT NULL DEFAULT 1, created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP)$suffix",
        "CREATE TABLE IF NOT EXISTS subscriptions (tenant_id VARCHAR(64) NOT NULL PRIMARY KEY, plan_code VARCHAR(32) NOT NULL, status VARCHAR(20) NOT NULL, started_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP, current_period_end DATE NULL, updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP, notes VARCHAR(500) NOT NULL DEFAULT '', CONSTRAINT subscriptions_tenant_fk FOREIGN KEY(tenant_id) REFERENCES tenants(id) ON DELETE CASCADE, CONSTRAINT subscriptions_plan_fk FOREIGN KEY(plan_code) REFERENCES subscription_plans(code))$suffix",
        "CREATE TABLE IF NOT EXISTS subscription_events (id VARCHAR(64) NOT NULL PRIMARY KEY, tenant_id VARCHAR(64) NOT NULL, admin_id VARCHAR(64) NOT NULL, event_type VARCHAR(50) NOT NULL, details TEXT NOT NULL, created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP, KEY subscription_events_tenant_idx(tenant_id,created_at), CONSTRAINT subscription_events_tenant_fk FOREIGN KEY(tenant_id) REFERENCES tenants(id) ON DELETE CASCADE, CONSTRAINT subscription_events_admin_fk FOREIGN KEY(admin_id) REFERENCES platform_admins(id) ON DELETE CASCADE)$suffix",
        "CREATE TABLE IF NOT EXISTS integration_connections (id VARCHAR(64) NOT NULL PRIMARY KEY, tenant_id VARCHAR(64) NOT NULL, name VARCHAR(80) NOT NULL, platform VARCHAR(32) NOT NULL DEFAULT 'kiwify', token_hash CHAR(64) NOT NULL, secret_ciphertext VARCHAR(512) NOT NULL DEFAULT '', status VARCHAR(20) NOT NULL DEFAULT 'active', last_event_at DATETIME NULL, created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP, UNIQUE KEY integrations_tenant_name_uq(tenant_id,name), KEY integrations_tenant_idx(tenant_id), CONSTRAINT integrations_tenant_fk FOREIGN KEY(tenant_id) REFERENCES tenants(id) ON DELETE CASCADE)$suffix",
        "CREATE TABLE IF NOT EXISTS integration_products (id VARCHAR(64) NOT NULL PRIMARY KEY, tenant_id VARCHAR(64) NOT NULL, connection_id VARCHAR(64) NOT NULL, external_product_id VARCHAR(120) NOT NULL, name VARCHAR(160) NOT NULL, status VARCHAR(20) NOT NULL DEFAULT 'active', created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP, updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP, UNIQUE KEY integration_product_external_uq(connection_id,external_product_id), KEY integration_products_tenant_idx(tenant_id,connection_id,status), CONSTRAINT integration_products_tenant_fk FOREIGN KEY(tenant_id) REFERENCES tenants(id) ON DELETE CASCADE, CONSTRAINT integration_products_connection_fk FOREIGN KEY(connection_id) REFERENCES integration_connections(id) ON DELETE CASCADE)$suffix",
        "CREATE TABLE IF NOT EXISTS integration_affiliate_links (id VARCHAR(64) NOT NULL PRIMARY KEY, tenant_id VARCHAR(64) NOT NULL, connection_id VARCHAR(64) NOT NULL, affiliate_id VARCHAR(64) NOT NULL, external_affiliate_id VARCHAR(120) NOT NULL, external_email VARCHAR(190) NOT NULL DEFAULT '', status VARCHAR(20) NOT NULL DEFAULT 'active', created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP, UNIQUE KEY integration_affiliate_external_uq(connection_id,external_affiliate_id), UNIQUE KEY integration_affiliate_internal_uq(connection_id,affiliate_id), KEY integration_affiliate_tenant_idx(tenant_id,connection_id,status), CONSTRAINT integration_affiliate_link_tenant_fk FOREIGN KEY(tenant_id) REFERENCES tenants(id) ON DELETE CASCADE, CONSTRAINT integration_affiliate_link_connection_fk FOREIGN KEY(connection_id) REFERENCES integration_connections(id) ON DELETE CASCADE, CONSTRAINT integration_affiliate_link_affiliate_fk FOREIGN KEY(tenant_id,affiliate_id) REFERENCES affiliates(tenant_id,id) ON DELETE CASCADE)$suffix",
        "CREATE TABLE IF NOT EXISTS sales_orders (id VARCHAR(64) NOT NULL PRIMARY KEY, tenant_id VARCHAR(64) NOT NULL, connection_id VARCHAR(64) NOT NULL, external_order_id VARCHAR(160) NOT NULL, external_product_id VARCHAR(120) NOT NULL DEFAULT '', product_name VARCHAR(160) NOT NULL DEFAULT '', external_affiliate_id VARCHAR(120) NOT NULL DEFAULT '', affiliate_id VARCHAR(64) NULL, affiliate_code VARCHAR(100) NOT NULL DEFAULT '', customer_hash CHAR(64) NULL, amount_cents BIGINT NOT NULL, commission_cents BIGINT NULL, commission_source VARCHAR(20) NOT NULL DEFAULT 'unknown', currency CHAR(3) NOT NULL DEFAULT 'BRL', status VARCHAR(20) NOT NULL, sold_at DATETIME NOT NULL, updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP, UNIQUE KEY sales_connection_order_uq(connection_id,external_order_id), KEY sales_orders_tenant_status_idx(tenant_id,status,sold_at), KEY sales_orders_affiliate_idx(tenant_id,affiliate_id), KEY sales_orders_customer_idx(tenant_id,customer_hash,status), KEY sales_orders_product_idx(tenant_id,connection_id,external_product_id), CONSTRAINT sales_orders_tenant_fk FOREIGN KEY(tenant_id) REFERENCES tenants(id) ON DELETE CASCADE, CONSTRAINT sales_orders_connection_fk FOREIGN KEY(connection_id) REFERENCES integration_connections(id) ON DELETE CASCADE)$suffix",
        "CREATE TABLE IF NOT EXISTS integration_events (id VARCHAR(64) NOT NULL PRIMARY KEY, tenant_id VARCHAR(64) NOT NULL, connection_id VARCHAR(64) NOT NULL, external_event_id VARCHAR(160) NOT NULL, event_type VARCHAR(80) NOT NULL, result VARCHAR(32) NOT NULL, message VARCHAR(500) NOT NULL DEFAULT '', created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP, UNIQUE KEY integration_connection_event_uq(connection_id,external_event_id), KEY integration_events_tenant_created_idx(tenant_id,created_at), CONSTRAINT integration_events_tenant_fk FOREIGN KEY(tenant_id) REFERENCES tenants(id) ON DELETE CASCADE, CONSTRAINT integration_events_connection_fk FOREIGN KEY(connection_id) REFERENCES integration_connections(id) ON DELETE CASCADE)$suffix",
    ];
}

function ensure_integration_secret_column(PDO $pdo): void
{
    $check = $pdo->query("SHOW COLUMNS FROM integration_connections LIKE 'secret_ciphertext'");
    $column = $check->fetch(PDO::FETCH_ASSOC);
    if (!$column) {
        $pdo->exec("ALTER TABLE integration_connections ADD COLUMN secret_ciphertext VARCHAR(512) NOT NULL DEFAULT ''");
    } elseif (preg_match('/varchar\\((\\d+)\\)/i', (string)($column['Type'] ?? ''), $match) && (int)$match[1] < 512) {
        $pdo->exec("ALTER TABLE integration_connections MODIFY COLUMN secret_ciphertext VARCHAR(512) NOT NULL DEFAULT ''");
    }
}

function ensure_affiliate_hotmart_code_column(PDO $pdo): void
{
    $column = $pdo->query("SHOW COLUMNS FROM affiliates LIKE 'hotmart_code'");
    if (!$column->fetch()) $pdo->exec('ALTER TABLE affiliates ADD COLUMN hotmart_code VARCHAR(100) NULL DEFAULT NULL AFTER code');
    $index = $pdo->query("SHOW INDEX FROM affiliates WHERE Key_name='affiliates_tenant_hotmart_code_uq'");
    if (!$index->fetch()) $pdo->exec('ALTER TABLE affiliates ADD UNIQUE KEY affiliates_tenant_hotmart_code_uq(tenant_id,hotmart_code)');
}

function ensure_campaign_target_affiliate_column(PDO $pdo): void
{
    $column = $pdo->query("SHOW COLUMNS FROM campaigns LIKE 'target_affiliate_id'");
    if (!$column->fetch()) $pdo->exec('ALTER TABLE campaigns ADD COLUMN target_affiliate_id VARCHAR(64) NULL AFTER affiliate_group');
    $index = $pdo->query("SHOW INDEX FROM campaigns WHERE Key_name='campaigns_affiliate_target_idx'");
    if (!$index->fetch()) $pdo->exec('ALTER TABLE campaigns ADD KEY campaigns_affiliate_target_idx(tenant_id,target_affiliate_id)');
}

function ensure_sales_customer_hash_column(PDO $pdo): void
{
    $column = $pdo->query("SHOW COLUMNS FROM sales_orders LIKE 'customer_hash'");
    if (!$column->fetch()) $pdo->exec('ALTER TABLE sales_orders ADD COLUMN customer_hash CHAR(64) NULL AFTER affiliate_code');
    $index = $pdo->query("SHOW INDEX FROM sales_orders WHERE Key_name='sales_orders_customer_idx'");
    if (!$index->fetch()) $pdo->exec('ALTER TABLE sales_orders ADD KEY sales_orders_customer_idx(tenant_id,customer_hash,status)');
}

function ensure_product_scoped_integration_schema(PDO $pdo): void
{
    $columns = [
        'external_product_id' => "ALTER TABLE sales_orders ADD COLUMN external_product_id VARCHAR(120) NOT NULL DEFAULT '' AFTER external_order_id",
        'product_name' => "ALTER TABLE sales_orders ADD COLUMN product_name VARCHAR(160) NOT NULL DEFAULT '' AFTER external_product_id",
        'external_affiliate_id' => "ALTER TABLE sales_orders ADD COLUMN external_affiliate_id VARCHAR(120) NOT NULL DEFAULT '' AFTER product_name",
        'commission_cents' => "ALTER TABLE sales_orders ADD COLUMN commission_cents BIGINT NULL AFTER amount_cents",
    ];
    foreach ($columns as $name => $alter) {
        $stmt = $pdo->query('SHOW COLUMNS FROM sales_orders LIKE ' . $pdo->quote($name));
        if (!$stmt->fetch()) $pdo->exec($alter);
    }
    $commissionSource=$pdo->query("SHOW COLUMNS FROM sales_orders LIKE 'commission_source'");
    if(!$commissionSource->fetch())$pdo->exec("ALTER TABLE sales_orders ADD COLUMN commission_source VARCHAR(20) NOT NULL DEFAULT 'unknown' AFTER commission_cents");
    $index = $pdo->query("SHOW INDEX FROM sales_orders WHERE Key_name='sales_orders_product_idx'");
    if (!$index->fetch()) $pdo->exec('ALTER TABLE sales_orders ADD KEY sales_orders_product_idx(tenant_id,connection_id,external_product_id)');
}

function seed_subscription_plans(PDO $pdo): void
{
    $plans = [['starter','Inicial'],['growth','Crescimento'],['scale','Escala']];
    $stmt = $pdo->prepare('INSERT IGNORE INTO subscription_plans(code,name,monthly_price_cents,active) VALUES(?,?,0,1)');
    foreach ($plans as $plan) $stmt->execute($plan);
}

function new_id(string $prefix): string { return $prefix . '-' . bin2hex(random_bytes(12)); }

function app_audit_record(string $actorUserId,?string $tenantId,string $action,string $subjectType='',string $subjectId='',array $details=[]): void
{
    app_db()->prepare('INSERT INTO audit_logs(id,tenant_id,actor_user_id,action_key,subject_type,subject_id,details) VALUES(?,?,?,?,?,?,?)')->execute([new_id('aud'),$tenantId,$actorUserId,$action,$subjectType,$subjectId,json_encode($details,JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE|JSON_INVALID_UTF8_SUBSTITUTE)?:'{}']);
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
        $pdo->prepare("INSERT INTO user_profiles(user_id,profile_key,enabled) VALUES(?,'producer',1)")->execute([$userId]);
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
    $pdo=app_db(); $query=$pdo->prepare('SELECT u.id,u.name,u.email,u.password_hash,m.tenant_id,m.role,t.name AS tenant_name FROM users u LEFT JOIN memberships m ON m.user_id=u.id LEFT JOIN tenants t ON t.id=m.tenant_id WHERE u.email=? ORDER BY m.created_at LIMIT 1');
    $query->execute([$email]); $row=$query->fetch();
    if (!$row || !password_verify($password,$row['password_hash'])) return null;
    unset($row['password_hash']);
    $row['profiles']=app_enabled_profiles($row);
    if(!$row['profiles'])return null;
    $row['active_profile']=in_array('producer',$row['profiles'],true)?'producer':'affiliate';
    if ($row['tenant_id'] === null) { $row['role']='affiliate'; $row['tenant_name']=''; }
    return $row;
}

function create_workspace_for_existing_user(string $userId,string $workspaceName): array
{
    $workspaceName=trim($workspaceName);if($workspaceName===''||preg_match_all('/./us',$workspaceName)>120)throw new DomainException('Informe um nome de espaço com até 120 caracteres.');
    $pdo=app_db();$pdo->beginTransaction();
    try{
        $userQuery=$pdo->prepare('SELECT id,name,email FROM users WHERE id=? FOR UPDATE');$userQuery->execute([$userId]);$user=$userQuery->fetch();if(!$user)throw new DomainException('Sua conta não foi encontrada.');
        $membership=$pdo->prepare('SELECT 1 FROM memberships WHERE user_id=? LIMIT 1');$membership->execute([$userId]);if($membership->fetchColumn())throw new DomainException('Esta conta já possui um espaço de produtor.');
        $tenantId=new_id('org');$base=strtolower(trim(preg_replace('/[^a-zA-Z0-9]+/','-',iconv('UTF-8','ASCII//TRANSLIT//IGNORE',$workspaceName)?:$workspaceName),'-'));$slug=($base!==''?substr($base,0,40):'espaco').'-'.substr(bin2hex(random_bytes(4)),0,8);
        $pdo->prepare('INSERT INTO tenants(id,name,slug) VALUES(?,?,?)')->execute([$tenantId,$workspaceName,$slug]);$pdo->prepare("INSERT INTO memberships(tenant_id,user_id,role) VALUES(?,?,'owner')")->execute([$tenantId,$userId]);$pdo->prepare("INSERT INTO user_profiles(user_id,profile_key,enabled) VALUES(?,'producer',1) ON DUPLICATE KEY UPDATE enabled=1")->execute([$userId]);$trial=(new DateTimeImmutable('+14 days'))->format('Y-m-d');$pdo->prepare("INSERT INTO subscriptions(tenant_id,plan_code,status,current_period_end) VALUES(?,'starter','trial',?)")->execute([$tenantId,$trial]);app_audit_record($userId,$tenantId,'workspace.created','tenant',$tenantId,['name'=>$workspaceName]);$pdo->commit();
        return ['id'=>$userId,'name'=>$user['name'],'email'=>$user['email'],'tenant_id'=>$tenantId,'tenant_name'=>$workspaceName,'role'=>'owner','profiles'=>array_values(array_unique([...app_user_profiles($userId),'producer'])),'active_profile'=>'producer'];
    }catch(Throwable $e){if($pdo->inTransaction())$pdo->rollBack();throw $e;}
}

function app_user_profiles(string $userId): array
{
    $query=app_db()->prepare('SELECT profile_key FROM user_profiles WHERE user_id=? AND enabled=1');
    $query->execute([$userId]);
    return array_values(array_map('strval',$query->fetchAll(PDO::FETCH_COLUMN)));
}

function issue_affiliate_access_invite(string $tenantId,string $affiliateId,string $createdBy): string
{
    $pdo=app_db();
    $check=$pdo->prepare('SELECT status FROM affiliates WHERE tenant_id=? AND id=?'); $check->execute([$tenantId,$affiliateId]);
    if($check->fetchColumn()!=='active') throw new DomainException('Somente afiliados ativos podem receber convite de acesso.');
    $linked=$pdo->prepare("SELECT 1 FROM affiliate_account_links WHERE tenant_id=? AND affiliate_id=? AND status='active'"); $linked->execute([$tenantId,$affiliateId]);
    if($linked->fetchColumn()) throw new DomainException('Este afiliado já possui uma conta vinculada.');
    $token=bin2hex(random_bytes(32)); $expires=(new DateTimeImmutable('+7 days'))->format('Y-m-d H:i:s');
    $pdo->prepare('INSERT INTO affiliate_access_invites(id,tenant_id,affiliate_id,token_hash,expires_at,created_by) VALUES(?,?,?,?,?,?)')->execute([new_id('afi'),$tenantId,$affiliateId,hash('sha256',$token),$expires,$createdBy]);
    app_audit_record($createdBy,$tenantId,'affiliate.access_invited','affiliate',$affiliateId,['expires_at'=>$expires]);
    return $token;
}

function affiliate_access_invite_details(string $token): ?array
{
    if(!preg_match('/^[a-f0-9]{64}$/',$token)) return null;
    $q=app_db()->prepare("SELECT a.name,a.email,t.name AS tenant_name FROM affiliate_access_invites i JOIN affiliates a ON a.tenant_id=i.tenant_id AND a.id=i.affiliate_id JOIN tenants t ON t.id=i.tenant_id WHERE i.token_hash=? AND i.accepted_at IS NULL AND i.expires_at>UTC_TIMESTAMP() AND a.status='active'");
    $q->execute([hash('sha256',$token)]); return $q->fetch() ?: null;
}

function accept_affiliate_access_invite(string $token,string $name,string $password): array
{
    if (!preg_match('/^[a-f0-9]{64}$/',$token)) throw new DomainException('Convite inválido ou expirado.');
    $pdo=app_db(); $pdo->beginTransaction();
    try {
        $q=$pdo->prepare("SELECT i.*,a.name AS affiliate_name,a.email,a.status AS affiliate_status FROM affiliate_access_invites i JOIN affiliates a ON a.tenant_id=i.tenant_id AND a.id=i.affiliate_id WHERE i.token_hash=? AND i.accepted_at IS NULL AND i.expires_at>UTC_TIMESTAMP() FOR UPDATE");
        $q->execute([hash('sha256',$token)]);$invite=$q->fetch();
        if (!$invite || $invite['affiliate_status']!=='active') throw new DomainException('Convite inválido, expirado ou afiliado inativo.');
        $userQuery=$pdo->prepare('SELECT id,name,password_hash FROM users WHERE email=?');$userQuery->execute([$invite['email']]);$user=$userQuery->fetch();
        if ($user) {
            if (!password_verify($password,$user['password_hash'])) throw new DomainException('Já existe uma conta com este e-mail. Informe a senha atual para vincular o afiliado.');
            $userId=$user['id'];$userName=$user['name'];
        } else {
            if (strlen($password)<12) throw new DomainException('A senha de uma nova conta deve ter pelo menos 12 caracteres.');
            if (trim($name)==='') throw new DomainException('Informe seu nome para criar a conta.');
            $userId=new_id('usr');$userName=trim($name);
            $pdo->prepare('INSERT INTO users(id,name,email,password_hash) VALUES(?,?,?,?)')->execute([$userId,$userName,$invite['email'],password_hash($password,PASSWORD_DEFAULT)]);
        }
        $pdo->prepare("INSERT INTO affiliate_account_links(tenant_id,affiliate_id,user_id,status) VALUES(?,?,?,'active')")->execute([$invite['tenant_id'],$invite['affiliate_id'],$userId]);
        $pdo->prepare("INSERT INTO user_profiles(user_id,profile_key,enabled) VALUES(?,'affiliate',1) ON DUPLICATE KEY UPDATE enabled=1")->execute([$userId]);
        app_audit_record($userId,(string)$invite['tenant_id'],'affiliate.access_linked','affiliate',(string)$invite['affiliate_id']);
        $pdo->prepare('UPDATE affiliate_access_invites SET accepted_at=UTC_TIMESTAMP() WHERE id=?')->execute([$invite['id']]);
        $pdo->commit();
        $membership=$pdo->prepare('SELECT m.tenant_id,m.role,t.name AS tenant_name FROM memberships m JOIN tenants t ON t.id=m.tenant_id WHERE m.user_id=? ORDER BY m.created_at LIMIT 1');$membership->execute([$userId]);$producer=$membership->fetch()?:null;
        return ['id'=>$userId,'name'=>$userName,'email'=>$invite['email'],'tenant_id'=>$producer['tenant_id']??null,'tenant_name'=>$producer['tenant_name']??'','role'=>$producer['role']??'affiliate','profiles'=>array_values(array_unique([...app_user_profiles($userId),'affiliate'])),'active_profile'=>'affiliate'];
    } catch(Throwable $error) { if($pdo->inTransaction())$pdo->rollBack(); throw $error; }
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
        $pdo->prepare("INSERT INTO user_profiles(user_id,profile_key,enabled) VALUES(?,'producer',1) ON DUPLICATE KEY UPDATE enabled=1")->execute([$userId]);
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
    if (!in_array($role,['admin','manager','analyst','support','viewer'],true)) throw new DomainException('Papel de equipe inválido.');
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

function issue_password_reset(string $email): ?string
{
    $pdo = app_db();
    $query = $pdo->prepare('SELECT id FROM users WHERE email=?');
    $query->execute([$email]);
    $userId = $query->fetchColumn();
    if (!$userId) return null;
    $recent = $pdo->prepare('SELECT id FROM password_reset_tokens WHERE user_id=? AND created_at>DATE_SUB(CURRENT_TIMESTAMP, INTERVAL 1 MINUTE) LIMIT 1');
    $recent->execute([$userId]);
    if ($recent->fetchColumn()) return null;
    $token = bin2hex(random_bytes(32));
    $expires = (new DateTimeImmutable('+1 hour'))->format('Y-m-d H:i:s');
    $pdo->prepare('INSERT INTO password_reset_tokens(id,user_id,token_hash,expires_at) VALUES(?,?,?,?)')
        ->execute([new_id('reset'),$userId,hash('sha256',$token),$expires]);
    return $token;
}

function password_reset_token_valid(string $token): bool
{
    if (strlen($token) !== 64 || !ctype_xdigit($token)) return false;
    $stmt = app_db()->prepare('SELECT 1 FROM password_reset_tokens WHERE token_hash=? AND used_at IS NULL AND expires_at>CURRENT_TIMESTAMP LIMIT 1');
    $stmt->execute([hash('sha256',$token)]);
    return (bool)$stmt->fetchColumn();
}

function complete_password_reset(string $token, string $password): bool
{
    if (strlen($password) < 12) throw new DomainException('Crie uma senha com pelo menos 12 caracteres.');
    $pdo = app_db();
    $pdo->beginTransaction();
    try {
        $stmt = $pdo->prepare('SELECT id,user_id FROM password_reset_tokens WHERE token_hash=? AND used_at IS NULL AND expires_at>CURRENT_TIMESTAMP LIMIT 1 FOR UPDATE');
        $stmt->execute([hash('sha256',$token)]);
        $reset = $stmt->fetch();
        if (!$reset) { $pdo->commit(); return false; }
        $pdo->prepare('UPDATE users SET password_hash=? WHERE id=?')->execute([password_hash($password,PASSWORD_DEFAULT),$reset['user_id']]);
        $pdo->prepare('UPDATE api_access_tokens SET revoked_at=UTC_TIMESTAMP() WHERE user_id=? AND revoked_at IS NULL')->execute([$reset['user_id']]);
        $pdo->prepare('UPDATE affiliate_api_access_tokens SET revoked_at=UTC_TIMESTAMP() WHERE user_id=? AND revoked_at IS NULL')->execute([$reset['user_id']]);
        $pdo->prepare('UPDATE password_reset_tokens SET used_at=CURRENT_TIMESTAMP WHERE user_id=? AND used_at IS NULL')->execute([$reset['user_id']]);
        $pdo->commit();
        return true;
    } catch (Throwable $error) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        throw $error;
    }
}

function tenant_members(): array
{
    $stmt=app_db()->prepare("SELECT u.id AS user_id,u.name,u.email,m.role,m.created_at FROM memberships m JOIN users u ON u.id=m.user_id WHERE m.tenant_id=? ORDER BY CASE m.role WHEN 'owner' THEN 0 WHEN 'admin' THEN 1 WHEN 'manager' THEN 2 ELSE 3 END,u.name");
    $stmt->execute([tenant_id()]); return $stmt->fetchAll();
}

function tenant_invitations(): array
{
    $stmt=app_db()->prepare('SELECT email,role,expires_at,created_at FROM invitations WHERE tenant_id=? AND accepted_at IS NULL AND expires_at>CURRENT_TIMESTAMP ORDER BY created_at DESC');
    $stmt->execute([tenant_id()]); return $stmt->fetchAll();
}

<?php
declare(strict_types=1);
require_once __DIR__ . '/integrations.php';

function affiliate_program_destination(): string
{
    $stmt = app_db()->prepare("SELECT setting_value FROM tenant_settings WHERE tenant_id=? AND setting_key='affiliate_destination' LIMIT 1");
    $stmt->execute([tenant_id()]);
    return (string)($stmt->fetchColumn() ?: '');
}

function affiliate_program_destination_save(string $url): void
{
    $url = trim($url);
    if ($url === '') {
        app_db()->prepare("DELETE FROM tenant_settings WHERE tenant_id=? AND setting_key='affiliate_destination'")->execute([tenant_id()]);
        return;
    }
    $parts = parse_url($url);
    if (strlen($url) > 1000 || filter_var($url, FILTER_VALIDATE_URL) === false || !is_array($parts) || strtolower((string)($parts['scheme'] ?? '')) !== 'https' || empty($parts['host']) || isset($parts['user']) || isset($parts['pass'])) {
        throw new DomainException('Informe uma URL HTTPS válida da página de vendas, sem usuário ou senha na URL.');
    }
    app_db()->prepare("INSERT INTO tenant_settings(tenant_id,setting_key,setting_value) VALUES(?,'affiliate_destination',?) ON DUPLICATE KEY UPDATE setting_value=VALUES(setting_value)")
        ->execute([tenant_id(), $url]);
}

function affiliate_record_click(string $tenantId, string $affiliateId, string $visitorToken): void
{
    $visitorHash = hash_hmac('sha256', $tenantId . "\0" . $affiliateId . "\0" . $visitorToken, integration_encryption_key());
    app_db()->prepare('INSERT IGNORE INTO affiliate_clicks(id,tenant_id,affiliate_id,visitor_hash,clicked_at,click_date) VALUES(?,?,?,?,CURRENT_TIMESTAMP,CURRENT_DATE)')
        ->execute([new_id('clk'), $tenantId, $affiliateId, $visitorHash]);
}

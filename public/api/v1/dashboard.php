<?php
declare(strict_types=1);
require_once __DIR__ . '/bootstrap.php';
require_once __DIR__ . '/../../../modules/affiliates.php';
require_once __DIR__ . '/../../../modules/campaigns.php';
api_method('GET');
api_require_auth();
$affiliates = affiliate_read_all();
$sales = array_sum(array_map(static fn($a) => (float)$a['sales'], $affiliates));
$commission = array_sum(array_map(static fn($a) => (float)$a['sales'] * (float)$a['commission'] / 100, $affiliates));
$active = count(array_filter($affiliates, static fn($a) => $a['status'] === 'active'));
$pending = count(array_filter($affiliates, static fn($a) => $a['status'] === 'pending'));
$orders = app_db()->prepare("SELECT COUNT(*) FROM sales_orders WHERE tenant_id=? AND status='approved' AND affiliate_id IS NOT NULL");
$orders->execute([tenant_id()]);
$visits = app_db()->prepare('SELECT COUNT(DISTINCT affiliate_id,visitor_hash) FROM affiliate_clicks WHERE tenant_id=?');
$visits->execute([tenant_id()]);
$visitorCount = (int)$visits->fetchColumn();
$orderCount = (int)$orders->fetchColumn();
$campaigns = array_values(array_filter(campaigns_read_all(), static fn($c) => !empty($c['active'])));
$campaign = $campaigns[0] ?? null;
$goalProgress = $campaign ? campaign_progress($campaign, $affiliates) : $sales;
api_ok([
    'workspace' => ['id' => tenant_id(), 'name' => (string)(current_user()['tenant_name'] ?? '')],
    'metrics' => ['sales_total' => round($sales, 2), 'commission_estimated' => round($commission, 2), 'active_affiliates' => $active, 'pending_affiliates' => $pending, 'approved_orders' => $orderCount, 'unique_visitors' => $visitorCount, 'conversion_rate' => $visitorCount > 0 ? round($orderCount / $visitorCount * 100, 2) : 0],
    'active_campaign' => $campaign ? ['id' => $campaign['id'], 'title' => $campaign['title'], 'metric' => $campaign['metric'], 'target' => (float)$campaign['target'], 'progress' => round($goalProgress, 2), 'start_date' => $campaign['start'], 'end_date' => $campaign['end']] : null,
]);

<?php
declare(strict_types=1);
require_once __DIR__ . '/tenancy.php';
require_once __DIR__ . '/database.php';

function campaigns_file(): string { return tenant_storage_file('campaigns.json'); }

function campaign_seed_data(): array
{
    return [[
        'id' => 'camp-oct-2026', 'title' => 'Desafio de outubro', 'metric' => 'revenue',
        'target' => 50000, 'group' => 'all', 'target_affiliate_id' => null, 'reward' => 'Bônus especial para a equipe',
        'start' => '2026-10-01', 'end' => '2026-10-31', 'active' => true,
    ]];
}

function campaigns_read_all(): array
{
    $pdo=app_db();$tenant=tenant_id();$query=$pdo->prepare('SELECT id,title,metric,target,affiliate_group AS "group",target_affiliate_id,reward,start_date AS "start",end_date AS "end",active FROM campaigns WHERE tenant_id=? ORDER BY created_at');
    $query->execute([$tenant]);$rows=$query->fetchAll();
    if($rows){foreach($rows as &$row)$row['active']=(bool)$row['active'];unset($row);return $rows;}
    $legacy=[];
    if($tenant===DEMO_USER['tenant_id']){$file=campaigns_file();if(is_file($file)){$decoded=json_decode((string)file_get_contents($file),true);if(is_array($decoded))$legacy=$decoded;}if(!$legacy)$legacy=campaign_seed_data();}
    if($legacy){campaigns_write_all($legacy);return $legacy;}return [];
}

function campaigns_write_all(array $campaigns): void
{
    $pdo=app_db();$tenant=tenant_id();$pdo->beginTransaction();
    try{$pdo->prepare('DELETE FROM campaigns WHERE tenant_id=?')->execute([$tenant]);$insert=$pdo->prepare('INSERT INTO campaigns(id,tenant_id,title,metric,target,affiliate_group,target_affiliate_id,reward,start_date,end_date,active) VALUES(?,?,?,?,?,?,?,?,?,?,?)');foreach($campaigns as $c)$insert->execute([$c['id'],$tenant,$c['title'],$c['metric'],(float)$c['target'],$c['group'],$c['target_affiliate_id']??null,$c['reward'],$c['start'],$c['end'],!empty($c['active'])?1:0]);$pdo->commit();}catch(Throwable $error){if($pdo->inTransaction())$pdo->rollBack();throw $error;}
}

function campaign_values_by_affiliate(array $campaign, array $affiliates): array
{
    $targetId = (string)($campaign['target_affiliate_id'] ?? '');
    $eligible = array_filter($affiliates, static fn($a) => $a['status'] === 'active' && ($targetId !== '' ? (string)$a['id'] === $targetId : ($campaign['group'] === 'all' || $a['group'] === $campaign['group'])));
    $values = [];
    foreach ($eligible as $affiliate) $values[(string)$affiliate['id']] = 0.0;
    $tenant = tenant_id();
    if ($tenant === DEMO_USER['tenant_id'] && in_array($campaign['metric'] ?? 'revenue', ['revenue','orders'], true)) {
        foreach ($eligible as $affiliate) $values[(string)$affiliate['id']] = match ($campaign['metric'] ?? 'revenue') { 'orders' => (float)$affiliate['orders'], 'revenue' => (float)$affiliate['sales'], default => 0.0 };
        return $values;
    }
    $ids = array_keys($values);
    if (!$ids) return $values;
    $placeholders = implode(',', array_fill(0, count($ids), '?'));
    if (($campaign['metric'] ?? 'revenue') === 'conversion') {
        $orderSql = "SELECT affiliate_id,COUNT(*) AS total FROM sales_orders WHERE tenant_id=? AND status='approved' AND sold_at>=? AND sold_at<DATE_ADD(?,INTERVAL 1 DAY) AND affiliate_id IN ($placeholders) GROUP BY affiliate_id";
        $orderStmt = app_db()->prepare($orderSql);
        $orderStmt->execute(array_merge([$tenant, $campaign['start'], $campaign['end']], $ids));
        $ordersByAffiliate = [];
        foreach ($orderStmt->fetchAll() as $row) $ordersByAffiliate[(string)$row['affiliate_id']] = (int)$row['total'];
        $clickSql = "SELECT affiliate_id,COUNT(DISTINCT visitor_hash) AS visitors FROM affiliate_clicks WHERE tenant_id=? AND clicked_at>=? AND clicked_at<DATE_ADD(?,INTERVAL 1 DAY) AND affiliate_id IN ($placeholders) GROUP BY affiliate_id";
        $clickStmt = app_db()->prepare($clickSql);
        $clickStmt->execute(array_merge([$tenant, $campaign['start'], $campaign['end']], $ids));
        $visitorsByAffiliate = [];
        foreach ($clickStmt->fetchAll() as $row) $visitorsByAffiliate[(string)$row['affiliate_id']] = (int)$row['visitors'];
        foreach ($values as $id => $_) {
            $visitors = $visitorsByAffiliate[$id] ?? 0;
            $values[$id] = $visitors > 0 ? (($ordersByAffiliate[$id] ?? 0) / $visitors) * 100 : 0.0;
        }
        return $values;
    }
    if (($campaign['metric'] ?? 'revenue') === 'new_customers') {
        $sql = "SELECT s.affiliate_id,COUNT(DISTINCT s.customer_hash) AS progress FROM sales_orders s WHERE s.tenant_id=? AND s.status='approved' AND s.customer_hash IS NOT NULL AND s.sold_at>=? AND s.sold_at<DATE_ADD(?,INTERVAL 1 DAY) AND s.affiliate_id IN ($placeholders) AND NOT EXISTS (SELECT 1 FROM sales_orders prior WHERE prior.tenant_id=s.tenant_id AND prior.customer_hash=s.customer_hash AND prior.status='approved' AND (prior.sold_at<s.sold_at OR (prior.sold_at=s.sold_at AND prior.id<s.id))) GROUP BY s.affiliate_id";
    } else {
        $aggregate = ($campaign['metric'] ?? 'revenue') === 'orders' ? 'COUNT(*)' : 'COALESCE(SUM(amount_cents),0)/100.0';
        $sql = "SELECT affiliate_id,$aggregate AS progress FROM sales_orders WHERE tenant_id=? AND status='approved' AND sold_at>=? AND sold_at<DATE_ADD(?,INTERVAL 1 DAY) AND affiliate_id IN ($placeholders) GROUP BY affiliate_id";
    }
    $stmt = app_db()->prepare($sql);
    $stmt->execute(array_merge([$tenant, $campaign['start'], $campaign['end']], $ids));
    foreach ($stmt->fetchAll() as $row) $values[(string)$row['affiliate_id']] = (float)$row['progress'];
    return $values;
}

function campaign_progress(array $campaign, array $affiliates): float
{
    return array_sum(campaign_values_by_affiliate($campaign, $affiliates));
}

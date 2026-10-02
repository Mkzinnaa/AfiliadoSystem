<?php
declare(strict_types=1);
require_once __DIR__ . '/database.php';
require_once __DIR__ . '/tenancy.php';

function reward_period_bounds(string $periodType): array
{
    $today = new DateTimeImmutable('today', new DateTimeZone('UTC'));
    if ($periodType === 'weekly') {
        $start = $today->modify('monday this week');
        $end = $today;
    } elseif ($periodType === 'monthly') {
        $start = $today->modify('first day of this month');
        $end = $today;
    } else {
        $start = new DateTimeImmutable('2000-01-01', new DateTimeZone('UTC'));
        $end = $today;
    }
    return [$start->format('Y-m-d'), $end->format('Y-m-d')];
}

function reward_rule_list(?string $tenantId = null): array
{
    $query = app_db()->prepare('SELECT id,title,metric,target,period_type,affiliate_group,reward_type,reward_value,active,created_at FROM affiliate_rewards WHERE tenant_id=? ORDER BY created_at DESC');
    $query->execute([$tenantId ?? tenant_id()]);
    return $query->fetchAll();
}

function reward_metric_values(array $rule, string $start, string $end, string $tenant): array
{
    $values = [];
    $eligible = app_db()->prepare("SELECT id FROM affiliates WHERE tenant_id=? AND status='active' AND (?='all' OR affiliate_group=?)");
    $eligible->execute([$tenant,$rule['affiliate_group'],$rule['affiliate_group']]);
    foreach ($eligible->fetchAll(PDO::FETCH_COLUMN) as $id) $values[(string)$id] = 0.0;
    if ($tenant === DEMO_USER['tenant_id'] && $rule['period_type'] === 'all_time' && in_array($rule['metric'], ['revenue','orders'], true)) {
        $query = app_db()->prepare("SELECT id,sales,orders FROM affiliates WHERE tenant_id=? AND status='active' AND (?='all' OR affiliate_group=?)");
        $query->execute([$tenant,$rule['affiliate_group'],$rule['affiliate_group']]);
        foreach ($query->fetchAll() as $affiliate) $values[(string)$affiliate['id']] = $rule['metric'] === 'revenue' ? (float)$affiliate['sales'] : (int)$affiliate['orders'];
        return $values;
    }
    $pdo = app_db();
    if ($rule['metric'] === 'new_customers') {
        $sql = "SELECT s.affiliate_id,COUNT(DISTINCT s.customer_hash) AS metric_total FROM sales_orders s JOIN affiliates a ON a.tenant_id=s.tenant_id AND a.id=s.affiliate_id WHERE s.tenant_id=? AND a.status='active' AND (?='all' OR a.affiliate_group=?) AND s.status='approved' AND s.customer_hash IS NOT NULL AND s.sold_at>=? AND s.sold_at<DATE_ADD(?,INTERVAL 1 DAY) AND NOT EXISTS (SELECT 1 FROM sales_orders prior WHERE prior.tenant_id=s.tenant_id AND prior.customer_hash=s.customer_hash AND prior.status='approved' AND (prior.sold_at<s.sold_at OR (prior.sold_at=s.sold_at AND prior.id<s.id))) GROUP BY s.affiliate_id";
        $query = $pdo->prepare($sql);
        $query->execute([$tenant,$rule['affiliate_group'],$rule['affiliate_group'],$start,$end]);
    } else {
        $aggregate = $rule['metric'] === 'orders' ? 'COUNT(*)' : 'COALESCE(SUM(s.amount_cents),0)/100.0';
        $sql = "SELECT s.affiliate_id,$aggregate AS metric_total FROM sales_orders s JOIN affiliates a ON a.tenant_id=s.tenant_id AND a.id=s.affiliate_id WHERE s.tenant_id=? AND a.status='active' AND (?='all' OR a.affiliate_group=?) AND s.status='approved' AND s.sold_at>=? AND s.sold_at<DATE_ADD(?,INTERVAL 1 DAY) GROUP BY s.affiliate_id";
        $query = $pdo->prepare($sql);
        $query->execute([$tenant,$rule['affiliate_group'],$rule['affiliate_group'],$start,$end]);
    }
    foreach ($query->fetchAll() as $row) $values[(string)$row['affiliate_id']] = (float)$row['metric_total'];
    return $values;
}

function reward_evaluate_rules(?string $affiliateId = null, ?string $tenantId = null): int
{
    $tenant = $tenantId ?? tenant_id();
    $rules = array_values(array_filter(reward_rule_list($tenant), static fn($rule) => (bool)$rule['active']));
    $pdo = app_db();
    $awards = 0;
    foreach ($rules as $rule) {
        [$start,$end] = reward_period_bounds((string)$rule['period_type']);
        $values = reward_metric_values($rule,$start,$end,$tenant);
        foreach ($values as $id => $value) {
            if ($affiliateId !== null && $id !== $affiliateId) continue;
            if ((float)$value < (float)$rule['target']) {
                $pdo->prepare("DELETE FROM affiliate_reward_awards WHERE tenant_id=? AND reward_id=? AND affiliate_id=? AND period_start=? AND status='unlocked'")->execute([$tenant,$rule['id'],$id,$start]);
                continue;
            }
            $insert = $pdo->prepare("INSERT IGNORE INTO affiliate_reward_awards(id,tenant_id,reward_id,affiliate_id,period_start,period_end,metric_value,status) VALUES(?,?,?,?,?,?,?,'unlocked')");
            $insert->execute([new_id('rwa'),$tenant,$rule['id'],$id,$start,$end,$value]);
            $awards += $insert->rowCount();
        }
    }
    return $awards;
}

function reward_award_list(): array
{
    $query = app_db()->prepare('SELECT w.id,w.reward_id,w.affiliate_id,w.period_start,w.period_end,w.metric_value,w.status,w.unlocked_at,w.delivered_at,r.title,r.metric,r.target,r.reward_type,r.reward_value,a.name AS affiliate_name,a.affiliate_group FROM affiliate_reward_awards w JOIN affiliate_rewards r ON r.tenant_id=w.tenant_id AND r.id=w.reward_id JOIN affiliates a ON a.tenant_id=w.tenant_id AND a.id=w.affiliate_id WHERE w.tenant_id=? ORDER BY w.unlocked_at DESC LIMIT 100');
    $query->execute([tenant_id()]);
    return $query->fetchAll();
}

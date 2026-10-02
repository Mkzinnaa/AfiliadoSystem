<?php
declare(strict_types=1);
require_once __DIR__ . '/database.php';
require_once __DIR__ . '/tenancy.php';
require_once __DIR__ . '/affiliates.php';

function affiliate_ranking(string $metric, string $start, string $end, string $group = 'all'): array
{
    if (!in_array($metric, ['revenue','orders','new_customers','conversion','growth'], true)) throw new InvalidArgumentException('Métrica de ranking inválida.');
    $tenant = tenant_id();
    $affiliates = array_values(array_filter(affiliate_read_all(), static fn($affiliate) => $affiliate['status'] === 'active' && ($group === 'all' || $affiliate['group'] === $group)));
    if (!$affiliates) return [];

    $values = [];
    if ($tenant === DEMO_USER['tenant_id'] && in_array($metric, ['revenue','orders'], true)) {
        foreach ($affiliates as $affiliate) $values[(string)$affiliate['id']] = ['revenue'=>(float)$affiliate['sales'],'orders'=>(int)$affiliate['orders'],'new_customers'=>0,'conversion'=>0.0];
    } else {
        $pdo = app_db();
        $newCustomerSql = "SELECT s.affiliate_id,COUNT(DISTINCT s.customer_hash) AS total FROM sales_orders s WHERE s.tenant_id=? AND s.status='approved' AND s.customer_hash IS NOT NULL AND s.sold_at>=? AND s.sold_at<DATE_ADD(?,INTERVAL 1 DAY) AND NOT EXISTS (SELECT 1 FROM sales_orders prior WHERE prior.tenant_id=s.tenant_id AND prior.customer_hash=s.customer_hash AND prior.status='approved' AND (prior.sold_at<s.sold_at OR (prior.sold_at=s.sold_at AND prior.id<s.id))) GROUP BY s.affiliate_id";
        $query = $pdo->prepare("SELECT affiliate_id,COALESCE(SUM(amount_cents),0)/100 AS revenue,COUNT(*) AS orders FROM sales_orders WHERE tenant_id=? AND status='approved' AND sold_at>=? AND sold_at<DATE_ADD(?,INTERVAL 1 DAY) GROUP BY affiliate_id");
        $query->execute([$tenant,$start,$end]);
        foreach ($query->fetchAll() as $row) $values[(string)$row['affiliate_id']] = ['revenue'=>(float)$row['revenue'],'orders'=>(int)$row['orders'],'new_customers'=>0,'conversion'=>0.0];
        $query = $pdo->prepare($newCustomerSql);
        $query->execute([$tenant,$start,$end]);
        foreach ($query->fetchAll() as $row) $values[(string)$row['affiliate_id']]['new_customers'] = (int)$row['total'];
        $query = $pdo->prepare("SELECT affiliate_id,COUNT(DISTINCT visitor_hash) AS visitors FROM affiliate_clicks WHERE tenant_id=? AND clicked_at>=? AND clicked_at<DATE_ADD(?,INTERVAL 1 DAY) GROUP BY affiliate_id");
        $query->execute([$tenant,$start,$end]);
        $visitors = [];
        foreach ($query->fetchAll() as $row) $visitors[(string)$row['affiliate_id']] = (int)$row['visitors'];
        foreach ($values as $id => &$entry) $entry['conversion'] = ($visitors[$id] ?? 0) > 0 ? $entry['orders'] / $visitors[$id] * 100 : 0.0;
        unset($entry);

        if ($metric === 'growth') {
            $from = new DateTimeImmutable($start);
            $to = new DateTimeImmutable($end);
            $days = (int)$from->diff($to)->days + 1;
            $previousStart = $from->modify('-' . $days . ' days')->format('Y-m-d');
            $previousEnd = $from->modify('-1 day')->format('Y-m-d');
            $query = $pdo->prepare("SELECT affiliate_id,COALESCE(SUM(amount_cents),0)/100 AS revenue FROM sales_orders WHERE tenant_id=? AND status='approved' AND sold_at>=? AND sold_at<DATE_ADD(?,INTERVAL 1 DAY) GROUP BY affiliate_id");
            $query->execute([$tenant,$previousStart,$previousEnd]);
            $previous = [];
            foreach ($query->fetchAll() as $row) $previous[(string)$row['affiliate_id']] = (float)$row['revenue'];
        }
    }

    $result = [];
    foreach ($affiliates as $affiliate) {
        $id = (string)$affiliate['id'];
        $entry = is_array($values[$id] ?? null) ? $values[$id] : ['revenue'=>0.0,'orders'=>0,'new_customers'=>0,'conversion'=>0.0];
        $score = match ($metric) {
            'revenue' => $entry['revenue'],
            'orders' => $entry['orders'],
            'new_customers' => $entry['new_customers'],
            'conversion' => $entry['conversion'],
            'growth' => ($previous[$id] ?? 0) > 0 ? (($entry['revenue'] - $previous[$id]) / $previous[$id]) * 100 : 0.0,
        };
        $affiliate['ranking_value'] = (float)$score;
        $affiliate['ranking_revenue'] = (float)$entry['revenue'];
        $affiliate['ranking_orders'] = (int)$entry['orders'];
        $affiliate['ranking_new_customers'] = (int)$entry['new_customers'];
        $affiliate['ranking_conversion'] = (float)$entry['conversion'];
        $affiliate['ranking_previous_revenue'] = (float)($previous[$id] ?? 0);
        $result[] = $affiliate;
    }
    usort($result, static fn($a,$b) => $b['ranking_value'] <=> $a['ranking_value'] ?: strcmp((string)$a['name'],(string)$b['name']));
    return $result;
}

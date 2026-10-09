<?php
declare(strict_types=1);
require_once __DIR__ . '/bootstrap.php';
require_once __DIR__ . '/../../../modules/affiliates.php';
require_once __DIR__ . '/../../../modules/campaigns.php';
api_method('GET');
api_require_auth();
api_require_permission('campaigns');
$affiliates = affiliate_read_all();
$campaigns = campaigns_read_all();
$data = [];
foreach ($campaigns as $campaign) {
    $values = campaign_values_by_affiliate($campaign, $affiliates);
    $data[] = ['id' => $campaign['id'], 'title' => $campaign['title'], 'metric' => $campaign['metric'], 'target' => (float)$campaign['target'], 'group' => $campaign['group'], 'target_affiliate_id' => $campaign['target_affiliate_id'], 'reward' => $campaign['reward'], 'start_date' => $campaign['start'], 'end_date' => $campaign['end'], 'active' => (bool)$campaign['active'], 'progress' => round(array_sum($values), 2), 'progress_by_affiliate' => $values];
}
api_ok($data);

<?php
declare(strict_types=1);
require_once __DIR__ . '/tenancy.php';
require_once __DIR__ . '/database.php';

function campaigns_file(): string { return tenant_storage_file('campaigns.json'); }

function campaign_seed_data(): array
{
    return [[
        'id' => 'camp-oct-2026', 'title' => 'Desafio de outubro', 'metric' => 'revenue',
        'target' => 50000, 'group' => 'all', 'reward' => 'Bônus especial para a equipe',
        'start' => '2026-10-01', 'end' => '2026-10-31', 'active' => true,
    ]];
}

function campaigns_read_all(): array
{
    $pdo=app_db();$tenant=tenant_id();$query=$pdo->prepare('SELECT id,title,metric,target,affiliate_group AS "group",reward,start_date AS "start",end_date AS "end",active FROM campaigns WHERE tenant_id=? ORDER BY created_at');
    $query->execute([$tenant]);$rows=$query->fetchAll();
    if($rows){foreach($rows as &$row)$row['active']=(bool)$row['active'];unset($row);return $rows;}
    $legacy=[];
    if($tenant===DEMO_USER['tenant_id']){$file=campaigns_file();if(is_file($file)){$decoded=json_decode((string)file_get_contents($file),true);if(is_array($decoded))$legacy=$decoded;}if(!$legacy)$legacy=campaign_seed_data();}
    if($legacy){campaigns_write_all($legacy);return $legacy;}return [];
}

function campaigns_write_all(array $campaigns): void
{
    $pdo=app_db();$tenant=tenant_id();$pdo->beginTransaction();
    try{$pdo->prepare('DELETE FROM campaigns WHERE tenant_id=?')->execute([$tenant]);$insert=$pdo->prepare('INSERT INTO campaigns(id,tenant_id,title,metric,target,affiliate_group,reward,start_date,end_date,active) VALUES(?,?,?,?,?,?,?,?,?,?)');foreach($campaigns as $c)$insert->execute([$c['id'],$tenant,$c['title'],$c['metric'],(float)$c['target'],$c['group'],$c['reward'],$c['start'],$c['end'],!empty($c['active'])?1:0]);$pdo->commit();}catch(Throwable $error){if($pdo->inTransaction())$pdo->rollBack();throw $error;}
}

function campaign_progress(array $campaign, array $affiliates): float
{
    $eligible = array_filter($affiliates, static fn($a) => $campaign['group'] === 'all' || $a['group'] === $campaign['group']);
    if (($campaign['metric'] ?? 'revenue') === 'orders') return (float)array_sum(array_column($eligible, 'orders'));
    return (float)array_sum(array_column($eligible, 'sales'));
}

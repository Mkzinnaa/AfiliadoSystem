<?php
declare(strict_types=1);

require_once __DIR__ . '/tenancy.php';
require_once __DIR__ . '/database.php';

function affiliates_file(): string
{
    return tenant_storage_file('affiliates.json');
}

function affiliate_seed_data(): array
{
    return [
        ['id' => 'af-1001', 'name' => 'João Oliveira', 'email' => 'joao@email.com', 'group' => 'Elite', 'commission' => 20, 'status' => 'active', 'sales' => 12450, 'orders' => 42, 'code' => 'JOAO-OLIVEIRA'],
        ['id' => 'af-1002', 'name' => 'Lucas Martins', 'email' => 'lucas@email.com', 'group' => 'Profissionais', 'commission' => 20, 'status' => 'active', 'sales' => 8750, 'orders' => 31, 'code' => 'LUCAS-MARTINS'],
        ['id' => 'af-1003', 'name' => 'Pedro Santos', 'email' => 'pedro@email.com', 'group' => 'Profissionais', 'commission' => 20, 'status' => 'pending', 'sales' => 6300, 'orders' => 24, 'code' => 'PEDRO-SANTOS'],
        ['id' => 'af-1004', 'name' => 'Ana Ferreira', 'email' => 'ana@email.com', 'group' => 'Elite', 'commission' => 25, 'status' => 'active', 'sales' => 4890, 'orders' => 19, 'code' => 'ANA-FERREIRA'],
        ['id' => 'af-1005', 'name' => 'Beatriz Lima', 'email' => 'beatriz@email.com', 'group' => 'Novos afiliados', 'commission' => 15, 'status' => 'inactive', 'sales' => 1250, 'orders' => 5, 'code' => 'BEATRIZ-LIMA'],
    ];
}

function affiliate_read_all(): array
{
    $pdo = app_db(); $tenant = tenant_id();
    $query = $pdo->prepare('SELECT id,name,email,affiliate_group AS "group",commission,status,sales,orders,code,hotmart_code FROM affiliates WHERE tenant_id=? ORDER BY name');
    $query->execute([$tenant]); $rows = $query->fetchAll();
    if ($rows) return $rows;
    $legacy = [];
    if ($tenant === DEMO_USER['tenant_id']) {
        $file = affiliates_file(); $legacyFile = __DIR__ . '/../storage/affiliates.json';
        foreach ([$file,$legacyFile] as $source) {
            if (is_file($source)) { $data=json_decode((string)file_get_contents($source),true); if (is_array($data) && $data) { $legacy=$data; break; } }
        }
        if (!$legacy) $legacy = affiliate_seed_data();
    }
    if ($legacy) { affiliate_write_all($legacy); return $legacy; }
    return [];
}

function affiliate_write_all(array $affiliates): void
{
    $pdo=app_db();$tenant=tenant_id();$pdo->beginTransaction();
    try {
        $pdo->prepare('DELETE FROM affiliates WHERE tenant_id=?')->execute([$tenant]);
        $insert=$pdo->prepare('INSERT INTO affiliates(id,tenant_id,name,email,affiliate_group,commission,status,sales,orders,code,hotmart_code) VALUES(?,?,?,?,?,?,?,?,?,?,?)');
        foreach($affiliates as $a) $insert->execute([$a['id'],$tenant,$a['name'],$a['email'],$a['group'],(float)$a['commission'],$a['status'],(float)$a['sales'],(int)$a['orders'],$a['code'],trim((string)($a['hotmart_code']??''))?:null]);
        $pdo->commit();
    } catch(Throwable $error) { if($pdo->inTransaction())$pdo->rollBack(); throw $error; }
}

function affiliate_slug(string $name): string
{
    $ascii = iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $name);
    $slug = strtoupper(trim(preg_replace('/[^A-Za-z0-9]+/', '-', $ascii === false ? $name : $ascii), '-'));
    return $slug !== '' ? $slug : 'AFILIADO';
}

function affiliate_find(array $affiliates, string $id): ?array
{
    foreach ($affiliates as $affiliate) {
        if (($affiliate['id'] ?? '') === $id) return $affiliate;
    }
    return null;
}

function affiliate_initials(string $name): string
{
    $parts = preg_split('/\s+/', trim($name)) ?: [];
    $initials = '';
    foreach (array_slice($parts, 0, 2) as $part) {
        if (preg_match('/^./u', $part, $match)) {
            $initials .= $match[0];
        } else {
            $initials .= substr($part, 0, 1);
        }
    }
    return strtr(strtoupper($initials), ['á'=>'Á','é'=>'É','í'=>'Í','ó'=>'Ó','ú'=>'Ú','ã'=>'Ã','õ'=>'Õ','â'=>'Â','ê'=>'Ê','ô'=>'Ô','ç'=>'Ç']);
}

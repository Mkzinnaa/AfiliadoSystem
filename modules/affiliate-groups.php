<?php
declare(strict_types=1);
require_once __DIR__ . '/affiliates.php';

function affiliate_group_list(): array
{
    $pdo = app_db();
    $tenant = tenant_id();
    $existing = $pdo->prepare('SELECT COUNT(*) FROM affiliate_groups WHERE tenant_id=?');
    $existing->execute([$tenant]);
    if ((int)$existing->fetchColumn() === 0) {
        $query = $pdo->prepare('SELECT DISTINCT affiliate_group FROM affiliates WHERE tenant_id=? ORDER BY affiliate_group');
        $query->execute([$tenant]);
        $names = $query->fetchAll(PDO::FETCH_COLUMN);
        if (!$names) $names = ['Elite', 'Profissionais', 'Novos afiliados'];
        $insert = $pdo->prepare('INSERT IGNORE INTO affiliate_groups(id,tenant_id,name) VALUES(?,?,?)');
        foreach ($names as $name) $insert->execute([new_id('grp'), $tenant, (string)$name]);
    }
    $query = $pdo->prepare('SELECT g.id,g.name,g.description,COUNT(DISTINCT a.id) AS affiliate_count,COUNT(DISTINCT c.id) AS campaign_count FROM affiliate_groups g LEFT JOIN affiliates a ON a.tenant_id=g.tenant_id AND a.affiliate_group=g.name LEFT JOIN campaigns c ON c.tenant_id=g.tenant_id AND c.affiliate_group=g.name WHERE g.tenant_id=? GROUP BY g.tenant_id,g.id,g.name,g.description ORDER BY g.name');
    $query->execute([$tenant]);
    return $query->fetchAll();
}

function affiliate_group_save(string $id, string $name, string $description): void
{
    $name = trim($name);
    $description = trim($description);
    $length = preg_match_all('/./us', $name);
    if ($name === '' || $length === false || $length > 100 || strlen($description) > 255) throw new DomainException('Informe um nome de até 100 caracteres e uma descrição de até 255 caracteres.');
    $pdo = app_db();
    $tenant = tenant_id();
    if ($id === '') {
        try {
            $pdo->prepare('INSERT INTO affiliate_groups(id,tenant_id,name,description) VALUES(?,?,?,?)')->execute([new_id('grp'), $tenant, $name, $description]);
        } catch (PDOException $error) {
            if (str_contains(strtolower($error->getMessage()), 'unique')) throw new DomainException('Já existe um grupo com esse nome.');
            throw $error;
        }
        return;
    }

    $pdo->beginTransaction();
    try {
        $find = $pdo->prepare('SELECT name FROM affiliate_groups WHERE tenant_id=? AND id=? FOR UPDATE');
        $find->execute([$tenant, $id]);
        $oldName = $find->fetchColumn();
        if ($oldName === false) throw new DomainException('Grupo não encontrado neste espaço.');
        $duplicate = $pdo->prepare('SELECT 1 FROM affiliate_groups WHERE tenant_id=? AND name=? AND id<>? LIMIT 1');
        $duplicate->execute([$tenant, $name, $id]);
        if ($duplicate->fetchColumn()) throw new DomainException('Já existe um grupo com esse nome.');
        $pdo->prepare('UPDATE affiliate_groups SET name=?,description=? WHERE tenant_id=? AND id=?')->execute([$name, $description, $tenant, $id]);
        if ($oldName !== $name) {
            $pdo->prepare('UPDATE affiliates SET affiliate_group=? WHERE tenant_id=? AND affiliate_group=?')->execute([$name, $tenant, $oldName]);
            $pdo->prepare('UPDATE campaigns SET affiliate_group=? WHERE tenant_id=? AND affiliate_group=?')->execute([$name, $tenant, $oldName]);
        }
        $pdo->commit();
    } catch (Throwable $error) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        if ($error instanceof PDOException && str_contains(strtolower($error->getMessage()), 'unique')) throw new DomainException('Já existe um grupo com esse nome.');
        throw $error;
    }
}

function affiliate_group_delete(string $id): void
{
    $pdo = app_db();
    $tenant = tenant_id();
    $find = $pdo->prepare('SELECT name FROM affiliate_groups WHERE tenant_id=? AND id=?');
    $find->execute([$tenant, $id]);
    $name = $find->fetchColumn();
    if ($name === false) throw new DomainException('Grupo não encontrado neste espaço.');
    $used = $pdo->prepare('SELECT (SELECT COUNT(*) FROM affiliates WHERE tenant_id=? AND affiliate_group=?) + (SELECT COUNT(*) FROM campaigns WHERE tenant_id=? AND affiliate_group=?)');
    $used->execute([$tenant, $name, $tenant, $name]);
    if ((int)$used->fetchColumn() > 0) throw new DomainException('Este grupo ainda está associado a afiliados ou campanhas. Transfira-os para outro grupo antes de excluir.');
    $pdo->prepare('DELETE FROM affiliate_groups WHERE tenant_id=? AND id=?')->execute([$tenant, $id]);
}

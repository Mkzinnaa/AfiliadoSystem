<?php
declare(strict_types=1);
require_once __DIR__ . '/database.php';
require_once __DIR__ . '/tenancy.php';

function announcement_create(string $title, string $body, string $audienceType, string $group, string $affiliateId, string $createdBy): string
{
    $title = trim(preg_replace('/[\r\n]+/', ' ', $title) ?? '');
    $body = trim($body);
    $titleLength = preg_match_all('/./us', $title);
    $bodyLength = preg_match_all('/./us', $body);
    if ($title === '' || $titleLength === false || $titleLength > 120 || $body === '' || $bodyLength === false || $bodyLength > 5000) throw new DomainException('Informe um assunto e uma mensagem (até 5.000 caracteres).');
    if (!in_array($audienceType, ['all','group','affiliate'], true)) throw new DomainException('Selecione um público válido.');

    $pdo = app_db();
    $tenant = tenant_id();
    $sql = "SELECT id,email FROM affiliates WHERE tenant_id=? AND status='active'";
    $params = [$tenant];
    if ($audienceType === 'group') { $sql .= ' AND affiliate_group=?'; $params[] = $group; }
    if ($audienceType === 'affiliate') { $sql .= ' AND id=?'; $params[] = $affiliateId; }
    $sql .= ' ORDER BY email';
    $query = $pdo->prepare($sql);
    $query->execute($params);
    $recipients = $query->fetchAll();
    if (!$recipients) throw new DomainException('Não há afiliados ativos nesse público.');
    if (count($recipients) > 5000) throw new DomainException('Esse público ultrapassa o limite de 5.000 destinatários por comunicado.');

    $id = new_id('ann');
    $pdo->beginTransaction();
    try {
        $pdo->prepare('INSERT INTO announcements(id,tenant_id,title,body,audience_type,audience_group,audience_affiliate_id,recipient_count,created_by) VALUES(?,?,?,?,?,?,?,?,?)')
            ->execute([$id, $tenant, $title, $body, $audienceType, $audienceType === 'group' ? $group : '', $audienceType === 'affiliate' ? $affiliateId : null, count($recipients), $createdBy]);
        $insert = $pdo->prepare("INSERT INTO announcement_recipients(id,tenant_id,announcement_id,affiliate_id,email,status) VALUES(?,?,?,?,?,'queued')");
        foreach ($recipients as $recipient) $insert->execute([new_id('anr'), $tenant, $id, $recipient['id'], $recipient['email']]);
        $pdo->commit();
        return $id;
    } catch (Throwable $error) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        throw $error;
    }
}

function announcement_list(): array
{
    $query = app_db()->prepare("SELECT a.id,a.title,a.body,a.audience_type,a.audience_group,a.recipient_count,a.created_at,COALESCE(SUM(r.status='sent'),0) AS sent_count,COALESCE(SUM(r.status='failed'),0) AS failed_count,COALESCE(SUM(r.status IN ('queued','sending')),0) AS pending_count FROM announcements a LEFT JOIN announcement_recipients r ON r.tenant_id=a.tenant_id AND r.announcement_id=a.id WHERE a.tenant_id=? GROUP BY a.tenant_id,a.id,a.title,a.body,a.audience_type,a.audience_group,a.recipient_count,a.created_at ORDER BY a.created_at DESC LIMIT 50");
    $query->execute([tenant_id()]);
    return $query->fetchAll();
}

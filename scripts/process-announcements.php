<?php
declare(strict_types=1);
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
require_once __DIR__ . '/../modules/database.php';
require_once __DIR__ . '/../modules/mailer.php';

set_time_limit(0);
if (!app_mail_is_configured()) { fwrite(STDERR, "SMTP não configurado.\n"); exit(1); }
$pdo = app_db();
$pdo->exec("UPDATE announcement_recipients SET status='queued' WHERE status='sending' AND updated_at<DATE_SUB(CURRENT_TIMESTAMP,INTERVAL 15 MINUTE)");
$jobs = $pdo->query("SELECT r.id,r.tenant_id,r.announcement_id,r.email,r.attempts,a.title,a.body FROM announcement_recipients r JOIN announcements a ON a.tenant_id=r.tenant_id AND a.id=r.announcement_id WHERE r.status IN ('queued','failed') AND r.attempts<3 ORDER BY r.created_at LIMIT 20")->fetchAll();
$sent = 0;
$failed = 0;
foreach ($jobs as $job) {
    $claim = $pdo->prepare("UPDATE announcement_recipients SET status='sending',attempts=attempts+1,last_error='' WHERE id=? AND tenant_id=? AND status IN ('queued','failed') AND attempts=? AND attempts<3");
    $claim->execute([$job['id'],$job['tenant_id'],(int)$job['attempts']]);
    if ($claim->rowCount() !== 1) continue;
    try {
        $title = (string)$job['title'];
        $body = nl2br(htmlspecialchars((string)$job['body'], ENT_QUOTES, 'UTF-8'), false);
        $html = app_email_page($title, '<p>Olá!</p><div style="font-size:14px;line-height:1.7">' . $body . '</div><p style="margin-top:24px">Este comunicado foi enviado pelo programa de afiliados.</p>');
        app_send_email((string)$job['email'], $title, $html, "Olá!\n\n" . (string)$job['body'] . "\n\nEste comunicado foi enviado pelo programa de afiliados.");
        $pdo->prepare("UPDATE announcement_recipients SET status='sent',sent_at=CURRENT_TIMESTAMP,last_error='' WHERE id=? AND tenant_id=?")->execute([$job['id'],$job['tenant_id']]);
        $sent++;
    } catch (Throwable $error) {
        $attempts = (int)$job['attempts'] + 1;
        $status = $attempts >= 3 ? 'failed' : 'queued';
        $message = substr($error->getMessage(), 0, 500);
        $pdo->prepare('UPDATE announcement_recipients SET status=?,last_error=? WHERE id=? AND tenant_id=?')->execute([$status,$message,$job['id'],$job['tenant_id']]);
        error_log('[Vértice] Falha ao entregar comunicado a ' . $job['email'] . ': ' . $message);
        $failed++;
    }
}
fwrite(STDOUT, 'Processados: ' . count($jobs) . ' · enviados: ' . $sent . ' · falhas: ' . $failed . "\n");

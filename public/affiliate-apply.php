<?php
declare(strict_types=1);
require_once __DIR__ . '/../modules/affiliates.php';
require_once __DIR__ . '/../modules/auth.php';

start_app_session();
if (empty($_SESSION['affiliate_apply_csrf'])) $_SESSION['affiliate_apply_csrf'] = bin2hex(random_bytes(32));
$csrf = (string)$_SESSION['affiliate_apply_csrf'];
$slug = trim((string)($_GET['workspace'] ?? $_POST['workspace'] ?? ''));
$workspace = null;
if (preg_match('/^[a-z0-9-]{1,64}$/', $slug)) {
    $query = app_db()->prepare('SELECT id,name FROM tenants WHERE slug=? LIMIT 1');
    $query->execute([$slug]);
    $workspace = $query->fetch();
}
$error = '';
$submitted = false;
$name = trim((string)($_POST['name'] ?? ''));
$email = trim((string)($_POST['email'] ?? ''));
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!hash_equals($csrf, (string)($_POST['csrf'] ?? ''))) {
        http_response_code(403);
        exit('Sessão expirada. Atualize a página e tente novamente.');
    }
    if (!$workspace) {
        $error = 'Este programa de afiliados não está disponível.';
    } else {
        try {
            affiliate_apply($slug, $name, $email);
            $submitted = true;
            $name = $email = '';
        } catch (DomainException $exception) {
            $error = $exception->getMessage();
        } catch (Throwable $exception) {
            error_log('[AFFILIEY] Falha ao registrar inscrição de afiliado: ' . $exception->getMessage());
            $error = 'Não foi possível enviar sua inscrição agora. Tente novamente mais tarde.';
        }
    }
}
function apply_escape(mixed $value): string { return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8'); }
if (!$workspace) http_response_code(404);
?>
<!doctype html>
<html lang="pt-BR">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width,initial-scale=1">
    <meta name="robots" content="noindex,nofollow">
    <title>Seja afiliado<?= $workspace ? ' — ' . apply_escape($workspace['name']) : '' ?> | AFFILIEY</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=DM+Sans:wght@400;500;600;700&family=Manrope:wght@500;600;700;800&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="assets/css/affiliate-apply.css">
<link rel="manifest" href="/manifest.webmanifest"><meta name="apple-mobile-web-app-capable" content="yes"><meta name="apple-mobile-web-app-status-bar-style" content="default"><link rel="apple-touch-icon" sizes="180x180" href="/brand/affiliey-apple-touch-icon.png"><link rel="stylesheet" href="/assets/css/pwa.css"><script src="/assets/js/pwa.js" defer></script><link rel="icon" type="image/png" href="/brand/affiliey-favicon.png"><link rel="stylesheet" href="/assets/css/brand.css?v=affiliey4"><meta name="theme-color" content="#111827"><meta property="og:site_name" content="AFFILIEY"><meta name="description" content="Gestão de afiliados, vendas, metas e campanhas em uma plataforma."><meta property="og:description" content="Gestão de afiliados, vendas, metas e campanhas em uma plataforma."><meta property="og:type" content="website"><meta property="og:title" content="AFFILIEY | Plataforma de afiliados"><meta property="og:image" content="https://afiliados.horizoncafe.com.br/brand/affiliey-logo-light.png"><link rel="stylesheet" href="/assets/css/page-transitions.css?v=2"><script src="/assets/js/page-transitions.js?v=2" defer></script><link rel="stylesheet" href="/assets/css/special-pages-premium.css?v=1"><link rel="stylesheet" href="/assets/css/typography.css?v=1"></head>
<body class="affiliate-apply-page">
<main class="card">
    <a class="brand" href="login.php"><picture class="brand-picture"><source media="(max-width: 640px)" srcset="/brand/affiliey-symbol.png"><img class="brand-logo" src="/brand/affiliey-logo-light.png" alt="AFFILIEY"></picture></a>
    <?php if (!$workspace): ?>
        <div class="eyebrow">LINK INDISPONÍVEL</div><h1>Não encontramos este programa</h1>
        <p class="sub">Peça ao produtor um link de inscrição atualizado.</p>
    <?php elseif ($submitted): ?>
        <div class="success-icon">✓</div><div class="eyebrow">INSCRIÇÃO ENVIADA</div><h1>Obrigado pelo interesse!</h1>
        <p class="sub">Sua inscrição para o programa de <?= apply_escape($workspace['name']) ?> foi recebida. O produtor precisa aprovar seu cadastro antes que você possa divulgar seu link.</p>
    <?php else: ?>
        <div class="eyebrow">PROGRAMA DE AFILIADOS</div><h1>Divulgue <?= apply_escape($workspace['name']) ?></h1>
        <p class="sub">Preencha seus dados para solicitar participação. Após a aprovação, você receberá seu código individual de divulgação.</p>
        <?php if ($error): ?><div class="message" role="alert"><?= apply_escape($error) ?></div><?php endif; ?>
        <form method="post" autocomplete="on">
            <input type="hidden" name="csrf" value="<?= apply_escape($csrf) ?>">
            <input type="hidden" name="workspace" value="<?= apply_escape($slug) ?>">
            <label for="name">Nome completo</label><input id="name" name="name" autocomplete="name" maxlength="120" value="<?= apply_escape($name) ?>" required>
            <label for="email">E-mail</label><input id="email" name="email" type="email" autocomplete="email" maxlength="190" value="<?= apply_escape($email) ?>" required>
            <button type="submit">Enviar inscrição →</button>
        </form>
        <p class="privacy">Seus dados serão enviados ao produtor deste programa para análise da inscrição.</p>
    <?php endif; ?>
</main>
</body>
</html>

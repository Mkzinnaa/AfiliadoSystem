<?php
declare(strict_types=1);
require_once __DIR__ . '/../modules/auth.php';
require_login();

$user = current_user();
$profile = (string)($user['active_profile'] ?? 'affiliate');
$isStudent = $profile === 'affiliate';
if (!$isStudent && empty($user['tenant_id'])) {
    header('Location: workspace-create.php');
    exit;
}

$sections = app_workspace_sections($profile);
$sectionId = trim((string)($_GET['section'] ?? 'overview'));
$section = null;
foreach ($sections as $candidate) {
    if ($candidate['id'] === $sectionId) {
        $section = $candidate;
        break;
    }
}
if ($section === null) {
    http_response_code(404);
    $section = $sections[0];
}

$items = array_values(array_filter($section['items'], static function (array $item) use ($isStudent): bool {
    return $isStudent || empty($item['module']) || app_user_can((string)$item['module'], 'view');
}));
$escape = static fn(mixed $value): string => htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
$homeHref = $isStudent ? 'student-dashboard.php?view=dashboard' : 'dashboard.php';
$environment = $isStudent ? 'AMBIENTE DO ALUNO' : 'AMBIENTE DO PRODUTOR';
?>
<!doctype html>
<html lang="pt-BR">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <meta name="theme-color" content="#111827">
  <title><?= $escape($section['label']) ?> — AFFILIEY</title>
  <link rel="icon" href="/brand/affiliey-favicon.png">
  <link rel="stylesheet" href="/assets/css/app-theme.css?v=3">
  <link rel="stylesheet" href="/assets/css/brand.css?v=affiliey4">
  <link rel="stylesheet" href="/assets/css/workspace-premium.css?v=3">
  <link rel="stylesheet" href="/assets/css/profile-environments.css?v=3">
  <link rel="stylesheet" href="/assets/css/sliding-pill-nav.css?v=2">
  <link rel="stylesheet" href="/assets/css/section-hub.css?v=1">
  <link rel="stylesheet" href="/assets/css/typography.css?v=1">
  <script src="/assets/js/sliding-pill-nav.js?v=1" defer></script>
</head>
<body class="app-shell section-hub <?= $isStudent ? 'affiliate-portal' : '' ?>">
  <header class="topbar">
    <a class="brand" href="<?= $escape($homeHref) ?>" aria-label="AFFILIEY — início">
      <picture class="brand-picture"><source media="(max-width:640px)" srcset="/brand/affiliey-symbol.png"><img class="brand-logo" src="/brand/affiliey-logo-dark.png" alt="AFFILIEY"></picture>
    </a>
    <nav class="main-nav" aria-label="Seções principais"><?= app_workspace_navigation() ?></nav>
    <div class="top-actions"><?= app_header_account_tools() ?><span><?= $escape($user['name'] ?? '') ?></span><a href="login.php?logout=1">Sair</a></div>
  </header>
  <main class="hub-main">
    <div class="hub-breadcrumb"><a href="<?= $escape($homeHref) ?>">Início</a><span aria-hidden="true">/</span><b><?= $escape($section['label']) ?></b></div>
    <section class="hub-heading">
      <div class="hub-heading-icon" aria-hidden="true"><?= $escape($section['icon']) ?></div>
      <div><span class="hub-eyebrow"><?= $environment ?></span><h1><?= $escape($section['label']) ?></h1><p><?= $escape($section['description']) ?></p></div>
    </section>
    <?php if (!$items): ?>
      <section class="hub-empty"><span aria-hidden="true">◇</span><h2>Nenhum módulo disponível nesta seção</h2><p>O proprietário do espaço pode ajustar suas permissões de acesso.</p></section>
    <?php else: ?>
      <section class="hub-grid" aria-label="Módulos de <?= $escape($section['label']) ?>">
        <?php foreach ($items as $index => $item): ?>
          <a class="hub-card" href="<?= $escape($item['href']) ?>" style="--hub-delay:<?= min($index, 5) * 45 ?>ms">
            <span class="hub-card-icon" aria-hidden="true"><?= $escape($section['icon']) ?></span>
            <span class="hub-card-content"><strong><?= $escape($item['title']) ?></strong><small><?= $escape($item['description']) ?></small></span>
            <span class="hub-card-arrow" aria-hidden="true">↗</span>
          </a>
        <?php endforeach; ?>
      </section>
    <?php endif; ?>
  </main>
</body>
</html>

<?php
declare(strict_types=1);
require_once __DIR__ . '/../modules/auth.php';
require_once __DIR__ . '/../modules/affiliates.php';
require_login();
start_app_session();

if (empty($_SESSION['affiliate_csrf'])) $_SESSION['affiliate_csrf'] = bin2hex(random_bytes(32));
$csrf = $_SESSION['affiliate_csrf'];
$affiliates = affiliate_read_all();
$canEdit = can_manage_workspace();
$flash = '';
$error = '';
$editId = (string)($_GET['edit'] ?? '');
$editing = $editId !== '' ? affiliate_find($affiliates, $editId) : null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    require_role(['owner', 'admin', 'manager']);
    if (!hash_equals($csrf, (string)($_POST['csrf'] ?? ''))) {
        http_response_code(403);
        exit('Sessão expirada. Atualize a página e tente novamente.');
    }
    $action = (string)($_POST['action'] ?? '');
    $id = trim((string)($_POST['id'] ?? ''));
    if ($action === 'toggle') {
        foreach ($affiliates as &$row) {
            if ($row['id'] === $id) $row['status'] = $row['status'] === 'active' ? 'inactive' : 'active';
        }
        unset($row);
        affiliate_write_all($affiliates);
        header('Location: affiliates.php?message=status'); exit;
    }
    if ($action === 'save') {
        $name = trim((string)($_POST['name'] ?? ''));
        $email = filter_var(trim((string)($_POST['email'] ?? '')), FILTER_VALIDATE_EMAIL);
        $group = trim((string)($_POST['group'] ?? 'Novos afiliados'));
        $commission = filter_var($_POST['commission'] ?? '', FILTER_VALIDATE_FLOAT);
        $hotmartCode = trim((string)($_POST['hotmart_code'] ?? ''));
        if ($name === '' || !$email || $commission === false || $commission < 0 || $commission > 100 || strlen($hotmartCode) > 100 || ($hotmartCode !== '' && !preg_match('/^[A-Za-z0-9_-]+$/', $hotmartCode))) {
            $error = 'Preencha nome, e-mail válido, comissão entre 0 e 100% e um código Hotmart válido.';
        } else {
            foreach ($affiliates as $row) {
                if (strcasecmp($row['email'], (string)$email) === 0 && $row['id'] !== $id) $error = 'Já existe um afiliado cadastrado com este e-mail.';
                if ($hotmartCode !== '' && strcasecmp((string)($row['hotmart_code'] ?? ''), $hotmartCode) === 0 && $row['id'] !== $id) $error = 'Este código Hotmart já está vinculado a outro afiliado.';
            }
            if ($error === '') {
                if ($id !== '' && affiliate_find($affiliates, $id)) {
                    foreach ($affiliates as &$row) {
                        if ($row['id'] === $id) {
                            $row['name'] = $name; $row['email'] = (string)$email; $row['group'] = $group; $row['commission'] = (float)$commission; $row['hotmart_code'] = $hotmartCode;
                            if ($row['code'] === '') $row['code'] = affiliate_slug($name);
                        }
                    }
                    unset($row);
                    $flash = 'Afiliado atualizado.';
                } else {
                    $code = affiliate_slug($name);
                    $used = array_column($affiliates, 'code');
                    $base = $code; $suffix = 2;
                    while (in_array($code, $used, true)) $code = $base . '-' . $suffix++;
                    $affiliates[] = ['id' => 'af-' . bin2hex(random_bytes(5)), 'name' => $name, 'email' => (string)$email, 'group' => $group, 'commission' => (float)$commission, 'status' => 'active', 'sales' => 0, 'orders' => 0, 'code' => $code, 'hotmart_code' => $hotmartCode];
                    $flash = 'Afiliado cadastrado e ativado.';
                }
                affiliate_write_all($affiliates);
                header('Location: affiliates.php?message=' . ($id !== '' ? 'updated' : 'created')); exit;
            }
        }
        $editing = ['id' => $id, 'name' => $name, 'email' => (string)($_POST['email'] ?? ''), 'group' => $group, 'commission' => $commission === false ? 20 : $commission, 'hotmart_code' => $hotmartCode];
    }
}

$flash = match ((string)($_GET['message'] ?? '')) { 'created' => 'Afiliado cadastrado e ativado.', 'updated' => 'Afiliado atualizado.', 'status' => 'Status do afiliado atualizado.', default => $flash };
$search = trim((string)($_GET['q'] ?? ''));
$statusFilter = (string)($_GET['status'] ?? 'all');
$groupFilter = (string)($_GET['group'] ?? 'all');
$filtered = array_values(array_filter($affiliates, static function ($a) use ($search, $statusFilter, $groupFilter) {
    $matches = $search === '' || stripos($a['name'] . ' ' . $a['email'] . ' ' . $a['code'] . ' ' . ($a['hotmart_code'] ?? ''), $search) !== false;
    return $matches && ($statusFilter === 'all' || $a['status'] === $statusFilter) && ($groupFilter === 'all' || $a['group'] === $groupFilter);
}));
$groups = array_values(array_unique(array_map(static fn($a) => (string)$a['group'], $affiliates)));
$activeCount = count(array_filter($affiliates, static fn($a) => $a['status'] === 'active'));
$pendingCount = count(array_filter($affiliates, static fn($a) => $a['status'] === 'pending'));
$salesTotal = array_sum(array_column($affiliates, 'sales'));
$origin = (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off' ? 'https://' : 'http://') . ($_SERVER['HTTP_HOST'] ?? 'localhost');
function e($value): string { return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8'); }
?>
<!doctype html><html lang="pt-BR"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>Afiliados — Vértice</title>
<link rel="preconnect" href="https://fonts.googleapis.com"><link rel="preconnect" href="https://fonts.gstatic.com" crossorigin><link href="https://fonts.googleapis.com/css2?family=DM+Sans:wght@400;500;600;700&family=Manrope:wght@500;600;700;800&display=swap" rel="stylesheet">
<link rel="stylesheet" href="assets/css/affiliates.css"><link rel="stylesheet" href="assets/css/app-theme.css?v=2"></head><body class="app-shell">
<header class="top"><a class="brand" href="dashboard.php"><span class="brand-mark">v</span> vértice<span class="brand-dot">.</span></a><nav class="topnav"><a href="dashboard.php">Visão geral</a><a class="active" href="affiliates.php">Afiliados</a><a href="sales.php">Vendas</a><a href="campaigns.php">Metas</a><?php if(in_array(current_user()['role']??'', ['owner','admin'], true)): ?><a href="team.php">Equipe</a><a href="integrations.php">Integrações</a><?php endif; ?></nav><div class="right"><span><?= e(current_user()['name'] ?? 'Mariana Costa') ?></span><a href="login.php?logout=1">Sair</a></div></header>
<main class="wrap"><div class="crumb"><a class="back" href="dashboard.php">Workspace</a>　/　<b>Afiliados</b></div>
<section class="head"><div><h1>Gerenciamento de afiliados</h1><p>Cadastre, organize e acompanhe o desempenho do seu time.</p></div><div class="head-actions"><button class="btn" type="button" id="exportBtn">↓ Exportar CSV</button><?php if($canEdit): ?><button class="btn primary" type="button" id="newBtn">＋ Adicionar afiliado</button><?php endif; ?></div></section>
<?php if ($flash !== ''): ?><div class="flash"><?= e($flash) ?></div><?php endif; ?><?php if ($error !== ''): ?><div class="flash error"><?= e($error) ?></div><?php endif; ?>
<section class="metrics"><article class="metric"><span>Total de afiliados</span><b><?= count($affiliates) ?></b><small>Em todos os grupos</small></article><article class="metric"><span>Afiliados ativos</span><b><?= $activeCount ?></b><small><strong>Prontos para vender</strong></small></article><article class="metric"><span>Aguardando aprovação</span><b><?= $pendingCount ?></b><small>Revisar solicitações</small></article><article class="metric"><span>Vendas atribuídas</span><b>R$ <?= number_format($salesTotal, 0, ',', '.') ?></b><small>Acumulado da equipe</small></article></section>
<form class="toolbar" method="get"><label class="search"><span>⌕</span><input name="q" value="<?= e($search) ?>" placeholder="Buscar por nome, e-mail ou código..."></label><select name="group" onchange="this.form.submit()"><option value="all">Todos os grupos</option><?php foreach($groups as $group): ?><option value="<?= e($group) ?>" <?= $groupFilter === $group ? 'selected' : '' ?>><?= e($group) ?></option><?php endforeach; ?></select><select name="status" onchange="this.form.submit()"><option value="all" <?= $statusFilter==='all'?'selected':'' ?>>Todos os status</option><option value="active" <?= $statusFilter==='active'?'selected':'' ?>>Ativos</option><option value="pending" <?= $statusFilter==='pending'?'selected':'' ?>>Pendentes</option><option value="inactive" <?= $statusFilter==='inactive'?'selected':'' ?>>Inativos</option></select><button class="btn" type="submit">Filtrar</button></form>
<section class="table-wrap"><table><thead><tr><th>Afiliado</th><th>Grupo</th><th>Vendas</th><th>Comissão</th><th>Link / código</th><th>Status</th><th>Ações</th></tr></thead><tbody>
<?php if (!$filtered): ?><tr><td colspan="7" class="empty">Nenhum afiliado encontrado para esses filtros.</td></tr><?php endif; ?>
<?php foreach($filtered as $affiliate): $initials = affiliate_initials($affiliate['name']); $statusLabel = ['active'=>'Ativo','pending'=>'Pendente','inactive'=>'Inativo'][$affiliate['status']] ?? 'Ativo'; $link = $origin . '/?ref=' . rawurlencode($affiliate['code']); ?>
<tr><td><div class="affiliate"><span class="avatar"><?= e($initials) ?></span><span><b><?= e($affiliate['name']) ?></b><small><?= e($affiliate['email']) ?></small></span></div></td><td><span class="group"><?= e($affiliate['group']) ?></span></td><td>R$ <?= number_format((float)$affiliate['sales'], 2, ',', '.') ?><br><small style="color:#9aa59f"><?= (int)$affiliate['orders'] ?> vendas</small></td><td><?= e($affiliate['commission']) ?>%</td><td><button class="code copy-link" title="Copiar link" data-link="<?= e($link) ?>"><?= e($affiliate['code']) ?> ⧉</button><?php if (!empty($affiliate['hotmart_code'])): ?><br><small style="color:#9aa59f">Hotmart: <?= e($affiliate['hotmart_code']) ?></small><?php endif; ?></td><td><span class="badge <?= e($affiliate['status']) ?>"><?= e($statusLabel) ?></span></td><td><div class="row-actions"><a class="icon" style="display:grid;place-items:center;text-decoration:none" title="Editar" href="affiliates.php?edit=<?= rawurlencode($affiliate['id']) ?>">✎</a><form method="post" style="margin:0"><input type="hidden" name="csrf" value="<?= e($csrf) ?>"><input type="hidden" name="action" value="toggle"><input type="hidden" name="id" value="<?= e($affiliate['id']) ?>"><button class="icon" title="<?= $affiliate['status']==='active'?'Desativar':'Ativar' ?>" type="submit"><?= $affiliate['status']==='active'?'⏻':'✓' ?></button></form></div></td></tr>
<?php endforeach; ?></tbody></table></section>
<p class="note">Os dados de afiliados são armazenados separados por espaço. Valores de vendas desta demonstração são fictícios.</p></main>
<?php if($canEdit): ?><div class="overlay <?= ($editing !== null || $error !== '' || isset($_GET['new'])) ? 'show' : '' ?>" id="modal"><div class="modal"><div class="modalhead"><h2><?= $editing ? 'Editar afiliado' : 'Adicionar afiliado' ?></h2><button class="close" type="button" id="closeBtn">×</button></div><p>Preencha os dados para organizar seu programa de afiliados.</p><form method="post"><input type="hidden" name="csrf" value="<?= e($csrf) ?>"><input type="hidden" name="action" value="save"><input type="hidden" name="id" value="<?= e($editing['id'] ?? '') ?>"><div class="grid"><div class="field wide"><label for="name">Nome completo</label><input id="name" name="name" value="<?= e($editing['name'] ?? '') ?>" placeholder="Ex.: Camila Souza" required></div><div class="field wide"><label for="email">E-mail</label><input id="email" name="email" type="email" value="<?= e($editing['email'] ?? '') ?>" placeholder="camila@email.com" required></div><div class="field"><label for="group">Grupo</label><select id="group" name="group"><?php foreach(array_unique(array_merge(['Elite','Profissionais','Novos afiliados'],$groups)) as $g): ?><option <?= ($editing['group'] ?? 'Novos afiliados')===$g?'selected':'' ?>><?= e($g) ?></option><?php endforeach; ?></select></div><div class="field"><label for="commission">Comissão (%)</label><input id="commission" name="commission" type="number" min="0" max="100" step="0.5" value="<?= e($editing['commission'] ?? 20) ?>" required></div><div class="field wide"><label for="hotmart_code">Código do afiliado na Hotmart (opcional)</label><input id="hotmart_code" name="hotmart_code" maxlength="100" pattern="[A-Za-z0-9_-]+" value="<?= e($editing['hotmart_code'] ?? '') ?>" placeholder="Cole o affiliate_code da Hotmart"><small>Vincula automaticamente as vendas recebidas pela integração Hotmart.</small></div></div><div class="modalfoot"><a class="btn" href="affiliates.php">Cancelar</a><button class="btn primary" type="submit"><?= $editing ? 'Salvar alterações' : 'Cadastrar afiliado' ?></button></div></form></div></div><?php endif; ?>
<script src="assets/js/affiliates.js" defer></script></body></html>

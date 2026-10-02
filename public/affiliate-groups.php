<?php
declare(strict_types=1);
require_once __DIR__ . '/../modules/auth.php';
require_once __DIR__ . '/../modules/affiliate-groups.php';
require_login(); start_app_session();
if (empty($_SESSION['affiliate_groups_csrf'])) $_SESSION['affiliate_groups_csrf'] = bin2hex(random_bytes(32));
$csrf = (string)$_SESSION['affiliate_groups_csrf'];
$error = '';
$flash = '';
$editId = (string)($_GET['edit'] ?? '');
$editing = null;
$formName = '';
$formDescription = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    require_role(['owner','admin','manager']);
    if (!hash_equals($csrf, (string)($_POST['csrf'] ?? ''))) { http_response_code(403); exit('Sessão expirada. Atualize a página.'); }
    $action = (string)($_POST['action'] ?? 'save');
    $id = trim((string)($_POST['id'] ?? ''));
    try {
        if ($action === 'delete') {
            affiliate_group_delete($id);
            header('Location: affiliate-groups.php?message=deleted'); exit;
        }
        if ($action === 'save') {
            $formName = trim((string)($_POST['name'] ?? ''));
            $formDescription = trim((string)($_POST['description'] ?? ''));
            affiliate_group_save($id, $formName, $formDescription);
            header('Location: affiliate-groups.php?message=' . ($id === '' ? 'created' : 'updated')); exit;
        }
        $error = 'Ação inválida.';
    } catch (DomainException $exception) { $error = $exception->getMessage(); }
    catch (Throwable $exception) { error_log('[Vértice] Falha ao gerenciar grupos: ' . $exception->getMessage()); $error = 'Não foi possível salvar o grupo. Tente novamente.'; }
}
$groups = affiliate_group_list();
if ($editId !== '') foreach ($groups as $group) if ($group['id'] === $editId) { $editing = $group; break; }
if ($editing) { $formName = (string)$editing['name']; $formDescription = (string)$editing['description']; }
$flash = match ((string)($_GET['message'] ?? '')) { 'created'=>'Grupo criado.', 'updated'=>'Grupo atualizado.', 'deleted'=>'Grupo excluído.', default=>$flash };
function ge(mixed $value): string { return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8'); }
?>
<!doctype html><html lang="pt-BR"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>Grupos de afiliados — Vértice</title><link rel="preconnect" href="https://fonts.googleapis.com"><link rel="preconnect" href="https://fonts.gstatic.com" crossorigin><link href="https://fonts.googleapis.com/css2?family=DM+Sans:wght@400;500;600;700&family=Manrope:wght@500;600;700;800&display=swap" rel="stylesheet"><link rel="stylesheet" href="assets/css/affiliate-groups.css"><link rel="stylesheet" href="assets/css/app-theme.css?v=2"></head><body class="app-shell">
<header class="topbar"><a class="brand" href="dashboard.php"><span class="brand-mark">v</span> vértice<span class="brand-dot">.</span></a><nav class="main-nav"><a href="dashboard.php">Visão geral</a><a class="selected" href="affiliates.php">Afiliados</a><a href="sales.php">Vendas</a><a href="campaigns.php">Metas</a><a href="ranking.php">Ranking</a><a href="announcements.php">Comunicados</a><?php if(in_array(current_user()['role']??'', ['owner','admin'], true)): ?><a href="team.php">Equipe</a><a href="integrations.php">Integrações</a><?php endif; ?></nav><div class="top-actions"><span><?= ge(current_user()['name'] ?? '') ?></span><a href="login.php?logout=1">Sair</a></div></header>
<main class="groups-page"><div class="crumb"><a href="dashboard.php">Workspace</a> / <a href="affiliates.php">Afiliados</a> / Grupos</div><section class="intro"><div><span class="eyebrow">ORGANIZAÇÃO DO PROGRAMA</span><h1>Grupos e níveis</h1><p>Organize afiliados em categorias e direcione campanhas para cada grupo.</p></div><a class="button" href="affiliates.php">Voltar aos afiliados</a></section>
<?php if($flash): ?><div class="notice success"><?= ge($flash) ?></div><?php endif; ?><?php if($error): ?><div class="notice error"><?= ge($error) ?></div><?php endif; ?>
<div class="groups-layout"><section class="panel"><div class="panel-head"><div><h2>Seus grupos</h2><p><?= count($groups) ?> grupo(s) cadastrados neste espaço</p></div></div><?php if(!$groups): ?><p class="empty">Nenhum grupo cadastrado.</p><?php endif; ?><?php foreach($groups as $group): ?><article class="group-row"><div class="group-symbol">◎</div><div class="group-copy"><b><?= ge($group['name']) ?></b><p><?= ge($group['description'] ?: 'Sem descrição') ?></p><small><?= (int)$group['affiliate_count'] ?> afiliado(s) · <?= (int)$group['campaign_count'] ?> campanha(s)</small></div><div class="group-actions"><a class="button small" href="affiliate-groups.php?edit=<?= rawurlencode($group['id']) ?>">Editar</a><form method="post" onsubmit="return confirm('Excluir este grupo?')"><input type="hidden" name="csrf" value="<?= ge($csrf) ?>"><input type="hidden" name="action" value="delete"><input type="hidden" name="id" value="<?= ge($group['id']) ?>"><button class="button small danger" type="submit" <?= ((int)$group['affiliate_count'] + (int)$group['campaign_count']) > 0 ? 'disabled title="Transfira os afiliados e campanhas antes de excluir"' : '' ?>>Excluir</button></form></div></article><?php endforeach; ?></section>
<aside class="panel form-panel"><div class="panel-head"><div><h2><?= $editing ? 'Editar grupo' : 'Criar grupo' ?></h2><p>Os grupos ficam disponíveis nos cadastros e nas campanhas.</p></div></div><form method="post"><input type="hidden" name="csrf" value="<?= ge($csrf) ?>"><input type="hidden" name="action" value="save"><input type="hidden" name="id" value="<?= ge($editing['id'] ?? '') ?>"><label>Nome do grupo<input name="name" maxlength="100" value="<?= ge($formName) ?>" placeholder="Ex.: Elite" required></label><label>Descrição<textarea name="description" maxlength="255" rows="4" placeholder="Ex.: Afiliados com melhor desempenho"><?= ge($formDescription) ?></textarea></label><?php if($editing && ((int)$editing['affiliate_count'] || (int)$editing['campaign_count'])): ?><p class="hint">Ao renomear, os afiliados e campanhas associados serão atualizados automaticamente.</p><?php endif; ?><div class="form-actions"><?php if($editing): ?><a class="button" href="affiliate-groups.php">Cancelar</a><?php endif; ?><button class="button primary" type="submit"><?= $editing ? 'Salvar alterações' : 'Criar grupo' ?></button></div></form></aside></div></main></body></html>

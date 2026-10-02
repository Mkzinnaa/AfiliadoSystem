<?php
declare(strict_types=1);
require_once __DIR__ . '/../modules/auth.php';
require_once __DIR__ . '/../modules/affiliates.php';
require_once __DIR__ . '/../modules/affiliate-groups.php';
require_once __DIR__ . '/../modules/campaigns.php';
require_login(); start_app_session();
if (empty($_SESSION['campaign_csrf'])) $_SESSION['campaign_csrf'] = bin2hex(random_bytes(32));
$csrf = $_SESSION['campaign_csrf'];
$campaigns = campaigns_read_all(); $affiliates = affiliate_read_all();
$groups = array_column(affiliate_group_list(), 'name');
$error = ''; $flash = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    require_role(['owner', 'admin', 'manager']);
    if (!hash_equals($csrf, (string)($_POST['csrf'] ?? ''))) { http_response_code(403); exit('Sessão expirada. Atualize a página.'); }
    $action = (string)($_POST['action'] ?? ''); $id = (string)($_POST['id'] ?? '');
    if ($action === 'toggle') {
        foreach ($campaigns as &$campaign) if ($campaign['id'] === $id) $campaign['active'] = !$campaign['active'];
        unset($campaign); campaigns_write_all($campaigns); header('Location: campaigns.php?message=updated'); exit;
    }
    if ($action === 'create') {
        $title = trim((string)($_POST['title'] ?? '')); $metric = (string)($_POST['metric'] ?? 'revenue');
        $target = filter_var($_POST['target'] ?? '', FILTER_VALIDATE_FLOAT); $group = (string)($_POST['group'] ?? 'all');
        $start = (string)($_POST['start'] ?? ''); $end = (string)($_POST['end'] ?? ''); $reward = trim((string)($_POST['reward'] ?? ''));
        $validDates = $start !== '' && $end !== '' && strtotime($start) !== false && strtotime($end) !== false && $end >= $start;
        $titleLength = preg_match_all('/./us', $title, $titleChars);
        if ($title === '' || $titleLength === false || $titleLength > 80 || !in_array($metric,['revenue','orders'],true) || $target === false || $target <= 0 || !$validDates || ($group !== 'all' && !in_array($group,$groups,true))) {
            $error = 'Confira o nome, a meta, o grupo e as datas da campanha.';
        } else {
            $campaigns[] = ['id'=>'camp-'.bin2hex(random_bytes(5)),'title'=>$title,'metric'=>$metric,'target'=>(float)$target,'group'=>$group,'reward'=>$reward,'start'=>$start,'end'=>$end,'active'=>true];
            campaigns_write_all($campaigns); header('Location: campaigns.php?message=created'); exit;
        }
    }
}
$flash = match((string)($_GET['message'] ?? '')) {'created'=>'Campanha criada e ativada.','updated'=>'Status da campanha atualizado.',default=>$flash};
$money = static fn($n) => 'R$ '.number_format((float)$n,2,',','.');
$activeCampaigns = array_values(array_filter($campaigns, static fn($c) => !empty($c['active'])));
$primaryCampaign = $activeCampaigns[0] ?? null;
$ranking = $affiliates;
if ($primaryCampaign) {
    $ranking = array_values(array_filter($ranking, static fn($a) => $primaryCampaign['group'] === 'all' || $a['group'] === $primaryCampaign['group']));
    usort($ranking, static fn($a,$b) => ($primaryCampaign['metric']==='orders' ? (int)$b['orders'] <=>(int)$a['orders'] : (float)$b['sales'] <=>(float)$a['sales']));
}
function ce($v): string { return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8'); }
?>
<!doctype html><html lang="pt-BR"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>Metas e campanhas — Vértice</title><link rel="preconnect" href="https://fonts.googleapis.com"><link rel="preconnect" href="https://fonts.gstatic.com" crossorigin><link href="https://fonts.googleapis.com/css2?family=DM+Sans:wght@400;500;600;700&family=Manrope:wght@500;600;700;800&display=swap" rel="stylesheet"><link rel="stylesheet" href="assets/css/campaigns.css"><script src="assets/js/campaigns.js" defer></script><link rel="stylesheet" href="assets/css/app-theme.css?v=2"></head><body class="app-shell">
<header class="topbar"><a class="brand" href="dashboard.php"><span class="brand-mark">v</span> vértice<span class="brand-dot">.</span></a><nav class="main-nav"><a href="dashboard.php">Visão geral</a><a href="affiliates.php">Afiliados</a><a href="sales.php">Vendas</a><a class="selected" href="campaigns.php">Metas</a><?php if(in_array(current_user()['role']??'', ['owner','admin'], true)): ?><a href="team.php">Equipe</a><a href="integrations.php">Integrações</a><?php endif; ?></nav><div class="top-actions"><span><?= ce(current_user()['name'] ?? '') ?></span><a href="login.php?logout=1">Sair</a></div></header>
<main class="page"><div class="crumb"><a href="dashboard.php">Workspace</a> / Metas e campanhas</div><section class="welcome"><div><span class="eyebrow">CRESCIMENTO DA EQUIPE</span><h1>Metas e campanhas</h1><p>Defina objetivos, acompanhe o progresso e incentive seus afiliados.</p></div><?php if(can_manage_workspace()): ?><button class="button primary" id="newCampaign" type="button">＋ Criar campanha</button><?php endif; ?></section>
<?php if($flash): ?><div class="notice"><?= ce($flash) ?></div><?php endif; ?><?php if($error): ?><div class="notice error"><?= ce($error) ?></div><?php endif; ?>
<section class="summary"><article><span>Campanhas ativas</span><strong><?= count($activeCampaigns) ?></strong></article><article><span>Afiliados participantes</span><strong><?= count(array_filter($affiliates,static fn($a)=>$a['status']==='active')) ?></strong></article><article><span>Vendas atribuídas</span><strong><?= $money(array_sum(array_column($affiliates,'sales'))) ?></strong></article></section>
<div class="columns"><section class="campaign-list"><div class="section-title"><div><h2>Suas campanhas</h2><p>Metas coletivas acompanhadas pelo time</p></div></div>
<?php if(!$campaigns): ?><article class="campaign-card empty-card"><b>Nenhuma campanha criada ainda</b><p>Crie uma meta para engajar sua equipe de afiliados.</p><button class="text-button" type="button" onclick="document.getElementById('newCampaign').click()">Criar primeira campanha →</button></article><?php endif; ?>
<?php foreach($campaigns as $campaign): $progress=campaign_progress($campaign,$affiliates); $percent=min(100,(float)$campaign['target']>0?$progress/(float)$campaign['target']*100:0); $metricName=$campaign['metric']==='orders'?'vendas':'faturamento'; $achieved=$campaign['metric']==='orders'?number_format($progress,0,',','.'): $money($progress); $targetDisplay=$campaign['metric']==='orders'?number_format((float)$campaign['target'],0,',','.').' vendas':$money($campaign['target']); ?>
<article class="campaign-card"><div class="campaign-top"><span class="target-icon">◎</span><span class="campaign-title"><b><?= ce($campaign['title']) ?></b><small><?= $campaign['group']==='all'?'Todos os afiliados':ce($campaign['group']) ?> · <?= ce(date('d/m',strtotime($campaign['start']))) ?> a <?= ce(date('d/m/Y',strtotime($campaign['end']))) ?></small></span><span class="state <?= $campaign['active']?'on':'off' ?>"><?= $campaign['active']?'Ativa':'Pausada' ?></span></div><div class="target-row"><strong><?= ce($achieved) ?></strong><span>de <?= ce($targetDisplay) ?> em <?= ce($metricName) ?></span></div><div class="progress"><i style="width:<?= number_format($percent,1,'.','') ?>%"></i></div><div class="progress-foot"><b><?= number_format($percent,1,',','.') ?>% concluído</b><span><?= $campaign['reward']!==''?'Recompensa: '.ce($campaign['reward']):'Sem recompensa configurada' ?></span></div><?php if(can_manage_workspace()): ?><form method="post" class="campaign-actions"><input type="hidden" name="csrf" value="<?= ce($csrf) ?>"><input type="hidden" name="action" value="toggle"><input type="hidden" name="id" value="<?= ce($campaign['id']) ?>"><button class="text-button" type="submit"><?= $campaign['active']?'Pausar campanha':'Reativar campanha' ?></button></form><?php endif; ?></article>
<?php endforeach; ?></section>
<aside class="side-panel"><section class="panel ranking"><div class="panel-heading"><div><h2>Ranking da campanha</h2><p><?= $primaryCampaign?ce($primaryCampaign['title']):'Crie uma campanha para começar' ?></p></div></div><?php if(!$ranking): ?><p class="empty">Cadastre afiliados para ver o ranking.</p><?php endif; ?><?php foreach(array_slice($ranking,0,5) as $i=>$a): ?><div class="rank-row"><b class="rank-num <?= $i<3?'medal':'' ?>"><?= str_pad((string)($i+1),2,'0',STR_PAD_LEFT) ?></b><span class="rank-name"><strong><?= ce($a['name']) ?></strong><small><?= ce($a['group']) ?></small></span><b class="rank-value"><?= $primaryCampaign&&$primaryCampaign['metric']==='orders'?number_format((int)$a['orders'],0,',','.').' vendas':$money($a['sales']) ?></b></div><?php endforeach; ?></section><section class="tip"><span>✦</span><div><b>Uma meta clara move o time</b><p>Campanhas com objetivo, prazo e recompensa ajudam os afiliados a manter o foco.</p></div></section></aside></div>
<p class="footnote">O progresso é calculado com os dados de demonstração do módulo de afiliados. Integrações trarão vendas reais automaticamente.</p></main>
<?php if(can_manage_workspace()): ?><div class="overlay <?= $error?'show':'' ?>" id="campaignModal"><div class="modal"><div class="modal-head"><h2>Criar campanha</h2><button type="button" id="closeModal" class="close">×</button></div><p>Configure uma meta coletiva para seus afiliados.</p><form method="post"><input type="hidden" name="csrf" value="<?= ce($csrf) ?>"><input type="hidden" name="action" value="create"><div class="fields"><label class="wide">Nome da campanha<input name="title" maxlength="80" value="<?= ce($_POST['title']??'') ?>" placeholder="Ex.: Desafio de novembro" required></label><label>Tipo de meta<select name="metric"><option value="revenue">Faturamento</option><option value="orders">Número de vendas</option></select></label><label>Meta alvo<input name="target" type="number" min="1" step="<?= ($_POST['metric']??'revenue')==='orders'?'1':'0.01' ?>" placeholder="50000" value="<?= ce($_POST['target']??'') ?>" required></label><label>Grupo participante<select name="group"><option value="all">Todos os afiliados</option><?php foreach($groups as $group): ?><option value="<?= ce($group) ?>"><?= ce($group) ?></option><?php endforeach; ?></select></label><label>Recompensa (opcional)<input name="reward" maxlength="120" placeholder="Ex.: Bônus de R$ 500" value="<?= ce($_POST['reward']??'') ?>"></label><label>Início<input name="start" type="date" value="<?= ce($_POST['start']??date('Y-m-d')) ?>" required></label><label>Encerramento<input name="end" type="date" value="<?= ce($_POST['end']??date('Y-m-d',strtotime('+30 days'))) ?>" required></label></div><div class="modal-actions"><a class="button" href="campaigns.php">Cancelar</a><button class="button primary" type="submit">Criar campanha</button></div></form></div></div><?php endif; ?></body></html>

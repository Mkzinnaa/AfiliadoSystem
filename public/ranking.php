<?php
declare(strict_types=1);
require_once __DIR__ . '/../modules/auth.php';
require_once __DIR__ . '/../modules/affiliate-groups.php';
require_once __DIR__ . '/../modules/ranking.php';
require_login();
$metric = (string)($_GET['metric'] ?? 'revenue');
if (!in_array($metric, ['revenue','orders','new_customers','conversion','growth'], true)) $metric = 'revenue';
$start = (string)($_GET['start'] ?? date('Y-m-01'));
$end = (string)($_GET['end'] ?? date('Y-m-d'));
$group = (string)($_GET['group'] ?? 'all');
$groups = array_column(affiliate_group_list(), 'name');
if ($group !== 'all' && !in_array($group, $groups, true)) $group = 'all';
$startDate = DateTimeImmutable::createFromFormat('!Y-m-d', $start);
$endDate = DateTimeImmutable::createFromFormat('!Y-m-d', $end);
$error = '';
$ranking = [];
if (!$startDate || !$endDate || $startDate->format('Y-m-d') !== $start || $endDate->format('Y-m-d') !== $end || $start > $end || $startDate->diff($endDate)->days > 366) {
    $error = 'Selecione um período válido de até 367 dias.';
} else {
    $ranking = affiliate_ranking($metric, $start, $end, $group);
}
$labels = ['revenue'=>'Faturamento','orders'=>'Vendas','new_customers'=>'Clientes novos','conversion'=>'Conversão','growth'=>'Crescimento'];
$money = static fn($value) => 'R$ ' . number_format((float)$value, 2, ',', '.');
$displayValue = static function(array $affiliate) use ($metric, $money): string {
    return match ($metric) {
        'revenue' => $money($affiliate['ranking_revenue']),
        'orders' => number_format($affiliate['ranking_orders'], 0, ',', '.') . ' vendas',
        'new_customers' => number_format($affiliate['ranking_new_customers'], 0, ',', '.') . ' clientes',
        'conversion' => number_format($affiliate['ranking_conversion'], 2, ',', '.') . '%',
        'growth' => ($affiliate['ranking_value'] > 0 ? '+' : '') . number_format($affiliate['ranking_value'], 1, ',', '.') . '%',
    };
};
$totalRevenue = array_sum(array_column($ranking, 'ranking_revenue'));
$totalOrders = array_sum(array_column($ranking, 'ranking_orders'));
$topAffiliate = $ranking[0] ?? null;
function re(mixed $value): string { return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8'); }
?>
<!doctype html><html lang="pt-BR"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>Ranking — Vértice</title><link rel="preconnect" href="https://fonts.googleapis.com"><link rel="preconnect" href="https://fonts.gstatic.com" crossorigin><link href="https://fonts.googleapis.com/css2?family=DM+Sans:wght@400;500;600;700&family=Manrope:wght@500;600;700;800&display=swap" rel="stylesheet"><link rel="stylesheet" href="assets/css/ranking.css"><link rel="stylesheet" href="assets/css/app-theme.css?v=2"></head><body class="app-shell">
<header class="topbar"><a class="brand" href="dashboard.php"><span class="brand-mark">v</span> vértice<span class="brand-dot">.</span></a><nav class="main-nav"><a href="dashboard.php">Visão geral</a><a href="affiliates.php">Afiliados</a><a href="sales.php">Vendas</a><a href="campaigns.php">Metas</a><a class="selected" href="ranking.php">Ranking</a><a href="announcements.php">Comunicados</a><?php if(in_array(current_user()['role']??'', ['owner','admin'], true)): ?><a href="team.php">Equipe</a><a href="integrations.php">Integrações</a><?php endif; ?></nav><div class="top-actions"><span><?= re(current_user()['name'] ?? '') ?></span><a href="login.php?logout=1">Sair</a></div></header>
<main class="page ranking-page"><div class="crumb"><a href="dashboard.php">Workspace</a> / Ranking</div><section class="welcome"><div><span class="eyebrow">DESEMPENHO DA EQUIPE</span><h1>Ranking de afiliados</h1><p>Compare resultados por indicador, período e grupo.</p></div></section>
<form class="ranking-filters panel" method="get"><label>Métrica<select name="metric"><?php foreach($labels as $key=>$label): ?><option value="<?= re($key) ?>" <?= $metric===$key?'selected':'' ?>><?= re($label) ?></option><?php endforeach; ?></select></label><label>Início<input type="date" name="start" value="<?= re($start) ?>" required></label><label>Fim<input type="date" name="end" value="<?= re($end) ?>" required></label><label>Grupo<select name="group"><option value="all">Todos os grupos</option><?php foreach($groups as $name): ?><option value="<?= re($name) ?>" <?= $group===$name?'selected':'' ?>><?= re($name) ?></option><?php endforeach; ?></select></label><button class="button primary" type="submit">Atualizar ranking</button></form>
<?php if($error): ?><div class="notice error"><?= re($error) ?></div><?php endif; ?>
<section class="ranking-summary"><article class="panel"><span>Afiliados no ranking</span><strong><?= count($ranking) ?></strong></article><article class="panel"><span>Faturamento do período</span><strong><?= $money($totalRevenue) ?></strong></article><article class="panel"><span>Vendas aprovadas</span><strong><?= number_format($totalOrders,0,',','.') ?></strong></article></section>
<section class="panel ranking-table-panel"><div class="ranking-heading"><div><h2>Classificação por <?= re(strtolower($labels[$metric])) ?></h2><p><?= re(date('d/m/Y',strtotime($start))) ?> a <?= re(date('d/m/Y',strtotime($end))) ?><?= $group!=='all'?' · Grupo '.re($group):'' ?></p></div><?php if($topAffiliate): ?><span class="leader-chip">🏆 Destaque: <?= re($topAffiliate['name']) ?></span><?php endif; ?></div>
<?php if(!$ranking): ?><div class="empty-ranking"><span>↗</span><b>Nenhum resultado neste filtro</b><p>Receba vendas pelas integrações ou escolha outro grupo e período.</p></div><?php else: ?><div class="ranking-list"><?php foreach($ranking as $index=>$affiliate): $initialMatch=[];preg_match('/^./u',(string)$affiliate['name'],$initialMatch);$initial=strtoupper($initialMatch[0]??'A'); ?><article class="ranking-row <?= $index<3?'podium':'' ?>"><span class="place <?= $index<3?'medal':'' ?>"><?= $index===0?'🥇':($index===1?'🥈':($index===2?'🥉':str_pad((string)($index+1),2,'0',STR_PAD_LEFT))) ?></span><span class="avatar"><?= re($initial) ?></span><span class="affiliate-name"><b><?= re($affiliate['name']) ?></b><small><?= re($affiliate['group'] ?: 'Sem grupo') ?></small></span><span class="secondary-metrics"><span><?= $money($affiliate['ranking_revenue']) ?></span><small><?= number_format($affiliate['ranking_orders'],0,',','.') ?> vendas</small></span><strong class="ranking-score"><?= re($displayValue($affiliate)) ?></strong></article><?php endforeach; ?></div><?php endif; ?></section>
<p class="ranking-note">O ranking de produção usa pedidos aprovados recebidos pelas integrações. Clientes novos exigem identificador de cliente enviado pelo checkout; conversão usa cliques únicos registrados. No espaço demo, faturamento e vendas usam os valores ilustrativos dos afiliados.</p></main></body></html>

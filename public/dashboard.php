<?php
declare(strict_types=1);
require_once __DIR__ . '/../modules/auth.php';
require_once __DIR__ . '/../modules/affiliates.php';
require_once __DIR__ . '/../modules/campaigns.php';
require_login();
$user = current_user();
$affiliates = affiliate_read_all();
$salesTotal = array_sum(array_column($affiliates, 'sales'));
$commissionTotal = array_sum(array_map(static fn($a) => (float)$a['sales'] * (float)$a['commission'] / 100, $affiliates));
$activeCount = count(array_filter($affiliates, static fn($a) => $a['status'] === 'active'));
$pendingCount = count(array_filter($affiliates, static fn($a) => $a['status'] === 'pending'));
$ordersTotal = array_sum(array_column($affiliates, 'orders'));
$conversionOrders=app_db()->prepare("SELECT COUNT(*) FROM sales_orders WHERE tenant_id=? AND status='approved' AND affiliate_id IS NOT NULL");$conversionOrders->execute([tenant_id()]);$conversionOrderCount=(int)$conversionOrders->fetchColumn();
$conversionVisits=app_db()->prepare('SELECT COUNT(*) FROM (SELECT affiliate_id,visitor_hash FROM affiliate_clicks WHERE tenant_id=? GROUP BY affiliate_id,visitor_hash) unique_visitors');$conversionVisits->execute([tenant_id()]);$conversionVisitCount=(int)$conversionVisits->fetchColumn();
$conversionRate=$conversionVisitCount>0?($conversionOrderCount/$conversionVisitCount)*100:0;
$ranking = $affiliates;
usort($ranking, static fn($a, $b) => (float)$b['sales'] <=> (float)$a['sales']);
$campaigns = campaigns_read_all();
$activeCampaigns = array_values(array_filter($campaigns, static fn($campaign) => !empty($campaign['active'])));
$activeCampaign = $activeCampaigns[0] ?? null;
$goal = (float)($activeCampaign['target'] ?? 50000);
$goalValue = $activeCampaign ? campaign_progress($activeCampaign, $affiliates) : $salesTotal;
$goalProgress = min(100, $goal > 0 ? $goalValue / $goal * 100 : 0);
$goalName = (string)($activeCampaign['title'] ?? 'Meta da equipe');
$goalDisplay = ($activeCampaign['metric'] ?? 'revenue') === 'orders' ? number_format($goal, 0, ',', '.') . ' vendas' : 'R$ ' . number_format($goal, 2, ',', '.');
$goalValueDisplay = ($activeCampaign['metric'] ?? 'revenue') === 'orders' ? number_format($goalValue, 0, ',', '.') . ' vendas' : 'R$ ' . number_format($goalValue, 2, ',', '.');
$formatMoney = static fn($value) => 'R$ ' . number_format((float)$value, 2, ',', '.');
$chartLabels=['week'=>['25 set','26 set','27 set','28 set','29 set','30 set','01 out'],'month'=>['04 set','09 set','14 set','19 set','24 set','29 set','01 out'],'quarter'=>['jul','ago','ago','set','set','set','out']];
$chartAmounts=['week'=>[2400,3800,3200,5500,4700,6800,8200],'month'=>[3500,4800,4200,6300,5400,7400,9100],'quarter'=>[2800,4100,3800,5200,6500,5900,8800]];$chartTotals=['week'=>$salesTotal,'month'=>$salesTotal,'quarter'=>$salesTotal];$chartScale=10000;
$ordersStmt=app_db()->prepare("SELECT sold_at,amount_cents FROM sales_orders WHERE tenant_id=? AND status='approved' AND sold_at>=DATE_SUB(CURRENT_DATE, INTERVAL 95 DAY)");$ordersStmt->execute([tenant_id()]);$integratedSales=$ordersStmt->fetchAll();
if($integratedSales){$timezone=new DateTimeZone(date_default_timezone_get());$today=new DateTimeImmutable('today',$timezone);$ranges=['week'=>7,'month'=>30,'quarter'=>90];$chartAmounts=[];$chartTotals=[];$maximum=0.0;
    foreach($ranges as $periodKey=>$days){$buckets=array_fill(0,7,0.0);$bucketDates=[];for($i=0;$i<7;$i++){$offset=(int)floor(($i+1)*$days/7)-1;$bucketDates[]=$today->modify('-'.($days-1-$offset).' days');}
        foreach($integratedSales as $order){$utc=new DateTimeImmutable((string)$order['sold_at'],new DateTimeZone('UTC'));$local=$utc->setTimezone($timezone)->setTime(0,0);$age=(int)$today->diff($local)->format('%r%a');if($age<0||$age>=$days)continue;$index=min(6,(int)floor(($days-1-$age)*7/$days));$buckets[$index]+=(int)$order['amount_cents']/100;}
        $chartTotals[$periodKey]=array_sum($buckets);$maximum=max($maximum,...$buckets);$chartLabels[$periodKey]=array_map(static fn($d)=>$d->format('d/m'),$bucketDates);$chartAmounts[$periodKey]=$buckets;
    }
    $chartScale=max(1000,ceil($maximum*1.15/1000)*1000);
}
$chartValues=[];foreach($chartAmounts as $periodKey=>$amounts)$chartValues[$periodKey]=array_map(static fn($amount)=>$chartScale>0?min(100,round($amount/$chartScale*100,1)):0,$amounts);
?>
<!doctype html>
<html lang="pt-BR"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1"><title>Visão geral — AFFILIEY</title>
<link rel="preconnect" href="https://fonts.googleapis.com"><link rel="preconnect" href="https://fonts.gstatic.com" crossorigin><link href="https://fonts.googleapis.com/css2?family=DM+Sans:wght@400;500;600;700&family=Manrope:wght@500;600;700;800&display=swap" rel="stylesheet">
<link rel="stylesheet" href="assets/css/dashboard.css"><script src="assets/js/dashboard.js" defer></script><link rel="stylesheet" href="assets/css/app-theme.css?v=3"><link rel="manifest" href="/manifest.webmanifest"><meta name="apple-mobile-web-app-capable" content="yes"><meta name="apple-mobile-web-app-status-bar-style" content="default"><link rel="apple-touch-icon" sizes="180x180" href="/brand/affiliey-apple-touch-icon.png"><link rel="stylesheet" href="/assets/css/pwa.css"><script src="/assets/js/pwa.js" defer></script><link rel="icon" type="image/png" href="/brand/affiliey-favicon.png"><link rel="stylesheet" href="/assets/css/brand.css?v=affiliey4"><link rel="stylesheet" href="/assets/css/workspace-premium.css?v=1"><link rel="stylesheet" href="/assets/css/sliding-pill-nav.css?v=2"><script src="/assets/js/sliding-pill-nav.js?v=1" defer></script><link rel="stylesheet" href="/assets/css/dashboard-premium.css?v=1"><meta name="theme-color" content="#111827"><meta property="og:site_name" content="AFFILIEY"><meta name="description" content="Gestão de afiliados, vendas, metas e campanhas em uma plataforma."><meta property="og:description" content="Gestão de afiliados, vendas, metas e campanhas em uma plataforma."><meta property="og:type" content="website"><meta property="og:title" content="AFFILIEY | Plataforma de afiliados"><meta property="og:image" content="https://afiliados.horizoncafe.com.br/brand/affiliey-logo-light.png"><link rel="stylesheet" href="/assets/css/page-transitions.css?v=2"><script src="/assets/js/page-transitions.js?v=2" defer></script></head><body class="app-shell dashboard-shell">
<header class="topbar"><a class="brand" href="dashboard.php"><picture class="brand-picture"><source media="(max-width: 640px)" srcset="/brand/affiliey-symbol.png"><img class="brand-logo" src="/brand/affiliey-logo-dark.png" alt="AFFILIEY"></picture></a><nav class="main-nav"><a class="selected" href="dashboard.php">Visão geral</a><a href="affiliates.php">Afiliados</a><a href="sales.php">Vendas</a><a href="campaigns.php">Metas</a><a href="ranking.php">Ranking</a><a href="rewards.php">Recompensas</a><a href="announcements.php">Comunicados</a><?php if(in_array(($user['role']??''),['owner','admin'],true)): ?><a href="team.php">Equipe</a><a href="integrations.php">Integrações</a><?php endif; ?></nav><div class="top-actions"><span class="hello"><?= htmlspecialchars((string)$user['name'], ENT_QUOTES, 'UTF-8') ?></span><a class="logout" href="login.php?logout=1">Sair</a></div></header>
<main class="page">
  <section class="welcome"><div><span class="eyebrow">PAINEL DO PRODUTOR</span><h1>Visão geral da operação</h1><p>Acompanhe o desempenho do seu programa de afiliados.</p></div><a class="button primary" href="affiliates.php">＋ Gerenciar afiliados</a></section>
  <section class="stats" aria-label="Indicadores principais">
    <article class="stat-card"><div class="stat-label">Vendas atribuídas <span class="stat-icon">↗</span></div><strong><?= $formatMoney($salesTotal) ?></strong><small>Faturamento da equipe</small></article>
    <article class="stat-card"><div class="stat-label">Comissões estimadas <span class="stat-icon">◇</span></div><strong><?= $formatMoney($commissionTotal) ?></strong><small>Com base nas comissões cadastradas</small></article>
    <article class="stat-card"><div class="stat-label">Afiliados ativos <span class="stat-icon">♙</span></div><strong><?= $activeCount ?></strong><small><?= $pendingCount ?> aguardando aprovação</small></article>
    <article class="stat-card"><div class="stat-label">Vendas concluídas <span class="stat-icon">▤</span></div><strong><?= number_format((int)$ordersTotal, 0, ',', '.') ?></strong><small>Pedidos atribuídos aos afiliados</small></article>
    <article class="stat-card"><div class="stat-label">Conversão estimada <span class="stat-icon">%</span></div><strong><?= number_format($conversionRate, 2, ',', '.') ?>%</strong><small><?= number_format($conversionOrderCount,0,',','.') ?> pedidos aprovados ÷ <?= number_format($conversionVisitCount,0,',','.') ?> visitas únicas</small></article>
  </section>
  <section class="dashboard-grid">
    <article class="panel chart-panel"><div class="panel-head"><div><h2>Movimento de vendas</h2><p><?= $integratedSales?'Pedidos aprovados recebidos pela Kiwify':'Série ilustrativa até a primeira integração' ?></p></div><select id="chartPeriod" aria-label="Período do gráfico"><option value="week">Últimos 7 dias</option><option value="month">Últimos 30 dias</option><option value="quarter">Últimos 90 dias</option></select></div>
      <div class="chart-legend"><span><i class="legend-sales"></i>Vendas</span><strong id="chartTotal"><?= $formatMoney($chartTotals['week']) ?> <small id="chartTotalLabel"><?= $integratedSales?'total no período':'total atribuído' ?></small></strong></div>
      <div class="chart" id="salesChart" data-week='<?= htmlspecialchars(json_encode($chartValues['week']),ENT_QUOTES,'UTF-8') ?>' data-month='<?= htmlspecialchars(json_encode($chartValues['month']),ENT_QUOTES,'UTF-8') ?>' data-quarter='<?= htmlspecialchars(json_encode($chartValues['quarter']),ENT_QUOTES,'UTF-8') ?>' data-week-labels='<?= htmlspecialchars(json_encode($chartLabels['week']),ENT_QUOTES,'UTF-8') ?>' data-month-labels='<?= htmlspecialchars(json_encode($chartLabels['month']),ENT_QUOTES,'UTF-8') ?>' data-quarter-labels='<?= htmlspecialchars(json_encode($chartLabels['quarter']),ENT_QUOTES,'UTF-8') ?>' data-week-total="<?= number_format($chartTotals['week'],2,'.','') ?>" data-month-total="<?= number_format($chartTotals['month'],2,'.','') ?>" data-quarter-total="<?= number_format($chartTotals['quarter'],2,'.','') ?>" data-integrated="<?= $integratedSales?'1':'0' ?>">
        <div class="y-axis"><span><?= $formatMoney($chartScale) ?></span><span><?= $formatMoney($chartScale*.75) ?></span><span><?= $formatMoney($chartScale*.5) ?></span><span><?= $formatMoney($chartScale*.25) ?></span><span>R$ 0</span></div>
        <div class="plot"><div class="guides"><i></i><i></i><i></i><i></i><i></i></div><div class="bars" id="chartBars"><?php foreach($chartValues['week'] as $i=>$height): ?><div class="bar-item"><i style="height:<?= number_format((float)$height,1,'.','') ?>%"></i><span><?= htmlspecialchars((string)$chartLabels['week'][$i],ENT_QUOTES,'UTF-8') ?></span></div><?php endforeach; ?></div></div>
      </div><div class="demo-note"><?= $integratedSales?'O gráfico usa os valores das vendas aprovadas recebidas pela integração Kiwify.':'O gráfico usa uma série ilustrativa. Conecte a Kiwify para visualizar pedidos reais.' ?></div>
    </article>
    <article class="panel goal-panel"><div class="panel-head"><div><h2><?= htmlspecialchars($goalName, ENT_QUOTES, 'UTF-8') ?></h2><p>Meta coletiva ativa</p></div><span class="goal-badge">● Em andamento</span></div><div class="goal-value"><?= htmlspecialchars($goalValueDisplay, ENT_QUOTES, 'UTF-8') ?><small> de <?= htmlspecialchars($goalDisplay, ENT_QUOTES, 'UTF-8') ?></small></div><div class="progress-track"><i style="width:<?= number_format($goalProgress, 1, '.', '') ?>%"></i></div><div class="goal-meta"><span><?= number_format($goalProgress, 1, ',', '.') ?>% concluído</span><span><?= $activeCampaign ? htmlspecialchars(date('d/m',strtotime($activeCampaign['start'])).' – '.date('d/m/Y',strtotime($activeCampaign['end'])),ENT_QUOTES,'UTF-8') : 'Meta de vendas' ?></span></div><a class="text-link" href="campaigns.php">Ver campanhas →</a></article>
    <article class="panel ranking-panel"><div class="panel-head"><div><h2>Afiliados em destaque</h2><p>Ranking por faturamento</p></div><a class="text-link" href="affiliates.php">Ver todos →</a></div>
      <?php if (!$ranking): ?><p class="empty">Cadastre afiliados para começar seu ranking.</p><?php endif; ?>
      <?php foreach (array_slice($ranking, 0, 4) as $index => $affiliate): $initials = affiliate_initials((string)$affiliate['name']); ?>
      <div class="rank-row"><span class="rank-position <?= $index < 3 ? 'podium' : '' ?>"><?= str_pad((string)($index + 1), 2, '0', STR_PAD_LEFT) ?></span><span class="avatar"><?= htmlspecialchars($initials, ENT_QUOTES, 'UTF-8') ?></span><span class="rank-person"><b><?= htmlspecialchars((string)$affiliate['name'], ENT_QUOTES, 'UTF-8') ?></b><small><?= htmlspecialchars((string)$affiliate['group'], ENT_QUOTES, 'UTF-8') ?> · <?= (int)$affiliate['orders'] ?> vendas</small></span><strong class="rank-total"><?= $formatMoney($affiliate['sales']) ?></strong></div>
      <?php endforeach; ?>
    </article>
    <article class="panel quick-panel"><div class="panel-head"><div><h2>Atalhos</h2><p>Ações frequentes</p></div></div><a class="quick-link" href="affiliates.php?new=1"><span class="quick-icon">＋</span><span><b>Adicionar afiliado</b><small>Convide alguém para sua equipe</small></span><span class="arrow">→</span></a><a class="quick-link" href="affiliates.php?status=pending"><span class="quick-icon amber">◷</span><span><b>Aprovações pendentes</b><small><?= $pendingCount ?> solicitações para revisar</small></span><span class="arrow">→</span></a><a class="quick-link" href="affiliates.php"><span class="quick-icon blue">▤</span><span><b>Ver relatório da equipe</b><small>Consulte vendas e comissões</small></span><span class="arrow">→</span></a></article>
  </section>
  <footer class="footnote">Pedidos da Kiwify atualizam os afiliados reconhecidos. O espaço demo NovaVida ainda contém dados fictícios anteriores; crie um espaço novo para acompanhar uma operação real sem esses registros.</footer>
</main></body></html>

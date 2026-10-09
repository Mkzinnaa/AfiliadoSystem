<?php
declare(strict_types=1);
require_once __DIR__ . '/auth.php';

function affiliate_portal_scope_clause(array $links,string $alias): array
{
    if(!in_array($alias,['o','l','r'],true)) throw new InvalidArgumentException('Alias de escopo inválido.');
    $clauses=[];$params=[];
    foreach($links as $link) {
        $tenant=(string)($link['tenant_id']??'');$affiliate=(string)($link['affiliate_id']??'');
        if($tenant===''||$affiliate==='') throw new InvalidArgumentException('Todo vínculo precisa conter organização e afiliado.');
        $clauses[]="($alias.tenant_id=? AND $alias.affiliate_id=?)";$params[]=$tenant;$params[]=$affiliate;
    }
    return ['sql'=>$clauses?implode(' OR ',$clauses):'1=0','params'=>$params];
}

function affiliate_portal_links(string $userId): array
{
    $q=app_db()->prepare("SELECT l.tenant_id,l.affiliate_id,l.user_id,a.name AS affiliate_name,a.email,a.affiliate_group,a.commission,a.status,t.name AS producer_name FROM affiliate_account_links l JOIN affiliates a ON a.tenant_id=l.tenant_id AND a.id=l.affiliate_id JOIN tenants t ON t.id=l.tenant_id WHERE l.user_id=? AND l.status='active' AND a.status='active' ORDER BY t.name,a.name");
    $q->execute([$userId]); return $q->fetchAll();
}

function affiliate_portal_sales(array $links,int $limit=500,int $offset=0): array
{
    if (!$links) return [];
    $scope=affiliate_portal_scope_clause($links,'o');$params=$scope['params'];
    $limit=max(1,min(500,$limit));$offset=max(0,min(100000,$offset));
    $sql="SELECT o.tenant_id,o.affiliate_id,o.external_order_id,o.external_product_id,COALESCE(NULLIF(p.name,''),NULLIF(o.product_name,''),'Produto') AS product_name,o.amount_cents,CASE WHEN o.commission_source='platform' THEN o.commission_cents ELSE NULL END AS commission_cents,o.commission_source,o.currency,o.status,o.sold_at,t.name AS producer_name FROM sales_orders o JOIN integration_connections c ON c.id=o.connection_id AND c.tenant_id=o.tenant_id AND c.status='active' JOIN integration_products p ON p.tenant_id=o.tenant_id AND p.connection_id=o.connection_id AND p.external_product_id=o.external_product_id AND p.status='active' JOIN affiliate_account_links l ON l.tenant_id=o.tenant_id AND l.affiliate_id=o.affiliate_id AND l.user_id=? AND l.status='active' JOIN affiliates a ON a.tenant_id=l.tenant_id AND a.id=l.affiliate_id AND a.status='active' JOIN tenants t ON t.id=o.tenant_id WHERE (".$scope['sql'].") ORDER BY o.sold_at DESC LIMIT $limit OFFSET $offset";
    array_unshift($params, current_user()['id']);
    $q=app_db()->prepare($sql);$q->execute($params);return $q->fetchAll();
}

function affiliate_portal_summary(string $userId): array
{
    $q=app_db()->prepare("SELECT COUNT(*) AS total_count,SUM(o.status='approved') AS approved_count,SUM(CASE WHEN o.status='approved' THEN o.amount_cents ELSE 0 END) AS revenue_cents,SUM(o.status='pending') AS pending_count,SUM(o.status IN ('canceled','refunded')) AS cancelled_count,SUM(CASE WHEN o.status='approved' AND o.commission_source='platform' AND o.commission_cents IS NOT NULL THEN 1 ELSE 0 END) AS commission_count,SUM(CASE WHEN o.status='approved' AND o.commission_source='platform' THEN COALESCE(o.commission_cents,0) ELSE 0 END) AS commission_cents FROM sales_orders o JOIN integration_connections c ON c.id=o.connection_id AND c.tenant_id=o.tenant_id AND c.status='active' JOIN integration_products p ON p.tenant_id=o.tenant_id AND p.connection_id=o.connection_id AND p.external_product_id=o.external_product_id AND p.status='active' JOIN affiliate_account_links l ON l.tenant_id=o.tenant_id AND l.affiliate_id=o.affiliate_id AND l.user_id=? AND l.status='active' JOIN affiliates a ON a.tenant_id=l.tenant_id AND a.id=l.affiliate_id AND a.status='active' WHERE o.affiliate_id IS NOT NULL");$q->execute([$userId]);$row=$q->fetch()?:[];foreach(['total_count','approved_count','revenue_cents','pending_count','cancelled_count','commission_count','commission_cents'] as $key)$row[$key]=(int)($row[$key]??0);return $row;
}

function affiliate_portal_products(array $links): array
{
    if (!$links) return [];
    $scope=affiliate_portal_scope_clause($links,'l');$params=$scope['params'];
    $sql="SELECT DISTINCT p.external_product_id,p.name AS product_name,c.platform,t.name AS producer_name,t.slug AS workspace_slug,a.commission,a.code FROM integration_affiliate_links l JOIN affiliate_account_links al ON al.tenant_id=l.tenant_id AND al.affiliate_id=l.affiliate_id AND al.user_id=? AND al.status='active' JOIN integration_connections c ON c.id=l.connection_id AND c.tenant_id=l.tenant_id AND c.status='active' JOIN integration_products p ON p.tenant_id=l.tenant_id AND p.connection_id=l.connection_id AND p.status='active' JOIN affiliates a ON a.tenant_id=l.tenant_id AND a.id=l.affiliate_id JOIN tenants t ON t.id=l.tenant_id WHERE l.status='active' AND (".$scope['sql'].") AND EXISTS(SELECT 1 FROM sales_orders so WHERE so.tenant_id=l.tenant_id AND so.connection_id=l.connection_id AND so.external_product_id=p.external_product_id AND so.affiliate_id=l.affiliate_id) ORDER BY t.name,p.name";
    array_unshift($params,current_user()['id']);$q=app_db()->prepare($sql);$q->execute($params);return $q->fetchAll();
}

function affiliate_portal_announcements(array $links): array
{
    if (!$links) return [];$scope=affiliate_portal_scope_clause($links,'r');
    $q=app_db()->prepare('SELECT a.title,a.body,a.created_at,t.name AS producer_name FROM announcement_recipients r JOIN announcements a ON a.tenant_id=r.tenant_id AND a.id=r.announcement_id JOIN tenants t ON t.id=a.tenant_id WHERE ('.$scope['sql'].') ORDER BY a.created_at DESC LIMIT 100');$q->execute($scope['params']);return $q->fetchAll();
}

function affiliate_portal_campaign_progress(array $goal): ?float
{
    $tenant=(string)$goal['tenant_id'];$affiliate=(string)$goal['affiliate_id'];$start=(string)$goal['start_date'];$end=(string)$goal['end_date'];$metric=(string)$goal['metric'];
    if($metric==='conversion') {
        $orders=app_db()->prepare("SELECT COUNT(*) FROM sales_orders o JOIN integration_connections c ON c.id=o.connection_id AND c.tenant_id=o.tenant_id AND c.status='active' JOIN integration_products p ON p.tenant_id=o.tenant_id AND p.connection_id=o.connection_id AND p.external_product_id=o.external_product_id AND p.status='active' WHERE o.tenant_id=? AND o.affiliate_id=? AND o.status='approved' AND o.sold_at>=? AND o.sold_at<DATE_ADD(?,INTERVAL 1 DAY)");$orders->execute([$tenant,$affiliate,$start,$end]);
        $clicks=app_db()->prepare('SELECT COUNT(DISTINCT visitor_hash) FROM affiliate_clicks WHERE tenant_id=? AND affiliate_id=? AND click_date BETWEEN ? AND ?');$clicks->execute([$tenant,$affiliate,$start,$end]);$visits=(int)$clicks->fetchColumn();return $visits>0?(int)$orders->fetchColumn()/$visits*100:null;
    }
    $expression=match($metric){'orders'=>'COUNT(*)','new_customers'=>'COUNT(DISTINCT o.customer_hash)','revenue'=>'COALESCE(SUM(o.amount_cents),0)/100.0',default=>null};
    if($expression===null)return null;
    $q=app_db()->prepare("SELECT $expression FROM sales_orders o JOIN integration_connections c ON c.id=o.connection_id AND c.tenant_id=o.tenant_id AND c.status='active' JOIN integration_products p ON p.tenant_id=o.tenant_id AND p.connection_id=o.connection_id AND p.external_product_id=o.external_product_id AND p.status='active' WHERE o.tenant_id=? AND o.affiliate_id=? AND o.status='approved' AND o.sold_at>=? AND o.sold_at<DATE_ADD(?,INTERVAL 1 DAY)");$q->execute([$tenant,$affiliate,$start,$end]);return (float)$q->fetchColumn();
}

function affiliate_portal_public_rankings(array $links): array
{
    if(!$links)return [];$groups=[];$start=date('Y-m-01');$end=date('Y-m-d');$fromPrevious=(new DateTimeImmutable($start))->modify('-1 month')->format('Y-m-01');$toPrevious=(new DateTimeImmutable($start))->modify('-1 day')->format('Y-m-d');
    foreach($links as $link){$tenant=(string)$link['tenant_id'];$config=app_db()->prepare("SELECT setting_value FROM tenant_settings WHERE tenant_id=? AND setting_key='affiliate_ranking_public'");$config->execute([$tenant]);if($config->fetchColumn()!=='1')continue;$m=app_db()->prepare("SELECT setting_value FROM tenant_settings WHERE tenant_id=? AND setting_key='affiliate_ranking_metric'");$m->execute([$tenant]);$metric=(string)($m->fetchColumn()?:'revenue');if(!in_array($metric,['revenue','orders','new_customers','conversion','growth'],true))$metric='revenue';
        $a=app_db()->prepare("SELECT a.id,a.name,a.affiliate_group FROM affiliates a WHERE a.tenant_id=? AND a.status='active' ORDER BY a.name");$a->execute([$tenant]);$affiliates=$a->fetchAll();if(!$affiliates)continue;$ids=array_fill_keys(array_map(static fn($r)=>(string)$r['id'],$affiliates),true);
        $aggregate=static function(string $periodStart,string $periodEnd)use($tenant):array{$q=app_db()->prepare("SELECT o.affiliate_id,COALESCE(SUM(o.amount_cents),0)/100 AS revenue,COUNT(*) AS orders,COUNT(DISTINCT CASE WHEN o.customer_hash IS NOT NULL AND NOT EXISTS(SELECT 1 FROM sales_orders old WHERE old.tenant_id=o.tenant_id AND old.customer_hash=o.customer_hash AND old.status='approved' AND (old.sold_at<o.sold_at OR (old.sold_at=o.sold_at AND old.id<o.id))) THEN o.customer_hash ELSE NULL END) AS new_customers FROM sales_orders o JOIN integration_connections c ON c.id=o.connection_id AND c.tenant_id=o.tenant_id AND c.status='active' JOIN integration_products p ON p.tenant_id=o.tenant_id AND p.connection_id=o.connection_id AND p.external_product_id=o.external_product_id AND p.status='active' WHERE o.tenant_id=? AND o.affiliate_id IS NOT NULL AND o.status='approved' AND o.sold_at>=? AND o.sold_at<DATE_ADD(?,INTERVAL 1 DAY) GROUP BY o.affiliate_id");$q->execute([$tenant,$periodStart,$periodEnd]);$out=[];foreach($q->fetchAll() as $row)$out[(string)$row['affiliate_id']]=['revenue'=>(float)$row['revenue'],'orders'=>(int)$row['orders'],'new_customers'=>(int)$row['new_customers']];return $out;};
        $values=$aggregate($start,$end);$previous=$metric==='growth'?$aggregate($fromPrevious,$toPrevious):[];$visitors=[];
        if($metric==='conversion'){$q=app_db()->prepare('SELECT affiliate_id,COUNT(DISTINCT visitor_hash) AS total FROM affiliate_clicks WHERE tenant_id=? AND click_date BETWEEN ? AND ? GROUP BY affiliate_id');$q->execute([$tenant,$start,$end]);foreach($q->fetchAll() as $r)$visitors[(string)$r['affiliate_id']]=(int)$r['total'];}
        $rows=[];foreach($affiliates as $affiliate){$id=(string)$affiliate['id'];$v=$values[$id]??['revenue'=>0.0,'orders'=>0,'new_customers'=>0];$prev=(float)($previous[$id]['revenue']??0);$score=match($metric){'orders'=>$v['orders'],'new_customers'=>$v['new_customers'],'conversion'=>(($visitors[$id]??0)>0?$v['orders']/$visitors[$id]*100:0.0),'growth'=>$prev>0?(($v['revenue']-$prev)/$prev*100):0.0,default=>$v['revenue']};$rows[]=['affiliate_id'=>$id,'name'=>$affiliate['name'],'group'=>$affiliate['affiliate_group'],'value'=>(float)$score,'own'=>$id===(string)$link['affiliate_id']];}
        usort($rows,static fn($x,$y)=>$y['value']<=>$x['value']?:strcmp((string)$x['name'],(string)$y['name']));$rank=0;foreach($rows as $index=>&$row){if($index===0||$row['value']!==$rows[$index-1]['value'])$rank=$index+1;$row['position']=$rank;}unset($row);$groups[]=['producer'=>$link['producer_name'],'metric'=>$metric,'start'=>$start,'end'=>$end,'rows'=>$rows];
    }
    return $groups;
}

function affiliate_portal_achievements(array $links): array
{
    if(!$links)return [];$parts=[];$params=[];foreach($links as $link){$parts[]='(w.tenant_id=? AND w.affiliate_id=?)';$params[]=$link['tenant_id'];$params[]=$link['affiliate_id'];}
    $q=app_db()->prepare('SELECT w.title,w.metric,w.target,w.period_type,a.metric_value,a.status,a.unlocked_at,a.delivered_at,t.name AS producer_name FROM affiliate_reward_awards a JOIN affiliate_rewards w ON w.tenant_id=a.tenant_id AND w.id=a.reward_id JOIN tenants t ON t.id=a.tenant_id WHERE ('.implode(' OR ',$parts).') ORDER BY a.unlocked_at DESC LIMIT 100');$q->execute($params);return $q->fetchAll();
}

function affiliate_personal_goal_save(string $userId,array $input): void
{
    $title=trim((string)($input['title']??''));$metric=(string)($input['metric']??'');$target=filter_var($input['target']??null,FILTER_VALIDATE_FLOAT);$start=(string)($input['start_date']??'');$end=(string)($input['end_date']??'');
    $startDate=DateTimeImmutable::createFromFormat('!Y-m-d',$start);$endDate=DateTimeImmutable::createFromFormat('!Y-m-d',$end);
    $titleLength=preg_match_all('/./us',$title,$matches);
    if($title===''||$titleLength===false||$titleLength>120||!in_array($metric,['revenue','orders','new_customers'],true)||$target===false||$target<=0||!$startDate||!$endDate||$startDate->format('Y-m-d')!==$start||$endDate->format('Y-m-d')!==$end||$end<$start)throw new DomainException('Informe título, métrica, alvo positivo e período válido.');
    $links=affiliate_portal_links($userId);if(!$links)throw new DomainException('É necessário ter um vínculo ativo com um produtor.');
    $id=new_id('goal');app_db()->prepare('INSERT INTO affiliate_personal_goals(id,user_id,title,metric,target,start_date,end_date) VALUES(?,?,?,?,?,?,?)')->execute([$id,$userId,$title,$metric,(float)$target,$start,$end]);
    app_audit_record($userId,null,'affiliate.personal_goal_created','goal',$id,['metric'=>$metric,'target'=>(float)$target,'start_date'=>$start,'end_date'=>$end]);
}

function affiliate_portal_personal_goals(string $userId): array
{
    $q=app_db()->prepare('SELECT id,title,metric,target,start_date,end_date,created_at FROM affiliate_personal_goals WHERE user_id=? ORDER BY end_date DESC,created_at DESC');$q->execute([$userId]);$goals=$q->fetchAll();if(!$goals)return [];$links=affiliate_portal_links($userId);$scope=affiliate_portal_scope_clause($links,'o');
    foreach($goals as &$goal){$expression=match($goal['metric']){'orders'=>'COUNT(*)','new_customers'=>'COUNT(DISTINCT CASE WHEN o.customer_hash IS NOT NULL AND NOT EXISTS(SELECT 1 FROM sales_orders old WHERE old.tenant_id=o.tenant_id AND old.customer_hash=o.customer_hash AND old.status=\'approved\' AND (old.sold_at<o.sold_at OR (old.sold_at=o.sold_at AND old.id<o.id))) THEN o.customer_hash ELSE NULL END)','revenue'=>'COALESCE(SUM(o.amount_cents),0)/100.0'};$params=$scope['params'];array_unshift($params,$userId);array_push($params,$goal['start_date'],$goal['end_date']);$sql="SELECT $expression FROM sales_orders o JOIN integration_connections c ON c.id=o.connection_id AND c.tenant_id=o.tenant_id AND c.status='active' JOIN integration_products p ON p.tenant_id=o.tenant_id AND p.connection_id=o.connection_id AND p.external_product_id=o.external_product_id AND p.status='active' JOIN affiliate_account_links l ON l.tenant_id=o.tenant_id AND l.affiliate_id=o.affiliate_id AND l.user_id=? AND l.status='active' JOIN affiliates a ON a.tenant_id=l.tenant_id AND a.id=l.affiliate_id AND a.status='active' WHERE (".$scope['sql'].") AND o.status='approved' AND o.sold_at>=? AND o.sold_at<DATE_ADD(?,INTERVAL 1 DAY)";$progress=app_db()->prepare($sql);$progress->execute($params);$goal['progress']=(float)$progress->fetchColumn();}
    unset($goal);return $goals;
}

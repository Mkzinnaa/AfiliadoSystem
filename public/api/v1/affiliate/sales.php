<?php
declare(strict_types=1);
require_once __DIR__ . '/../bootstrap.php';
require_once __DIR__ . '/../../../../modules/affiliate-portal.php';
api_method('GET');$user=api_require_affiliate_auth();$links=affiliate_portal_links((string)$user['user_id']);$limit=max(1,min(100,(int)($_GET['limit']??25)));$offset=max(0,min(100000,(int)($_GET['offset']??0)));$rows=affiliate_portal_sales($links,$limit,$offset);$rows=array_map(static function(array $r):array{$r['amount']=(int)$r['amount_cents']/100;$r['commission']=$r['commission_cents']===null?null:(int)$r['commission_cents']/100;unset($r['tenant_id'],$r['affiliate_id'],$r['external_order_id'],$r['amount_cents'],$r['commission_cents'],$r['commission_source']);return $r;},$rows);$summary=affiliate_portal_summary((string)$user['user_id']);api_ok($rows,['total'=>$summary['total_count'],'limit'=>$limit,'offset'=>$offset]);

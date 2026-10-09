<?php
declare(strict_types=1);
require_once __DIR__ . '/../bootstrap.php';
require_once __DIR__ . '/../../../../modules/affiliate-portal.php';
api_method('GET');$user=api_require_affiliate_auth();$summary=affiliate_portal_summary((string)$user['user_id']);
api_ok(['metrics'=>['approved_sales'=>$summary['approved_count'],'revenue'=>$summary['revenue_cents']/100,'pending_sales'=>$summary['pending_count'],'cancelled_or_refunded_sales'=>$summary['cancelled_count'],'commission_reported'=>$summary['commission_cents']/100,'commission_transactions'=>$summary['commission_count']],'data_source'=>'Transações autenticadas de produtos ativos autorizados. Valores de comissão são exibidos somente quando enviados pela plataforma de origem.']);

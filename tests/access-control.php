<?php
declare(strict_types=1);

require_once __DIR__ . '/../modules/affiliate-portal.php';

$checks=0;
$check=static function(bool $condition,string $message)use(&$checks):void{if(!$condition)throw new RuntimeException('FAILED: '.$message);$checks++;};

$check(app_permission_default('owner','integrations','delete'),'owner defaults include all configured actions');
$check(app_permission_default('admin','integrations','edit'),'administrator starts with full permissions that owner can later override');
$check(app_permission_default('manager','affiliates','edit'),'manager may edit affiliates');
$check(!app_permission_default('manager','integrations','edit'),'manager cannot edit integrations by default');
$check(app_permission_default('analyst','sales','view'),'analyst can view sales metrics');
$check(!app_permission_default('analyst','sales','edit'),'analyst cannot alter sales');
$check(!app_permission_default('analyst','affiliates','view'),'analyst has no affiliate management access by default');
$check(app_permission_default('support','announcements','view'),'support can view communication module');
$check(app_permission_default('manager','materials','create'),'manager can publish affiliate materials');
$check(app_permission_default('manager','events','edit'),'manager can edit scheduled events');
$check(app_permission_default('support','support','reply'),'support role can respond to affiliate conversations');
$check(!app_permission_default('support','sales','view'),'support cannot view financial sales');
$check(!app_permission_default('support','integrations','view'),'support cannot inspect integrations');
$check(app_permission_default('viewer','dashboard','view'),'viewer may see dashboard');
$check(!app_permission_default('viewer','dashboard','edit'),'viewer cannot perform dashboard actions');

$scope=affiliate_portal_scope_clause([
    ['tenant_id'=>'org-joao','affiliate_id'=>'af-pedro'],
    ['tenant_id'=>'org-carlos','affiliate_id'=>'af-pedro'],
], 'o');
$check($scope['sql']==='(o.tenant_id=? AND o.affiliate_id=?) OR (o.tenant_id=? AND o.affiliate_id=?)','each affiliate scope binds organization and affiliate together');
$check($scope['params']===['org-joao','af-pedro','org-carlos','af-pedro'],'scope parameters preserve tenant and affiliate pairing');
$check(affiliate_portal_scope_clause([],'r')['sql']==='1=0','empty scope cannot match any records');
$thrown=false;try{affiliate_portal_scope_clause([['tenant_id'=>'org','affiliate_id'=>'af']], 'o; DROP TABLE sales_orders');}catch(InvalidArgumentException){$thrown=true;}
$check($thrown,'scope SQL alias is allowlisted');
$thrown=false;try{affiliate_portal_scope_clause([['tenant_id'=>'org']], 'o');}catch(InvalidArgumentException){$thrown=true;}
$check($thrown,'incomplete scope is rejected');

$_SERVER['SCRIPT_NAME']='/dashboard.php';
$check(app_permission_module_for_request()==='dashboard','producer route maps to permission module');
$_SERVER['SCRIPT_NAME']='/affiliate-dashboard.php';
$check(app_permission_module_for_request()===null,'affiliate route does not inherit producer module');
$_SERVER['SCRIPT_NAME']='/affiliates.php';$_SERVER['REQUEST_METHOD']='POST';$_POST=['action'=>'invite_access'];
$check(app_permission_action_for_request('affiliates')==='edit','affiliate account invitation requires edit permission');
$_SERVER['SCRIPT_NAME']='/materials.php';$_POST=['action'=>'save'];
$check(app_permission_module_for_request()==='materials'&&app_permission_action_for_request()==='create','new materials require create permission');
$_SERVER['SCRIPT_NAME']='/events.php';$_POST=['action'=>'cancel','id'=>'evt-1'];
$check(app_permission_module_for_request()==='events'&&app_permission_action_for_request()==='edit','event cancellations require edit permission');
$_SERVER['SCRIPT_NAME']='/support.php';$_POST=['action'=>'close','thread_id'=>'thread-1'];
$check(app_permission_module_for_request()==='support'&&app_permission_action_for_request()==='close','support closure requires close permission');

fwrite(STDOUT,"OK: $checks access-control assertions passed.\n");

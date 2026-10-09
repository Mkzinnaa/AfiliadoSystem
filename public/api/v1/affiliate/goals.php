<?php
declare(strict_types=1);
require_once __DIR__ . '/../bootstrap.php';
require_once __DIR__ . '/../../../../modules/affiliate-portal.php';
api_method('GET','POST');
$user=api_require_affiliate_auth();
if(($_SERVER['REQUEST_METHOD']??'GET')==='POST'){
    $body=api_json_body();
    try{affiliate_personal_goal_save((string)$user['user_id'],$body);api_json(['data'=>['created'=>true],'meta'=>(object)[],'error'=>null],201);}
    catch(DomainException $e){api_fail($e->getMessage(),422,'invalid_goal');}
}
api_ok(affiliate_portal_personal_goals((string)$user['user_id']));

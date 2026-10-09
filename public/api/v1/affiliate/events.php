<?php
declare(strict_types=1);
require_once __DIR__ . '/../bootstrap.php';
require_once __DIR__ . '/../../../../modules/affiliate-community.php';
api_method('GET','POST');
$user=api_require_affiliate_auth();
if(($_SERVER['REQUEST_METHOD']??'GET')==='POST'){
    $body=api_json_body();
    try{affiliate_event_respond((string)($body['event_id']??''),(string)($body['response']??''));api_ok(['updated'=>true]);}
    catch(DomainException $e){api_fail($e->getMessage(),422,'invalid_response');}
}
api_ok(affiliate_portal_events(affiliate_portal_links((string)$user['user_id'])));

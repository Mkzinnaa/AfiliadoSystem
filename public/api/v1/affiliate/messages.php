<?php
declare(strict_types=1);
require_once __DIR__ . '/../bootstrap.php';
require_once __DIR__ . '/../../../../modules/affiliate-community.php';
api_method('GET','POST');
$user=api_require_affiliate_auth();
try{
    if(($_SERVER['REQUEST_METHOD']??'GET')==='POST'){
        $body=api_json_body();
        if(($body['action']??'')==='new'){$thread=affiliate_support_create((string)$user['user_id'],(string)($body['subject']??''),(string)($body['body']??''),(string)($body['tenant_id']??''));api_json(['data'=>['thread_id'=>$thread],'meta'=>(object)[],'error'=>null],201);}
        if(($body['action']??'')==='reply'){affiliate_support_reply((string)($body['thread_id']??''),(string)($body['body']??''),'affiliate');api_ok(['sent'=>true]);}
        api_fail('Ação inválida.',422,'invalid_action');
    }
    $threads=affiliate_support_list_for_affiliate((string)$user['user_id']);
    $threadId=trim((string)($_GET['thread_id']??''));
    if($threadId!==''){$owned=false;foreach($threads as $thread)if($thread['id']===$threadId)$owned=true;if(!$owned)api_fail('Conversa não encontrada.',404,'not_found');api_ok(['thread'=>$threadId,'messages'=>affiliate_support_messages($threadId,true)]);}
    api_ok($threads);
}catch(DomainException $e){api_fail($e->getMessage(),422,'invalid_message');}

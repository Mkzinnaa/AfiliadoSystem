<?php
declare(strict_types=1);
require_once __DIR__ . '/../modules/integrations.php';
header('Content-Type: application/json; charset=utf-8');header('Cache-Control: no-store');
function webhook_reply(int $status,array $body):never{http_response_code($status);echo json_encode($body,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);exit;}
if($_SERVER['REQUEST_METHOD']!=='POST')webhook_reply(405,['ok'=>false,'message'=>'Use POST.']);
$raw=file_get_contents('php://input',false,null,0,1048577);if($raw===false||strlen($raw)>1048576)webhook_reply(413,['ok'=>false,'message'=>'Payload acima do limite de 1 MB.']);
$connectionId=(string)($_GET['connection']??'');$signature=(string)($_GET['signature']??'');if($connectionId===''||$signature==='')webhook_reply(401,['ok'=>false,'message'=>'Assinatura do webhook ausente.']);
try{$result=kiwify_handle_webhook($connectionId,$signature,$raw);webhook_reply(200,['ok'=>true,'duplicate'=>(bool)($result['duplicate']??false),'ignored'=>(bool)($result['ignored']??false),'message'=>$result['message']]);}
catch(DomainException $e){$code=$e->getMessage()==='Assinatura Kiwify inválida.'?401:400;webhook_reply($code,['ok'=>false,'message'=>$e->getMessage()]);}
catch(Throwable $e){webhook_reply(500,['ok'=>false,'message'=>'Falha ao processar o evento. A Kiwify poderá reenviar.']);}

<?php
declare(strict_types=1);
require_once __DIR__ . '/../modules/community-groups.php';
require_login();start_app_session();$user=current_user();
if(($user['active_profile']??'')!=='affiliate'){http_response_code(403);exit('Alterne para o ambiente Aluno para continuar.');}
if($_SERVER['REQUEST_METHOD']!=='POST'||empty($_SESSION['community_group_csrf'])||!hash_equals((string)$_SESSION['community_group_csrf'],(string)($_POST['csrf']??''))){http_response_code(403);exit('Sessão expirada. Atualize a página.');}
try{community_group_leave((string)($_POST['tenant_id']??''),(string)($_POST['group_id']??''),(string)$user['id']);header('Location: student-dashboard.php?view=groups&left=1');exit;}catch(DomainException $e){http_response_code(403);exit($e->getMessage());}

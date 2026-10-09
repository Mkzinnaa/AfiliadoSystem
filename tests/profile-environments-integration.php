<?php
declare(strict_types=1);

// Run with: php tests/profile-environments-integration.php
// Requires the normal AFFILIEY MySQL configuration. Creates uniquely named
// temporary accounts/workspaces and removes them in a finally block.
require_once __DIR__ . '/../modules/community-groups.php';
start_app_session();

function profile_test_assert(bool $condition,string $message): void
{
    if(!$condition)throw new RuntimeException('FAIL: '.$message);
}

function profile_test_as(array $user): void
{
    $_SESSION['affiliate_user']=$user;
}

$pdo=app_db();$users=[];$tenants=[];$suffix=bin2hex(random_bytes(5));
try{
    $student=create_user_account('Teste Aluno '.$suffix,'student-'.$suffix.'@example.test','StudentTest!2026#Pass');$users[]=$student['id'];
    profile_test_assert(in_array('affiliate',app_enabled_profiles($student),true)&&in_array('producer',app_enabled_profiles($student),true),'uma conta nova habilita os dois ambientes');
    profile_test_assert($student['active_profile']==='affiliate','conta nova inicia no ambiente Aluno');

    $producer=create_user_account('Teste Produtor '.$suffix,'producer-'.$suffix.'@example.test','ProducerTest!2026#Pass');$users[]=$producer['id'];
    $space=create_workspace_for_existing_user($producer['id'],'Teste Comunidade '.$suffix);$tenants[]=$space['tenant_id'];
    profile_test_assert($space['active_profile']==='producer','criação do espaço seleciona Produtor');
    $pdo->prepare("UPDATE users SET active_profile='affiliate' WHERE id=?")->execute([$producer['id']]);
    profile_test_assert($pdo->query("SELECT active_profile FROM users WHERE id=".$pdo->quote($producer['id']))->fetchColumn()==='affiliate','ambiente selecionado fica persistido');

    profile_test_as([...$space,'profiles'=>['producer','affiliate'],'active_profile'=>'producer']);
    $groupId=community_group_create(['name'=>'Grupo aberto '.$suffix,'description'=>'Grupo de teste','join_policy'=>'automatic']);
    $openInvite=community_group_issue_invite($groupId,3,2);
    community_resource_create(['group_id'=>$groupId,'resource_type'=>'announcement','title'=>'Aviso de teste','body'=>'Mensagem exclusiva do grupo']);

    profile_test_as($student);
    profile_test_assert(community_group_join($openInvite,$student['id'])==='active','convite automático cria participação ativa');
    profile_test_assert(community_group_join($openInvite,$student['id'])==='active','repetir convite não duplica participação');
    $studentGroups=community_group_list_for_student($student['id']);
    profile_test_assert(count(array_filter($studentGroups,static fn($g)=>$g['id']===$groupId))===1,'aluno vê seu grupo ativo exatamente uma vez');
    $feed=community_resource_list_for_student($space['tenant_id'],$groupId,$student['id']);
    profile_test_assert(count($feed)===1&&$feed[0]['title']==='Aviso de teste','conteúdo do grupo é visível ao participante');
    try{community_group_list_for_producer();throw new RuntimeException('aluno não deveria listar grupos administrativos');}catch(DomainException){/* expected */}

    $pendingId=community_group_create(['name'=>'Grupo com aprovação '.$suffix,'description'=>'','join_policy'=>'approval']);
    $pendingInvite=community_group_issue_invite($pendingId,3,1);
    profile_test_assert(community_group_join($pendingInvite,$student['id'])==='pending','grupo com aprovação mantém solicitação pendente');

    $other=create_user_account('Outro produtor '.$suffix,'other-'.$suffix.'@example.test','OtherTest!2026#Pass');$users[]=$other['id'];
    $otherSpace=create_workspace_for_existing_user($other['id'],'Outro espaço '.$suffix);$tenants[]=$otherSpace['tenant_id'];
    profile_test_as([...$otherSpace,'profiles'=>['producer','affiliate'],'active_profile'=>'producer']);
    $otherGroups=community_group_list_for_producer();
    profile_test_assert(!in_array($groupId,array_column($otherGroups,'id'),true),'produtor não lista grupos de outra organização');
    echo "OK: conta única, ambientes, persistência, convites, grupos, conteúdo e isolamento.\n";
}finally{
    foreach($tenants as $tenant)$pdo->prepare('DELETE FROM tenants WHERE id=?')->execute([$tenant]);
    foreach($users as $user)$pdo->prepare('DELETE FROM users WHERE id=?')->execute([$user]);
    $_SESSION=[];
}

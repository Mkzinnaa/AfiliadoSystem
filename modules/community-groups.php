<?php
declare(strict_types=1);

require_once __DIR__ . '/auth.php';

function community_text_length(string $value): int
{
    return preg_match_all('/./us',$value) ?: 0;
}

function community_assert_producer_access(string $tenantId, string $groupId, string $action): array
{
    $user=current_user();
    if(!$user||$tenantId===''||$groupId===''||!app_user_can('communities',$action,$user)) throw new DomainException('Você não tem permissão para este grupo.');
    $membership=app_db()->prepare('SELECT 1 FROM memberships WHERE tenant_id=? AND user_id=? LIMIT 1');
    $membership->execute([$tenantId,(string)$user['id']]);
    if(!$membership->fetchColumn()) throw new DomainException('Você não pertence a esta organização.');
    $group=app_db()->prepare('SELECT 1 FROM community_groups WHERE tenant_id=? AND id=? LIMIT 1');
    $group->execute([$tenantId,$groupId]);
    if(!$group->fetchColumn()) throw new DomainException('Grupo não encontrado.');
    return $user;
}

function community_group_list_for_student(string $userId): array
{
    if ((string)(current_user()['id'] ?? '') !== $userId) throw new DomainException('Acesso negado.');
    $query=app_db()->prepare("SELECT g.id,g.tenant_id,g.name,g.description,g.cover_url,g.allow_member_leave,m.status AS membership_status,m.joined_at,t.name AS producer_name FROM community_group_members m JOIN community_groups g ON g.tenant_id=m.tenant_id AND g.id=m.group_id JOIN tenants t ON t.id=g.tenant_id WHERE m.user_id=? AND m.status IN ('active','pending') AND g.active=1 ORDER BY m.status,g.name");
    $query->execute([$userId]); return $query->fetchAll();
}

function community_group_list_for_producer(?string $tenantId=null): array
{
    $user=current_user(); $tenantId??=(string)($user['tenant_id']??'');
    if($tenantId===''||!app_user_can('communities','view',$user)) throw new DomainException('Você não tem acesso a estes grupos.');
    $membership=app_db()->prepare('SELECT 1 FROM memberships WHERE tenant_id=? AND user_id=?'); $membership->execute([$tenantId,(string)$user['id']]);
    if(!$membership->fetchColumn()) throw new DomainException('Você não pertence a esta organização.');
    $query=app_db()->prepare("SELECT g.*,(SELECT COUNT(*) FROM community_group_members m WHERE m.tenant_id=g.tenant_id AND m.group_id=g.id AND m.status='active') AS member_count,(SELECT COUNT(*) FROM community_group_members m WHERE m.tenant_id=g.tenant_id AND m.group_id=g.id AND m.status='pending') AS pending_count FROM community_groups g WHERE g.tenant_id=? ORDER BY g.created_at DESC");
    $query->execute([$tenantId]); return $query->fetchAll();
}

function community_group_pending_members(string $groupId): array
{
    $user=current_user();$tenantId=(string)($user['tenant_id']??'');
    community_assert_producer_access($tenantId,$groupId,'view');
    $q=app_db()->prepare("SELECT m.user_id,u.name,u.email,m.created_at FROM community_group_members m JOIN users u ON u.id=m.user_id JOIN community_groups g ON g.tenant_id=m.tenant_id AND g.id=m.group_id WHERE m.tenant_id=? AND m.group_id=? AND m.status='pending' ORDER BY m.created_at");
    $q->execute([$tenantId,$groupId]);return $q->fetchAll();
}

function community_group_active_members(string $groupId): array
{
    $user=current_user();$tenantId=(string)($user['tenant_id']??'');
    community_assert_producer_access($tenantId,$groupId,'view');
    $q=app_db()->prepare("SELECT m.user_id,u.name,u.email,m.joined_at FROM community_group_members m JOIN users u ON u.id=m.user_id WHERE m.tenant_id=? AND m.group_id=? AND m.status='active' ORDER BY m.joined_at DESC");
    $q->execute([$tenantId,$groupId]);return $q->fetchAll();
}

function community_group_toggle(string $groupId): void
{
    $user=current_user();$tenantId=(string)($user['tenant_id']??'');
    community_assert_producer_access($tenantId,$groupId,'edit');
    $q=app_db()->prepare('UPDATE community_groups SET active=1-active WHERE tenant_id=? AND id=?');$q->execute([$tenantId,$groupId]);
    if(!$q->rowCount())throw new DomainException('Grupo não encontrado.');
    app_audit_record((string)$user['id'],$tenantId,'community.group_toggled','community_group',$groupId);
}

function community_group_update_settings(string $groupId,array $input): void
{
    $user=current_user();$tenantId=(string)($user['tenant_id']??'');
    community_assert_producer_access($tenantId,$groupId,'edit');
    $policy=(string)($input['join_policy']??'automatic');$active=isset($input['active'])&&(string)$input['active']==='1'?1:0;$allowLeave=isset($input['allow_member_leave'])&&(string)$input['allow_member_leave']==='1'?1:0;$cover=trim((string)($input['cover_url']??''));
    if(!in_array($policy,['automatic','approval'],true))throw new DomainException('Política de ingresso inválida.');
    if($cover!==''&&(!filter_var($cover,FILTER_VALIDATE_URL)||strtolower((string)parse_url($cover,PHP_URL_SCHEME))!=='https'))throw new DomainException('A capa deve usar um endereço HTTPS válido.');
    $q=app_db()->prepare('UPDATE community_groups SET join_policy=?,active=?,allow_member_leave=?,cover_url=? WHERE tenant_id=? AND id=?');$q->execute([$policy,$active,$allowLeave,$cover,$tenantId,$groupId]);
    if(!$q->rowCount()){$check=app_db()->prepare('SELECT 1 FROM community_groups WHERE tenant_id=? AND id=?');$check->execute([$tenantId,$groupId]);if(!$check->fetchColumn())throw new DomainException('Grupo não encontrado.');}
    app_audit_record((string)$user['id'],$tenantId,'community.group_settings_updated','community_group',$groupId,['active'=>$active,'join_policy'=>$policy,'allow_member_leave'=>$allowLeave]);
}

function community_group_create(array $input): string
{
    $user=current_user(); $tenantId=(string)($user['tenant_id']??'');
    if($tenantId===''||!app_user_can('communities','create',$user)) throw new DomainException('Sem permissão para criar grupos.');
    $name=trim((string)($input['name']??'')); $description=trim((string)($input['description']??''));
    $policy=(string)($input['join_policy']??'automatic'); $cover=trim((string)($input['cover_url']??''));
    if($name===''||community_text_length($name)>160) throw new DomainException('Informe um nome de grupo com até 160 caracteres.');
    if(community_text_length($description)>5000) throw new DomainException('A descrição deve ter até 5.000 caracteres.');
    if(!in_array($policy,['automatic','approval'],true)) throw new DomainException('Política de ingresso inválida.');
    if($cover!==''&&(!filter_var($cover,FILTER_VALIDATE_URL)||!in_array(strtolower((string)parse_url($cover,PHP_URL_SCHEME)),['https'],true))) throw new DomainException('A capa deve usar um endereço HTTPS válido.');
    $id=new_id('grp');
    app_db()->prepare('INSERT INTO community_groups(id,tenant_id,name,description,cover_url,join_policy,created_by) VALUES(?,?,?,?,?,?,?)')->execute([$id,$tenantId,$name,$description,$cover,$policy,(string)$user['id']]);
    app_audit_record((string)$user['id'],$tenantId,'community.group_created','community_group',$id,['join_policy'=>$policy]);
    return $id;
}

function community_group_issue_invite(string $groupId, int $days=30, ?int $maxUses=null): string
{
    $user=current_user(); $tenantId=(string)($user['tenant_id']??'');
    if($tenantId===''||!app_user_can('communities','edit',$user)) throw new DomainException('Sem permissão para gerar convites.');
    $days=max(1,min(365,$days)); if($maxUses!==null)$maxUses=max(1,min(100000,$maxUses));
    $check=app_db()->prepare('SELECT active FROM community_groups WHERE tenant_id=? AND id=?');$check->execute([$tenantId,$groupId]);
    if(!(int)$check->fetchColumn()) throw new DomainException('Grupo não encontrado ou inativo.');
    $token=bin2hex(random_bytes(32));
    $expires=(new DateTimeImmutable('+'.$days.' days',new DateTimeZone('UTC')))->format('Y-m-d H:i:s');
    app_db()->prepare('INSERT INTO community_group_invites(id,tenant_id,group_id,token_hash,expires_at,max_uses,created_by) VALUES(?,?,?,?,?,?,?)')->execute([new_id('ginv'),$tenantId,$groupId,hash('sha256',$token),$expires,$maxUses,(string)$user['id']]);
    app_audit_record((string)$user['id'],$tenantId,'community.invite_created','community_group',$groupId,['expires_in_days'=>$days,'limited'=>$maxUses!==null]);
    return $token;
}

function community_group_revoke_invite(string $groupId): void
{
    $user=current_user(); $tenantId=(string)($user['tenant_id']??'');
    community_assert_producer_access($tenantId,$groupId,'edit');
    app_db()->prepare('UPDATE community_group_invites SET revoked_at=UTC_TIMESTAMP() WHERE tenant_id=? AND group_id=? AND revoked_at IS NULL')->execute([$tenantId,$groupId]);
    app_audit_record((string)$user['id'],$tenantId,'community.invites_revoked','community_group',$groupId);
}

function community_group_invite_details(string $token): ?array
{
    if(!preg_match('/^[a-f0-9]{64}$/',$token))return null;
    $query=app_db()->prepare("SELECT i.id AS invite_id,i.tenant_id,i.group_id,g.name,g.description,g.join_policy,t.name AS producer_name FROM community_group_invites i JOIN community_groups g ON g.tenant_id=i.tenant_id AND g.id=i.group_id JOIN tenants t ON t.id=g.tenant_id WHERE i.token_hash=? AND i.revoked_at IS NULL AND (i.expires_at IS NULL OR i.expires_at>UTC_TIMESTAMP()) AND (i.max_uses IS NULL OR i.uses_count<i.max_uses) AND g.active=1 LIMIT 1");
    $query->execute([hash('sha256',$token)]);return $query->fetch()?:null;
}

function community_group_join(string $token,string $userId): string
{
    if(!preg_match('/^[a-f0-9]{64}$/',$token)||$userId===''||(string)(current_user()['id']??'')!==$userId)throw new DomainException('Convite inválido.');
    $pdo=app_db();$pdo->beginTransaction();
    try{
        $q=$pdo->prepare("SELECT i.*,g.join_policy,g.active FROM community_group_invites i JOIN community_groups g ON g.tenant_id=i.tenant_id AND g.id=i.group_id WHERE i.token_hash=? AND i.revoked_at IS NULL AND (i.expires_at IS NULL OR i.expires_at>UTC_TIMESTAMP()) AND (i.max_uses IS NULL OR i.uses_count<i.max_uses) FOR UPDATE");
        $q->execute([hash('sha256',$token)]);$invite=$q->fetch();
        if(!$invite||(int)$invite['active']!==1)throw new DomainException('Este convite expirou, atingiu o limite ou foi revogado.');
        $exists=$pdo->prepare('SELECT status FROM community_group_members WHERE tenant_id=? AND group_id=? AND user_id=? FOR UPDATE');$exists->execute([$invite['tenant_id'],$invite['group_id'],$userId]);$existingStatus=$exists->fetchColumn();
        if($existingStatus!==false && $existingStatus!=='rejected'){ $pdo->commit(); return (string)$existingStatus; }
        $status=$invite['join_policy']==='approval'?'pending':'active';
        if($existingStatus===false){$pdo->prepare('INSERT INTO community_group_members(tenant_id,group_id,user_id,member_role,status,joined_at) VALUES(?,?,?,\'student\',?,?)')->execute([$invite['tenant_id'],$invite['group_id'],$userId,$status,$status==='active'?gmdate('Y-m-d H:i:s'):null]);}
        else {$pdo->prepare('UPDATE community_group_members SET status=?,joined_at=? WHERE tenant_id=? AND group_id=? AND user_id=?')->execute([$status,$status==='active'?gmdate('Y-m-d H:i:s'):null,$invite['tenant_id'],$invite['group_id'],$userId]);}
        $pdo->prepare('UPDATE community_group_invites SET uses_count=uses_count+1 WHERE id=?')->execute([$invite['id']]);
        $pdo->commit();
        app_audit_record($userId,(string)$invite['tenant_id'],'community.group_joined','community_group',(string)$invite['group_id'],['status'=>$status]);
        return $status;
    }catch(Throwable $error){if($pdo->inTransaction())$pdo->rollBack();throw $error;}
}

function community_group_leave(string $tenantId,string $groupId,string $userId): void
{
    if((string)(current_user()['id']??'')!==$userId)throw new DomainException('Acesso negado.');
    $delete=app_db()->prepare("DELETE m FROM community_group_members m JOIN community_groups g ON g.tenant_id=m.tenant_id AND g.id=m.group_id WHERE m.tenant_id=? AND m.group_id=? AND m.user_id=? AND m.status='active' AND g.allow_member_leave=1");
    $delete->execute([$tenantId,$groupId,$userId]);if(!$delete->rowCount())throw new DomainException('Você não pode sair deste grupo ou não participa dele.');
    app_audit_record($userId,$tenantId,'community.group_left','community_group',$groupId);
}

function community_group_update_member(string $groupId,string $userId,string $status): void
{
    $producer=current_user();$tenantId=(string)($producer['tenant_id']??'');
    community_assert_producer_access($tenantId,$groupId,'edit');
    if(!in_array($status,['active','rejected'],true))throw new DomainException('Status de participação inválido.');
    $q=app_db()->prepare("UPDATE community_group_members SET status=?,joined_at=IF(?='active',UTC_TIMESTAMP(),joined_at) WHERE tenant_id=? AND group_id=? AND user_id=? AND status='pending'");$q->execute([$status,$status,$tenantId,$groupId,$userId]);
    if(!$q->rowCount())throw new DomainException('Solicitação pendente não encontrada.');
    app_audit_record((string)$producer['id'],$tenantId,'community.member_reviewed','community_group',$groupId,['status'=>$status]);
}

function community_resource_list_for_student(string $tenantId,string $groupId,string $userId): array
{
    if((string)(current_user()['id']??'')!==$userId)throw new DomainException('Acesso negado.');
    $check=app_db()->prepare("SELECT 1 FROM community_group_members m JOIN community_groups g ON g.tenant_id=m.tenant_id AND g.id=m.group_id WHERE m.tenant_id=? AND m.group_id=? AND m.user_id=? AND m.status='active' AND g.active=1");$check->execute([$tenantId,$groupId,$userId]);
    if(!$check->fetchColumn())throw new DomainException('Você não participa deste grupo.');
    $q=app_db()->prepare('SELECT id,resource_type,title,body,resource_url,starts_at,created_at FROM community_resources WHERE tenant_id=? AND group_id=? AND active=1 ORDER BY COALESCE(starts_at,created_at) DESC,created_at DESC');$q->execute([$tenantId,$groupId]);return $q->fetchAll();
}

function community_resource_list_for_producer(string $groupId): array
{
    $user=current_user();$tenantId=(string)($user['tenant_id']??'');
    community_assert_producer_access($tenantId,$groupId,'view');
    $q=app_db()->prepare('SELECT id,resource_type,title,body,resource_url,starts_at,active,created_at FROM community_resources WHERE tenant_id=? AND group_id=? ORDER BY created_at DESC');$q->execute([$tenantId,$groupId]);return $q->fetchAll();
}

function community_resource_create(array $input): void
{
    $user=current_user();$tenantId=(string)($user['tenant_id']??'');$groupId=(string)($input['group_id']??'');
    community_assert_producer_access($tenantId,$groupId,'edit');
    $type=(string)($input['resource_type']??'content');$title=trim((string)($input['title']??''));$body=trim((string)($input['body']??''));$url=trim((string)($input['resource_url']??''));$starts=trim((string)($input['starts_at']??''));
    if(!in_array($type,['content','announcement','meeting'],true)||$title===''||community_text_length($title)>160||community_text_length($body)>10000)throw new DomainException('Revise o tipo, título e texto do conteúdo.');
    if($url!==''&&(!filter_var($url,FILTER_VALIDATE_URL)||strtolower((string)parse_url($url,PHP_URL_SCHEME))!=='https'))throw new DomainException('Links publicados precisam usar HTTPS.');
    if($starts!==''){ $date=DateTimeImmutable::createFromFormat('Y-m-d\TH:i',$starts);if(!$date)throw new DomainException('Data do evento inválida.');$starts=$date->format('Y-m-d H:i:s'); } else $starts='';
    $group=app_db()->prepare('SELECT 1 FROM community_groups WHERE tenant_id=? AND id=?');$group->execute([$tenantId,$groupId]);if(!$group->fetchColumn())throw new DomainException('Grupo não encontrado.');
    app_db()->prepare('INSERT INTO community_resources(id,tenant_id,group_id,resource_type,title,body,resource_url,starts_at,created_by) VALUES(?,?,?,?,?,?,?,?,?)')->execute([new_id('res'),$tenantId,$groupId,$type,$title,$body,$url,$starts!==''?$starts:null,(string)$user['id']]);
    app_audit_record((string)$user['id'],$tenantId,'community.resource_created','community_group',$groupId,['type'=>$type]);
}

<?php
declare(strict_types=1);
require_once __DIR__ . '/affiliate-portal.php';

function community_validate_url(string $url): string
{
    $url=trim($url);$parts=parse_url($url);
    if(!$parts||strtolower((string)($parts['scheme']??''))!=='https'||empty($parts['host'])||isset($parts['user'])||isset($parts['pass'])||filter_var($url,FILTER_VALIDATE_URL)===false||strlen($url)>2000)throw new DomainException('Informe um link HTTPS válido (máximo de 2.000 caracteres).');
    return $url;
}

function community_text_length(string $value): int { $count=preg_match_all('/./us',$value,$matches);return $count===false?PHP_INT_MAX:$count; }

function affiliate_material_list(): array
{
    $q=app_db()->prepare('SELECT m.*,u.name AS creator_name FROM affiliate_materials m JOIN users u ON u.id=m.created_by WHERE m.tenant_id=? ORDER BY m.active DESC,m.created_at DESC');$q->execute([tenant_id()]);return $q->fetchAll();
}

function affiliate_material_save(array $data,string $actor): void
{
    $id=trim((string)($data['id']??''));$title=trim((string)($data['title']??''));$description=trim((string)($data['description']??''));$url=community_validate_url((string)($data['url']??''));$type=(string)($data['audience_type']??'all');$group=trim((string)($data['group']??''));$affiliate=trim((string)($data['affiliate_id']??''));
    if($title===''||community_text_length($title)>160||community_text_length($description)>4000)throw new DomainException('Informe título (até 160 caracteres) e descrição (até 4.000 caracteres).');
    if(!in_array($type,['all','group','affiliate'],true))throw new DomainException('Público do material inválido.');
    $affiliateId=$type==='affiliate'?$affiliate:null;
    if($type==='group'){$check=app_db()->prepare('SELECT 1 FROM affiliate_groups WHERE tenant_id=? AND name=?');$check->execute([tenant_id(),$group]);if(!$check->fetchColumn())throw new DomainException('Selecione um grupo existente neste espaço.');}
    if($type==='affiliate'){$check=app_db()->prepare("SELECT 1 FROM affiliates WHERE tenant_id=? AND id=? AND status='active'");$check->execute([tenant_id(),$affiliateId]);if(!$check->fetchColumn())throw new DomainException('Selecione um afiliado ativo deste espaço.');}
    $tenant=tenant_id();$pdo=app_db();
    if($id!==''){$q=$pdo->prepare('UPDATE affiliate_materials SET title=?,description=?,resource_url=?,audience_type=?,audience_group=?,audience_affiliate_id=? WHERE tenant_id=? AND id=?');$q->execute([$title,$description,$url,$type,$type==='group'?$group:'',$affiliateId,$tenant,$id]);if($q->rowCount()===0){$exists=$pdo->prepare('SELECT 1 FROM affiliate_materials WHERE tenant_id=? AND id=?');$exists->execute([$tenant,$id]);if(!$exists->fetchColumn())throw new DomainException('Material não encontrado neste espaço.');}}
    else $pdo->prepare('INSERT INTO affiliate_materials(id,tenant_id,title,description,resource_url,audience_type,audience_group,audience_affiliate_id,created_by) VALUES(?,?,?,?,?,?,?,?,?)')->execute([new_id('mat'),$tenant,$title,$description,$url,$type,$type==='group'?$group:'',$affiliateId,$actor]);
    app_audit_record($actor,$tenant,$id!==''?'material.updated':'material.created','material',$id);
}

function affiliate_material_toggle(string $id,string $actor): void
{
    $q=app_db()->prepare('UPDATE affiliate_materials SET active=1-active WHERE tenant_id=? AND id=?');$q->execute([tenant_id(),$id]);if($q->rowCount()!==1)throw new DomainException('Material não encontrado.');app_audit_record($actor,tenant_id(),'material.toggled','material',$id);
}

function affiliate_portal_materials(array $links): array
{
    if(!$links)return [];$q=app_db()->prepare("SELECT DISTINCT m.id,m.title,m.description,m.resource_url,m.created_at,t.name AS producer_name FROM affiliate_materials m JOIN tenants t ON t.id=m.tenant_id WHERE m.active=1 AND EXISTS(SELECT 1 FROM affiliate_account_links l JOIN affiliates a ON a.tenant_id=l.tenant_id AND a.id=l.affiliate_id AND a.status='active' WHERE l.user_id=? AND l.tenant_id=m.tenant_id AND l.status='active' AND (m.audience_type='all' OR (m.audience_type='group' AND m.audience_group=a.affiliate_group) OR (m.audience_type='affiliate' AND m.audience_affiliate_id=a.id))) ORDER BY m.created_at DESC");
    $q->execute([(string)current_user()['id']]);return $q->fetchAll();
}

function affiliate_event_list(): array
{
    $q=app_db()->prepare('SELECT e.*,u.name AS creator_name FROM affiliate_events e JOIN users u ON u.id=e.created_by WHERE e.tenant_id=? ORDER BY e.starts_at DESC');$q->execute([tenant_id()]);return $q->fetchAll();
}

function affiliate_event_save(array $data,string $actor): void
{
    $title=trim((string)($data['title']??''));$description=trim((string)($data['description']??''));$url=community_validate_url((string)($data['url']??''));$starts=trim((string)($data['starts_at']??''));$type=(string)($data['audience_type']??'all');$group=trim((string)($data['group']??''));$affiliate=trim((string)($data['affiliate_id']??''));
    $date=DateTimeImmutable::createFromFormat('Y-m-d\TH:i',$starts);
    if($title===''||community_text_length($title)>160||community_text_length($description)>4000||!$date)throw new DomainException('Preencha título, data e descrição válidos.');
    if(!in_array($type,['all','group','affiliate'],true))throw new DomainException('Público do evento inválido.');
    if($type==='group'){$check=app_db()->prepare('SELECT 1 FROM affiliate_groups WHERE tenant_id=? AND name=?');$check->execute([tenant_id(),$group]);if(!$check->fetchColumn())throw new DomainException('Selecione um grupo existente neste espaço.');}
    $affiliateId=$type==='affiliate'?$affiliate:null;if($type==='affiliate'){$q=app_db()->prepare("SELECT 1 FROM affiliates WHERE tenant_id=? AND id=? AND status='active'");$q->execute([tenant_id(),$affiliateId]);if(!$q->fetchColumn())throw new DomainException('Selecione um afiliado ativo deste espaço.');}
    app_db()->prepare('INSERT INTO affiliate_events(id,tenant_id,title,description,starts_at,meeting_url,audience_type,audience_group,audience_affiliate_id,created_by) VALUES(?,?,?,?,?,?,?,?,?,?)')->execute([new_id('evt'),tenant_id(),$title,$description,$date->format('Y-m-d H:i:s'),$url,$type,$type==='group'?$group:'',$affiliateId,$actor]);app_audit_record($actor,tenant_id(),'event.created','event','',['title'=>$title]);
}

function affiliate_portal_events(array $links): array
{
    if(!$links)return [];$q=app_db()->prepare("SELECT DISTINCT e.*,t.name AS producer_name,r.response FROM affiliate_events e JOIN tenants t ON t.id=e.tenant_id JOIN affiliate_account_links l ON l.tenant_id=e.tenant_id AND l.user_id=? AND l.status='active' JOIN affiliates a ON a.tenant_id=l.tenant_id AND a.id=l.affiliate_id AND a.status='active' LEFT JOIN affiliate_event_rsvps r ON r.tenant_id=e.tenant_id AND r.event_id=e.id AND r.affiliate_id=a.id WHERE (e.audience_type='all' OR (e.audience_type='group' AND e.audience_group=a.affiliate_group) OR (e.audience_type='affiliate' AND e.audience_affiliate_id=a.id)) ORDER BY e.starts_at");$q->execute([(string)current_user()['id']]);return $q->fetchAll();
}

function affiliate_event_respond(string $eventId,string $response): void
{
    if(!in_array($response,['going','declined'],true))throw new DomainException('Resposta de presença inválida.');
    $links=affiliate_portal_links((string)current_user()['id']);$selected=null;
    foreach($links as $l){$q=app_db()->prepare("SELECT id FROM affiliate_events WHERE tenant_id=? AND id=? AND ((audience_type='all') OR (audience_type='group' AND audience_group=?) OR (audience_type='affiliate' AND audience_affiliate_id=?))");$q->execute([$l['tenant_id'],$eventId,$l['affiliate_group'],$l['affiliate_id']]);if($q->fetchColumn()){$selected=$l;break;}}
    if(!$selected)throw new DomainException('Evento não encontrado para seus vínculos ativos.');
    app_db()->prepare('INSERT INTO affiliate_event_rsvps(tenant_id,event_id,affiliate_id,response,responded_at) VALUES(?,?,?,?,UTC_TIMESTAMP()) ON DUPLICATE KEY UPDATE response=VALUES(response),responded_at=UTC_TIMESTAMP()')->execute([$selected['tenant_id'],$eventId,$selected['affiliate_id'],$response]);
}

function affiliate_support_list_for_affiliate(string $userId): array
{
    $q=app_db()->prepare("SELECT th.*,a.name AS affiliate_name,t.name AS producer_name FROM affiliate_support_threads th JOIN affiliate_account_links l ON l.tenant_id=th.tenant_id AND l.affiliate_id=th.affiliate_id AND l.user_id=? AND l.status='active' JOIN affiliates a ON a.tenant_id=th.tenant_id AND a.id=th.affiliate_id AND a.status='active' JOIN tenants t ON t.id=th.tenant_id ORDER BY th.updated_at DESC");$q->execute([$userId]);return $q->fetchAll();
}

function affiliate_support_create(string $userId,string $subject,string $body,string $tenantId=''): string
{
    $subject=trim($subject);$body=trim($body);if($subject===''||community_text_length($subject)>160||$body===''||community_text_length($body)>5000)throw new DomainException('Informe assunto e mensagem (até 5.000 caracteres).');
    $links=affiliate_portal_links($userId);if(!$links)throw new DomainException('Não há vínculo ativo com um produtor.');$link=null;foreach($links as $candidate)if($tenantId===''||(string)$candidate['tenant_id']===$tenantId){$link=$candidate;break;}if(!$link)throw new DomainException('Escolha um programa ao qual sua conta esteja vinculada.');$thread=new_id('thd');$pdo=app_db();$pdo->beginTransaction();try{$pdo->prepare('INSERT INTO affiliate_support_threads(id,tenant_id,affiliate_id,subject) VALUES(?,?,?,?)')->execute([$thread,$link['tenant_id'],$link['affiliate_id'],$subject]);$pdo->prepare("INSERT INTO affiliate_support_messages(id,thread_id,sender_user_id,sender_profile,body) VALUES(?,?,?,'affiliate',?)")->execute([new_id('msg'),$thread,$userId,$body]);$pdo->commit();app_audit_record($userId,(string)$link['tenant_id'],'support.thread_created','thread',$thread);return $thread;}catch(Throwable $e){if($pdo->inTransaction())$pdo->rollBack();throw $e;}
}

function affiliate_support_messages(string $threadId,bool $asAffiliate): array
{
    $user=current_user();$sql='SELECT m.id,m.sender_profile,m.body,m.created_at,m.read_at,u.name AS sender_name FROM affiliate_support_messages m JOIN affiliate_support_threads th ON th.id=m.thread_id JOIN users u ON u.id=m.sender_user_id WHERE th.id=?';$params=[$threadId];
    if($asAffiliate){$sql.=" AND EXISTS(SELECT 1 FROM affiliate_account_links l JOIN affiliates a ON a.tenant_id=l.tenant_id AND a.id=l.affiliate_id AND a.status='active' WHERE l.tenant_id=th.tenant_id AND l.affiliate_id=th.affiliate_id AND l.user_id=? AND l.status='active')";$params[]=$user['id'];}
    else{$sql.=' AND th.tenant_id=?';$params[]=tenant_id();}
    $sql.=' ORDER BY m.created_at';$q=app_db()->prepare($sql);$q->execute($params);$rows=$q->fetchAll();
    if($rows){$readProfile=$asAffiliate?'producer':'affiliate';$up='UPDATE affiliate_support_messages m JOIN affiliate_support_threads th ON th.id=m.thread_id SET m.read_at=UTC_TIMESTAMP() WHERE th.id=? AND m.sender_profile=? AND m.read_at IS NULL';$args=[$threadId,$readProfile];if(!$asAffiliate){$up.=' AND th.tenant_id=?';$args[]=tenant_id();}$read=app_db()->prepare($up);$read->execute($args);}
    return $rows;
}

function affiliate_support_reply(string $threadId,string $body,string $profile): void
{
    $body=trim($body);if($body===''||community_text_length($body)>5000)throw new DomainException('A mensagem deve ter até 5.000 caracteres.');$user=current_user();$pdo=app_db();
    if($profile==='affiliate'){$q=$pdo->prepare("SELECT th.tenant_id FROM affiliate_support_threads th JOIN affiliate_account_links l ON l.tenant_id=th.tenant_id AND l.affiliate_id=th.affiliate_id WHERE th.id=? AND l.user_id=? AND l.status='active' AND th.status='open' AND EXISTS(SELECT 1 FROM affiliates a WHERE a.tenant_id=th.tenant_id AND a.id=th.affiliate_id AND a.status='active')");$q->execute([$threadId,$user['id']]);}
    else{$q=$pdo->prepare("SELECT tenant_id FROM affiliate_support_threads WHERE id=? AND tenant_id=? AND status='open'");$q->execute([$threadId,tenant_id()]);}
    $tenant=$q->fetchColumn();if(!$tenant)throw new DomainException('Conversa não encontrada ou encerrada.');
    $pdo->prepare('INSERT INTO affiliate_support_messages(id,thread_id,sender_user_id,sender_profile,body) VALUES(?,?,?,?,?)')->execute([new_id('msg'),$threadId,$user['id'],$profile,$body]);$pdo->prepare('UPDATE affiliate_support_threads SET updated_at=UTC_TIMESTAMP() WHERE id=?')->execute([$threadId]);app_audit_record((string)$user['id'],(string)$tenant,'support.message_sent','thread',$threadId);
}

function affiliate_support_close(string $threadId): void
{
    $q=app_db()->prepare("UPDATE affiliate_support_threads SET status='closed' WHERE id=? AND tenant_id=? AND status='open'");$q->execute([$threadId,tenant_id()]);if($q->rowCount()!==1)throw new DomainException('Conversa não encontrada ou já encerrada.');app_audit_record((string)current_user()['id'],tenant_id(),'support.thread_closed','thread',$threadId);
}

<?php
declare(strict_types=1);

function app_permission_catalog(): array
{
    return [
        'dashboard' => ['label' => 'Visão geral', 'actions' => ['view' => 'Visualizar']],
        'affiliates' => ['label' => 'Afiliados', 'actions' => ['view' => 'Visualizar', 'create' => 'Cadastrar', 'edit' => 'Editar e aprovar']],
        'affiliate_groups' => ['label' => 'Grupos de afiliados', 'actions' => ['view' => 'Visualizar', 'create' => 'Criar', 'edit' => 'Editar', 'delete' => 'Excluir']],
        'sales' => ['label' => 'Vendas', 'actions' => ['view' => 'Visualizar']],
        'campaigns' => ['label' => 'Metas e campanhas', 'actions' => ['view' => 'Visualizar', 'create' => 'Criar', 'edit' => 'Editar e pausar']],
        'ranking' => ['label' => 'Ranking', 'actions' => ['view' => 'Visualizar', 'edit' => 'Configurar visibilidade para afiliados']],
        'rewards' => ['label' => 'Recompensas', 'actions' => ['view' => 'Visualizar', 'create' => 'Criar', 'edit' => 'Ativar e entregar']],
        'announcements' => ['label' => 'Comunicados', 'actions' => ['view' => 'Visualizar', 'create' => 'Criar e enviar']],
        'integrations' => ['label' => 'Integrações', 'actions' => ['view' => 'Visualizar', 'create' => 'Conectar', 'edit' => 'Alterar e pausar']],
        'team' => ['label' => 'Equipe', 'actions' => ['view' => 'Visualizar', 'invite' => 'Convidar membros']],
        'communities' => ['label' => 'Grupos e comunidades', 'actions' => ['view' => 'Visualizar', 'create' => 'Criar grupos', 'edit' => 'Gerenciar grupos e participantes']],
        'materials' => ['label' => 'Materiais', 'actions' => ['view' => 'Visualizar', 'create' => 'Publicar', 'edit' => 'Editar e arquivar']],
        'events' => ['label' => 'Reuniões e eventos', 'actions' => ['view' => 'Visualizar', 'create' => 'Agendar', 'edit' => 'Editar e cancelar']],
        'support' => ['label' => 'Suporte', 'actions' => ['view' => 'Visualizar conversas', 'reply' => 'Responder', 'close' => 'Encerrar']],
    ];
}

function app_permission_module_for_request(): ?string
{
    $script = strtolower(basename((string)($_SERVER['SCRIPT_NAME'] ?? '')));
    return match ($script) {
        'dashboard.php' => 'dashboard',
        'affiliates.php' => 'affiliates',
        'affiliate-groups.php' => 'affiliate_groups',
        'sales.php' => 'sales',
        'campaigns.php' => 'campaigns',
        'ranking.php' => 'ranking',
        'rewards.php' => 'rewards',
        'announcements.php' => 'announcements',
        'integrations.php' => 'integrations',
        'team.php' => 'team',
        'materials.php' => 'materials',
        'events.php' => 'events',
        'support.php' => 'support',
        'communities.php' => 'communities',
        'community-content.php' => 'communities',
        'community-settings.php' => 'communities',
        default => null,
    };
}

function app_permission_action_for_request(?string $module = null): string
{
    $module ??= app_permission_module_for_request();
    if (strtoupper((string)($_SERVER['REQUEST_METHOD'] ?? 'GET')) !== 'POST') return 'view';
    $action = strtolower(trim((string)($_POST['action'] ?? '')));
    if ($module === 'team') return 'invite';
    if ($module === 'communities') return $action === 'create_group' ? 'create' : 'edit';
    if ($module === 'support') return in_array($action,['close','resolve'],true)?'close':'reply';
    if (in_array($module,['materials','events'],true)) return in_array($action,['toggle','delete','cancel'],true)?'edit':(trim((string)($_POST['id']??''))===''?'create':'edit');
    if ($module === 'affiliates') {
        if ($action === 'save_destination') return 'edit';
        if ($action === 'save') return trim((string)($_POST['id'] ?? '')) === '' ? 'create' : 'edit';
        return in_array($action, ['approve', 'reject', 'toggle'], true) ? 'edit' : 'edit';
    }
    if ($module === 'affiliate_groups') {
        if ($action === 'delete') return 'delete';
        return trim((string)($_POST['id'] ?? '')) === '' ? 'create' : 'edit';
    }
    if (in_array($module, ['campaigns', 'rewards'], true)) {
        if (in_array($action, ['delete', 'remove'], true)) return 'delete';
        if (in_array($action, ['toggle', 'deliver'], true)) return 'edit';
        return 'create';
    }
    if ($module === 'announcements') return in_array($action, ['delete', 'remove'], true) ? 'delete' : 'create';
    if ($module === 'integrations') {
        if (in_array($action, ['delete', 'disconnect'], true)) return 'delete';
        return in_array($action, ['', 'create'], true) ? 'create' : 'edit';
    }
    return 'edit';
}

function app_permission_default(string $role, string $module, string $action): bool
{
    if ($role === 'owner' || $role === 'admin') return true;
    $views = ['dashboard', 'affiliates', 'affiliate_groups', 'sales', 'campaigns', 'ranking', 'rewards', 'announcements','materials','events','support','communities'];
    if ($role === 'viewer') return $action === 'view' && in_array($module, $views, true);
    if ($role === 'analyst') return $action === 'view' && in_array($module, ['dashboard','sales','campaigns','ranking','rewards'], true);
    if ($role === 'support') return ($action === 'view' && in_array($module, ['dashboard','affiliates','announcements','support'], true)) || ($module==='support' && $action==='reply');
    if ($role === 'manager') {
        if ($action === 'view') return in_array($module, [...$views, 'team'], true);
        return match ($module) {
            'affiliates', 'affiliate_groups', 'campaigns', 'rewards','materials','events','communities' => in_array($action, ['create', 'edit'], true),
            'announcements' => $action === 'create',
            'support' => in_array($action,['reply','close'],true),
            default => false,
        };
    }
    return false;
}

function app_permission_explicit(string $tenantId, string $userId, string $module, string $action): ?bool
{
    static $cache = [];
    $cacheKey = $tenantId . ':' . $userId . ':' . $module . ':' . $action;
    if (array_key_exists($cacheKey, $cache)) return $cache[$cacheKey];
    $query = app_db()->prepare('SELECT allowed FROM membership_permissions WHERE tenant_id=? AND user_id=? AND module_key=? AND action_key=? LIMIT 1');
    $query->execute([$tenantId, $userId, $module, $action]);
    $value = $query->fetchColumn();
    return $cache[$cacheKey] = ($value === false ? null : (bool)$value);
}

function app_user_can(string $module, string $action = 'view', ?array $user = null): bool
{
    $user ??= current_user();
    if (!$user) return false;
    if (($user['role'] ?? '') === 'owner') return true;
    $catalog = app_permission_catalog();
    if (!isset($catalog[$module]['actions'][$action])) return false;
    $explicit = app_permission_explicit((string)($user['tenant_id'] ?? tenant_id()), (string)$user['id'], $module, $action);
    return $explicit ?? app_permission_default((string)($user['role'] ?? 'viewer'), $module, $action);
}

function app_permission_save_member(string $tenantId, string $userId, array $permissions, string $updatedBy): void
{
    $catalog = app_permission_catalog();
    $pdo = app_db();
    $pdo->beginTransaction();
    try {
        $member = $pdo->prepare("SELECT role FROM memberships WHERE tenant_id=? AND user_id=? AND role<>'owner' FOR UPDATE");
        $member->execute([$tenantId, $userId]);
        if (!$member->fetchColumn()) throw new DomainException('Selecione um membro válido; as permissões do proprietário não podem ser alteradas.');
        $pdo->prepare('DELETE FROM membership_permissions WHERE tenant_id=? AND user_id=?')->execute([$tenantId, $userId]);
        $insert = $pdo->prepare('INSERT INTO membership_permissions(tenant_id,user_id,module_key,action_key,allowed,updated_by) VALUES(?,?,?,?,?,?)');
        foreach ($catalog as $module => $definition) {
            foreach ($definition['actions'] as $action => $_label) {
                $allowed = isset($permissions[$module][$action]) && (string)$permissions[$module][$action] === '1';
                $insert->execute([$tenantId, $userId, $module, $action, $allowed ? 1 : 0, $updatedBy]);
            }
        }
        $pdo->commit();
        app_audit_record($updatedBy,$tenantId,'team.permissions_updated','user',$userId,['overrides'=>count($permissions)]);
    } catch (Throwable $error) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        throw $error;
    }
}

function app_member_permissions(string $tenantId, array $member): array
{
    $permissions = [];
    foreach (app_permission_catalog() as $module => $definition) {
        foreach ($definition['actions'] as $action => $_label) {
            $permissions[$module][$action] = app_user_can($module, $action, [
                'id' => (string)$member['user_id'], 'tenant_id' => $tenantId, 'role' => (string)$member['role'],
            ]);
        }
    }
    return $permissions;
}

function app_workspace_navigation(): string
{
    $profile = (string)(current_user()['active_profile'] ?? 'producer');
    $currentSection = app_workspace_section_for_request($profile);
    $links = '';
    foreach (app_workspace_sections($profile) as $section) {
        if ($profile !== 'affiliate') {
            $section['items'] = array_values(array_filter($section['items'], static fn(array $item): bool => empty($item['module']) || app_user_can((string)$item['module'], 'view')));
            if (!$section['items']) continue;
        }
        $active = $currentSection === $section['id'];
        $links .= '<a class="workspace-nav-link' . ($active ? ' selected active' : '') . '" href="section.php?section=' . rawurlencode($section['id']) . '"' . ($active ? ' aria-current="page"' : '') . '><span aria-hidden="true">' . htmlspecialchars($section['icon'], ENT_QUOTES, 'UTF-8') . '</span><b>' . htmlspecialchars($section['label'], ENT_QUOTES, 'UTF-8') . '</b></a>';
    }
    return $links;
}

/** Navigation hubs and real destinations available in each workspace. */
function app_workspace_sections(string $profile): array
{
    $student = [
        ['id'=>'overview','label'=>'Início','icon'=>'⌂','description'=>'Seu resumo e atividade recente.','items'=>[
            ['title'=>'Visão geral','description'=>'Acompanhe vendas, comissões e novidades da sua conta.','href'=>'student-dashboard.php?view=dashboard'],
        ]],
        ['id'=>'business','label'=>'Meu Negócio','icon'=>'↗','description'=>'Produtos e resultados comerciais vinculados à sua conta.','items'=>[
            ['title'=>'Meus produtos','description'=>'Veja produtos com vendas identificadas e links autorizados.','href'=>'student-dashboard.php?view=products'],
            ['title'=>'Minhas vendas','description'=>'Consulte transações atribuídas aos seus vínculos ativos.','href'=>'student-dashboard.php?view=sales'],
            ['title'=>'Minhas comissões','description'=>'Acompanhe comissões informadas pelas integrações.','href'=>'student-dashboard.php?view=commissions'],
        ]],
        ['id'=>'performance','label'=>'Meu Desempenho','icon'=>'⌁','description'=>'Metas, posição e conquistas da sua jornada.','items'=>[
            ['title'=>'Minhas metas','description'=>'Crie objetivos pessoais e acompanhe seu progresso.','href'=>'student-dashboard.php?view=goals'],
            ['title'=>'Ranking','description'=>'Consulte os rankings que os produtores compartilharam.','href'=>'student-dashboard.php?view=ranking'],
            ['title'=>'Conquistas','description'=>'Veja as metas alcançadas e os reconhecimentos recebidos.','href'=>'student-dashboard.php?view=achievements'],
        ]],
        ['id'=>'community','label'=>'Comunidade e Aprendizado','icon'=>'◇','description'=>'Grupos, conteúdos, materiais e encontros.','items'=>[
            ['title'=>'Meus grupos','description'=>'Acesse os grupos em que sua participação está ativa.','href'=>'student-dashboard.php?view=groups'],
            ['title'=>'Conteúdos dos grupos','description'=>'Leia publicações e orientações dos produtores.','href'=>'student-content.php'],
            ['title'=>'Materiais','description'=>'Acesse materiais de divulgação disponibilizados para você.','href'=>'student-dashboard.php?view=materials'],
            ['title'=>'Reuniões','description'=>'Confira treinamentos e eventos dos seus grupos.','href'=>'student-dashboard.php?view=events'],
        ]],
        ['id'=>'communication','label'=>'Central de Comunicação','icon'=>'◎','description'=>'Avisos importantes e atendimento aos produtores.','items'=>[
            ['title'=>'Comunicados','description'=>'Leia mensagens e atualizações dos seus produtores.','href'=>'student-dashboard.php?view=announcements'],
            ['title'=>'Suporte','description'=>'Abra ou acompanhe conversas de atendimento.','href'=>'student-dashboard.php?view=messages'],
        ]],
    ];
    if ($profile === 'affiliate') return $student;

    return [
        ['id'=>'overview','label'=>'Visão Geral','icon'=>'⌂','description'=>'Resumo da operação e indicadores principais.','items'=>[
            ['title'=>'Dashboard','description'=>'Acompanhe vendas atribuídas, comissões e atividade da equipe.','href'=>'dashboard.php','module'=>'dashboard'],
        ]],
        ['id'=>'products-sales','label'=>'Produtos e Vendas','icon'=>'↗','description'=>'Vendas integradas e conexões com plataformas externas.','items'=>[
            ['title'=>'Vendas','description'=>'Consulte transações recebidas e seus respectivos status.','href'=>'sales.php','module'=>'sales'],
            ['title'=>'Integrações','description'=>'Conecte e gerencie suas plataformas de vendas.','href'=>'integrations.php','module'=>'integrations'],
        ]],
        ['id'=>'affiliate-management','label'=>'Gestão de Afiliados','icon'=>'♙','description'=>'Relacionamentos, grupos de afiliados e acessos da equipe.','items'=>[
            ['title'=>'Afiliados','description'=>'Cadastre, aprove e acompanhe seus afiliados.','href'=>'affiliates.php','module'=>'affiliates'],
            ['title'=>'Grupos de afiliados','description'=>'Organize sua rede em grupos e categorias.','href'=>'affiliate-groups.php','module'=>'affiliate_groups'],
            ['title'=>'Equipe e permissões','description'=>'Convide colaboradores e configure os acessos do espaço.','href'=>'team.php','module'=>'team'],
        ]],
        ['id'=>'communities','label'=>'Comunidades e Grupos','icon'=>'◇','description'=>'Grupos, conteúdos e configurações de participação.','items'=>[
            ['title'=>'Meus grupos','description'=>'Crie comunidades e gerencie seus participantes.','href'=>'communities.php','module'=>'communities'],
            ['title'=>'Conteúdos e comunicados','description'=>'Publique conteúdos para participantes dos seus grupos.','href'=>'community-content.php','module'=>'communities'],
            ['title'=>'Configurar grupos','description'=>'Defina regras de acesso e preferências das comunidades.','href'=>'community-settings.php','module'=>'communities'],
        ]],
        ['id'=>'communication-events','label'=>'Comunicação e Eventos','icon'=>'◎','description'=>'Comunicados, treinamentos, materiais e suporte.','items'=>[
            ['title'=>'Comunicados','description'=>'Prepare e envie mensagens para afiliados e grupos.','href'=>'announcements.php','module'=>'announcements'],
            ['title'=>'Reuniões e eventos','description'=>'Agende encontros e acompanhe confirmações.','href'=>'events.php','module'=>'events'],
            ['title'=>'Materiais','description'=>'Organize recursos e materiais de divulgação.','href'=>'materials.php','module'=>'materials'],
            ['title'=>'Suporte','description'=>'Acompanhe solicitações enviadas pelos afiliados.','href'=>'support.php','module'=>'support'],
        ]],
        ['id'=>'reports-management','label'=>'Relatórios e Gestão','icon'=>'▤','description'=>'Metas, desempenho, recompensas e operação da equipe.','items'=>[
            ['title'=>'Metas e campanhas','description'=>'Crie campanhas e acompanhe os objetivos da equipe.','href'=>'campaigns.php','module'=>'campaigns'],
            ['title'=>'Ranking','description'=>'Configure e acompanhe o desempenho compartilhado.','href'=>'ranking.php','module'=>'ranking'],
            ['title'=>'Recompensas','description'=>'Defina incentivos e gerencie entregas.','href'=>'rewards.php','module'=>'rewards'],
        ]],
    ];
}

function app_workspace_section_for_request(string $profile): string
{
    $script = strtolower(basename((string)($_SERVER['SCRIPT_NAME'] ?? '')));
    if ($script === 'section.php') return (string)($_GET['section'] ?? 'overview');
    if ($profile === 'affiliate') {
        if ($script === 'student-content.php') return 'community';
        $view = (string)($_GET['view'] ?? 'dashboard');
        return match ($view) {
            'products','sales','commissions' => 'business',
            'goals','ranking','achievements' => 'performance',
            'groups','materials','events' => 'community',
            'announcements','messages' => 'communication',
            default => 'overview',
        };
    }
    return match ($script) {
        'dashboard.php' => 'overview',
        'sales.php','integrations.php' => 'products-sales',
        'affiliates.php','affiliate-groups.php','team.php' => 'affiliate-management',
        'communities.php','community-content.php','community-settings.php' => 'communities',
        'announcements.php','events.php','materials.php','support.php' => 'communication-events',
        'campaigns.php','ranking.php','rewards.php' => 'reports-management',
        default => 'overview',
    };
}

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
        default => null,
    };
}

function app_permission_action_for_request(?string $module = null): string
{
    $module ??= app_permission_module_for_request();
    if (strtoupper((string)($_SERVER['REQUEST_METHOD'] ?? 'GET')) !== 'POST') return 'view';
    $action = strtolower(trim((string)($_POST['action'] ?? '')));
    if ($module === 'team') return 'invite';
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
    $views = ['dashboard', 'affiliates', 'affiliate_groups', 'sales', 'campaigns', 'ranking', 'rewards', 'announcements','materials','events','support'];
    if ($role === 'viewer') return $action === 'view' && in_array($module, $views, true);
    if ($role === 'analyst') return $action === 'view' && in_array($module, ['dashboard','sales','campaigns','ranking','rewards'], true);
    if ($role === 'support') return ($action === 'view' && in_array($module, ['dashboard','affiliates','announcements','support'], true)) || ($module==='support' && $action==='reply');
    if ($role === 'manager') {
        if ($action === 'view') return in_array($module, [...$views, 'team'], true);
        return match ($module) {
            'affiliates', 'affiliate_groups', 'campaigns', 'rewards','materials','events' => in_array($action, ['create', 'edit'], true),
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
    $profileSwitch = function_exists('app_profile_switcher') ? app_profile_switcher() : '';
    if ((current_user()['active_profile'] ?? 'producer') === 'affiliate') {
        $views = ['dashboard'=>'Meu painel','products'=>'Produtos afiliados','sales'=>'Minhas vendas','commissions'=>'Minhas comissões','goals'=>'Minhas metas','ranking'=>'Ranking','achievements'=>'Conquistas','materials'=>'Materiais','events'=>'Reuniões','announcements'=>'Comunicados','messages'=>'Suporte'];
        $currentView = (string)($_GET['view'] ?? 'dashboard'); $links='';
        foreach($views as $view=>$label) {
            $class=$currentView===$view?' class="active"':'';
            $links.='<a'.$class.' href="affiliate-dashboard.php?view='.$view.'">'.htmlspecialchars($label,ENT_QUOTES,'UTF-8').'</a>';
        }
        return $profileSwitch.$links;
    }
    $items = [
        'dashboard' => ['dashboard.php', 'Visão geral'],
        'affiliates' => ['affiliates.php', 'Afiliados'],
        'sales' => ['sales.php', 'Vendas'],
        'campaigns' => ['campaigns.php', 'Metas'],
        'ranking' => ['ranking.php', 'Ranking'],
        'rewards' => ['rewards.php', 'Recompensas'],
        'announcements' => ['announcements.php', 'Comunicados'],
        'team' => ['team.php', 'Equipe'],
        'integrations' => ['integrations.php', 'Integrações'],
        'materials' => ['materials.php', 'Materiais'],
        'events' => ['events.php', 'Reuniões'],
        'support' => ['support.php', 'Suporte'],
    ];
    $current = app_permission_module_for_request();
    if ($current === 'affiliate_groups') $current = 'affiliates';
    $activeClass = basename((string)($_SERVER['SCRIPT_NAME'] ?? '')) === 'affiliates.php' ? 'active' : 'selected';
    $links = '';
    foreach ($items as $module => [$href, $label]) {
        if (!app_user_can($module, 'view')) continue;
        $class = $current === $module ? ' class="' . $activeClass . '"' : '';
        $links .= '<a' . $class . ' href="' . $href . '">' . htmlspecialchars($label, ENT_QUOTES, 'UTF-8') . '</a>';
    }
    return $profileSwitch . $links;
}

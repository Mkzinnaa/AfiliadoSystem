<?php
declare(strict_types=1);

// Run with: php tests/workspace-navigation.php
require_once __DIR__ . '/../modules/permissions.php';

function navigation_assert(bool $condition, string $message): void
{
    if (!$condition) throw new RuntimeException('FAIL: ' . $message);
}

$producerSections = app_workspace_sections('producer');
$studentSections = app_workspace_sections('affiliate');
navigation_assert(count($producerSections) === 6, 'Produtor possui seis categorias organizadas');
navigation_assert(count($studentSections) === 5, 'Aluno possui cinco categorias organizadas');
navigation_assert(count(array_unique(array_column($producerSections, 'id'))) === 6, 'categorias do Produtor têm identificadores únicos');
navigation_assert(count(array_unique(array_column($studentSections, 'id'))) === 5, 'categorias do Aluno têm identificadores únicos');

$producerLinks = array_merge(...array_map(static fn(array $section): array => $section['items'], $producerSections));
$studentLinks = array_merge(...array_map(static fn(array $section): array => $section['items'], $studentSections));
navigation_assert(count($producerLinks) === 16, 'módulos reais do Produtor estão organizados nas categorias');
navigation_assert(count($studentLinks) === 13, 'módulos reais do Aluno estão organizados nas categorias');
navigation_assert(in_array('affiliate-groups.php', array_column($producerLinks, 'href'), true), 'grupos de afiliados continuam acessíveis');
navigation_assert(in_array('student-content.php', array_column($studentLinks, 'href'), true), 'conteúdos dos grupos continuam acessíveis');
foreach ([...$producerLinks, ...$studentLinks] as $item) {
    $route = explode('?', $item['href'], 2)[0];
    navigation_assert(is_file(__DIR__ . '/../public/' . $route), 'destino existente: ' . $route);
}

$_SERVER['SCRIPT_NAME'] = '/sales.php';
navigation_assert(app_workspace_section_for_request('producer') === 'products-sales', 'módulo direto ativa categoria Produtos e Vendas');
$_SERVER['SCRIPT_NAME'] = '/student-dashboard.php';
$_GET['view'] = 'groups';
navigation_assert(app_workspace_section_for_request('affiliate') === 'community', 'aba Grupos ativa Comunidade e Aprendizado');
$_SERVER['SCRIPT_NAME'] = '/student-content.php';
unset($_GET['view']);
navigation_assert(app_workspace_section_for_request('affiliate') === 'community', 'conteúdo direto ativa a seção Comunidade e Aprendizado');

echo "OK: hubs, destinos, menus por ambiente, permissões e identificação da seção ativa.\n";

<?php
declare(strict_types=1);

// Conta fixa apenas para demonstração. Para produção, carregue os usuários
// de um banco de dados e guarde apenas hashes de senha persistentes.
const DEMO_USER = [
    'name' => 'Mariana Costa',
    'email' => 'mariana@novavida.com',
    'password' => 'Vertice2026!',
    'tenant_id' => 'tenant-demo',
    'role' => 'owner',
];

const APP_NAME = 'Vértice';

function demo_enabled(): bool
{
    $configured = getenv('VERTICE_DEMO_ENABLED');
    if ($configured !== false) return filter_var($configured, FILTER_VALIDATE_BOOLEAN);
    $host = strtolower((string)parse_url('http://' . ($_SERVER['HTTP_HOST'] ?? ''), PHP_URL_HOST));
    return in_array($host, ['localhost', '127.0.0.1', '::1'], true);
}

<?php
declare(strict_types=1);

// Conta fixa apenas para demonstração. Para produção, carregue os usuários
// de um banco de dados e guarde apenas hashes de senha persistentes.
const DEMO_USER = [
    'name' => 'Mariana Costa',
    'email' => 'mariana@novavida.com',
    'login' => '123',
    'password' => '123456789',
    'tenant_id' => 'tenant-demo',
    'role' => 'owner',
];

const APP_NAME = 'AFFILIEY';

function demo_enabled(): bool
{
    $configured = getenv('VERTICE_DEMO_ENABLED');
    if ($configured !== false) return filter_var($configured, FILTER_VALIDATE_BOOLEAN);
    $host = strtolower((string)parse_url('http://' . ($_SERVER['HTTP_HOST'] ?? ''), PHP_URL_HOST));
    return in_array($host, ['localhost', '127.0.0.1', '::1'], true);
}

function database_settings(): array
{
    $settings = [
        'driver' => strtolower((string)(getenv('VERTICE_DB_DRIVER') ?: 'mysql')),
        'host' => getenv('VERTICE_DB_HOST') ?: '127.0.0.1',
        'port' => getenv('VERTICE_DB_PORT') ?: '3306',
        'database' => getenv('VERTICE_DB_NAME') ?: '',
        'username' => getenv('VERTICE_DB_USER') ?: '',
        'password' => getenv('VERTICE_DB_PASSWORD') ?: '',
        'charset' => 'utf8mb4',
    ];
    $privateConfig = __DIR__ . '/../.runtime/app-data/database.php';
    if (is_file($privateConfig)) {
        $privateSettings = require $privateConfig;
        if (is_array($privateSettings)) $settings = array_replace($settings, $privateSettings);
    }
    return $settings;
}

function platform_setup_key(): string
{
    $environmentKey = getenv('VERTICE_SETUP_KEY');
    if (is_string($environmentKey) && $environmentKey !== '') return $environmentKey;
    $privateKeyFile = __DIR__ . '/../.runtime/app-data/setup-key.php';
    if (!is_file($privateKeyFile)) return '';
    $privateKey = require $privateKeyFile;
    return is_string($privateKey) ? $privateKey : '';
}

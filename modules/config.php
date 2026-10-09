<?php
declare(strict_types=1);
require_once __DIR__ . '/env.php';

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
    $host = strtolower((string)parse_url('http://' . ($_SERVER['HTTP_HOST'] ?? ''), PHP_URL_HOST));
    if (!in_array($host, ['localhost', '127.0.0.1', '::1'], true)) return false;
    $configured = getenv('VERTICE_DEMO_ENABLED');
    if ($configured !== false) return filter_var($configured, FILTER_VALIDATE_BOOLEAN);
    return true;
}

function database_settings(): array
{
    $settings = [
        'driver' => 'mysql',
        'host' => '127.0.0.1',
        'port' => '3306',
        'database' => '',
        'username' => '',
        'password' => '',
        'charset' => 'utf8mb4',
    ];
    $privateConfig = __DIR__ . '/../.runtime/app-data/database.php';
    if (is_file($privateConfig)) {
        $privateSettings = require $privateConfig;
        if (is_array($privateSettings)) $settings = array_replace($settings, $privateSettings);
    }
    $environment = [
        'driver' => 'VERTICE_DB_DRIVER', 'host' => 'VERTICE_DB_HOST', 'port' => 'VERTICE_DB_PORT',
        'database' => 'VERTICE_DB_NAME', 'username' => 'VERTICE_DB_USER', 'password' => 'VERTICE_DB_PASSWORD',
    ];
    foreach ($environment as $setting => $variable) {
        $value = getenv($variable);
        if ($value !== false) $settings[$setting] = $value;
    }
    $settings['driver'] = strtolower((string)$settings['driver']);
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

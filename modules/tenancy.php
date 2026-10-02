<?php
declare(strict_types=1);

function tenant_id(): string
{
    if (session_status() !== PHP_SESSION_ACTIVE) session_start();
    $id = (string)($_SESSION['affiliate_user']['tenant_id'] ?? 'tenant-demo');
    return preg_match('/^[a-zA-Z0-9_-]{1,64}$/', $id) ? $id : 'tenant-demo';
}

function tenant_storage_dir(): string
{
    $root = realpath(__DIR__ . '/../storage');
    if ($root === false) throw new RuntimeException('Pasta de armazenamento não encontrada.');
    $dir = $root . DIRECTORY_SEPARATOR . 'tenants' . DIRECTORY_SEPARATOR . tenant_id();
    if (!is_dir($dir) && !mkdir($dir, 0775, true) && !is_dir($dir)) {
        throw new RuntimeException('Não foi possível criar o espaço de dados da organização.');
    }
    return $dir;
}

function tenant_storage_file(string $file): string
{
    if (!preg_match('/^[a-zA-Z0-9_-]+\.json$/', $file)) throw new InvalidArgumentException('Nome de arquivo inválido.');
    return tenant_storage_dir() . DIRECTORY_SEPARATOR . $file;
}

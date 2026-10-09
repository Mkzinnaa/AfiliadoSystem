<?php
declare(strict_types=1);

ini_set('display_errors', '0');
ini_set('display_startup_errors', '0');
ini_set('log_errors', '1');

/** Load private root-level .env values without replacing server-provided variables. */
function app_load_env(): void
{
    static $loaded = false;
    if ($loaded) return;
    $loaded = true;

    $path = dirname(__DIR__) . DIRECTORY_SEPARATOR . '.env';
    if (!is_file($path)) return;
    if (!is_readable($path)) throw new RuntimeException('O arquivo privado .env não pode ser lido.');

    $lines = file($path, FILE_IGNORE_NEW_LINES);
    if (!is_array($lines)) throw new RuntimeException('Não foi possível ler o arquivo privado .env.');
    foreach ($lines as $lineNumber => $line) {
        $line = trim($line);
        if ($line === '' || str_starts_with($line, '#')) continue;
        if (str_starts_with($line, 'export ')) $line = substr($line, 7);
        if (!preg_match('/\A([A-Z][A-Z0-9_]*)\s*=\s*(.*)\z/', $line, $match)) {
            throw new RuntimeException('Formato inválido no arquivo privado .env, linha ' . ($lineNumber + 1) . '.');
        }

        $name = $match[1];
        if (getenv($name) !== false) continue;
        $value = trim($match[2]);
        if (strlen($value) >= 2 && $value[0] === "'" && str_ends_with($value, "'")) {
            $value = substr($value, 1, -1);
        } elseif (strlen($value) >= 2 && $value[0] === '"' && str_ends_with($value, '"')) {
            $decoded = json_decode($value, true);
            if (!is_string($decoded)) throw new RuntimeException('Valor entre aspas inválido no .env, linha ' . ($lineNumber + 1) . '.');
            $value = $decoded;
        }

        putenv($name . '=' . $value);
        $_ENV[$name] = $value;
    }
}

app_load_env();

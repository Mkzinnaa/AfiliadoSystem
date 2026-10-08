<?php
declare(strict_types=1);

require_once __DIR__ . '/../../../modules/api.php';
ini_set('display_errors', '0');
ini_set('log_errors', '1');
api_init();

set_exception_handler(static function (Throwable $error): void {
    error_log('[AFFILIEY API] ' . $error->getMessage());
    api_fail('Não foi possível processar a solicitação.', 500, 'internal_error');
});

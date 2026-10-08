<?php
declare(strict_types=1);
require_once __DIR__ . '/../bootstrap.php';
api_method('GET');
api_ok(api_require_auth());

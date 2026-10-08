<?php
declare(strict_types=1);
require_once __DIR__ . '/bootstrap.php';
api_method('GET');
api_ok([
    'name' => 'AFFILIEY API',
    'version' => 'v1',
    'authentication' => 'Bearer token',
    'endpoints' => [
        'POST /api/v1/auth/login.php',
        'GET /api/v1/auth/me.php',
        'POST /api/v1/auth/logout.php',
        'GET /api/v1/dashboard.php',
        'GET /api/v1/affiliates.php',
        'GET /api/v1/sales.php',
        'GET /api/v1/campaigns.php',
        'GET /api/v1/ranking.php',
        'GET /api/v1/rewards.php',
        'GET /api/v1/announcements.php',
    ],
]);

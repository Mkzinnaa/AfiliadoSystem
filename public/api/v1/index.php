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
        'POST /api/v1/auth/login.php com profile=affiliate para token do ambiente afiliado',
        'GET /api/v1/affiliate/dashboard.php',
        'GET /api/v1/affiliate/products.php',
        'GET /api/v1/affiliate/sales.php',
        'GET /api/v1/affiliate/materials.php',
        'GET /api/v1/affiliate/events.php e POST para responder {event_id,response}',
        'GET /api/v1/affiliate/announcements.php',
        'GET /api/v1/affiliate/messages.php e POST para criar ou responder atendimentos',
        'GET /api/v1/affiliate/goals.php e POST para criar metas pessoais',
        'GET /api/v1/affiliate/ranking.php (somente produtores que habilitaram compartilhamento)',
        'GET /api/v1/affiliate/achievements.php',
    ],
]);

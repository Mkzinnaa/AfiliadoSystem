<?php
declare(strict_types=1);
require_once __DIR__ . '/../bootstrap.php';
require_once __DIR__ . '/../../../../modules/affiliate-portal.php';
api_method('GET');
$user=api_require_affiliate_auth();
api_ok(affiliate_portal_achievements(affiliate_portal_links((string)$user['user_id'])));

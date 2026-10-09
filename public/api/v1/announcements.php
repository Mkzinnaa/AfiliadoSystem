<?php
declare(strict_types=1);
require_once __DIR__ . '/bootstrap.php';
require_once __DIR__ . '/../../../modules/announcements.php';
api_method('GET');
api_require_auth();
api_require_permission('announcements');
api_ok(announcement_list());

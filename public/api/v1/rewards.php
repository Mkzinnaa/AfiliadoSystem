<?php
declare(strict_types=1);
require_once __DIR__ . '/bootstrap.php';
require_once __DIR__ . '/../../../modules/rewards.php';
api_method('GET');
api_require_auth();
api_ok(reward_award_list());

<?php
declare(strict_types=1);
require_once __DIR__ . '/_guard.php'; // admin + POST + CSRF on the web; CLI untouched (Fable 2026-10-12)
header('Content-Type: application/json');

$__dir = __DIR__;
for ($__i = 0; $__i < 5; $__i++) {
    $__dir = dirname($__dir);
    if (is_file($__dir . '/app/Core/paths.php')) {
        require_once $__dir . '/app/Core/paths.php';
        break;
    }
}

require_once PUBLIC_ROOT . '/loginAuth/auth.php';

require_once APP_ROOT . '/Modules/Privacy/Cron/data_retention.php';

<?php
require_once __DIR__ . '/_guard.php';
/**
 * Shim — routes to /app/Modules/Sales/Cron/closer_recalibrate.php (Sam the Closer's weekly refit)
 */
$__dir = __DIR__;
for ($__i = 0; $__i < 5; $__i++) {
    $__dir = dirname($__dir);
    if (is_file($__dir . '/app/Core/paths.php')) {
        require_once $__dir . '/app/Core/paths.php';
        break;
    }
}
unset($__dir, $__i);
require_once APP_ROOT . '/Modules/Sales/Cron/closer_recalibrate.php';

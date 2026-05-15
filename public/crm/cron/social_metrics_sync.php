<?php
/**
 * Shim — routes to /app/Modules/Social/Cron/metrics_sync.php
 * Callable via web POST (admin only) or directly by cron.
 */
$__dir = __DIR__;
for ($__i = 0; $__i < 6; $__i++) {
    $__dir = dirname($__dir);
    if (is_file($__dir . '/app/Core/paths.php')) {
        require_once $__dir . '/app/Core/paths.php';
        break;
    }
}
unset($__dir, $__i);
require_once APP_ROOT . '/Modules/Social/Cron/metrics_sync.php';

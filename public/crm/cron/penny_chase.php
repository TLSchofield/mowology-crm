<?php
require_once __DIR__ . '/_guard.php';
/**
 * Shim — routes to /app/Modules/Expenses/Cron/penny_chase.php (Penny chases missing receipts)
 * ?dry_run=1 scans and lists who would be nudged without writing; ?summary=1 sends the weekly summary now. DO NOT add new code here.
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
require_once APP_ROOT . '/Modules/Expenses/Cron/penny_chase.php';

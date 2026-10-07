<?php
require_once __DIR__ . '/_guard.php';
/**
 * Shim — routes to /app/Modules/Expenses/Cron/penny_prepare.php (Penny prepares receipts in the background)
 * ?dry_run=1 lists what would be prepared without calling the AI. DO NOT add new code here.
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
require_once APP_ROOT . '/Modules/Expenses/Cron/penny_prepare.php';

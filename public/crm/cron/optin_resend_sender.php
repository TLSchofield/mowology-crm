<?php
/**
 * Shim — routes to /app/Modules/Marketing/Cron/optin_resend_sender.php
 * (the /app tree is web-denied by .htaccess; this is the public entry).
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
require_once APP_ROOT . '/Modules/Marketing/Cron/optin_resend_sender.php';

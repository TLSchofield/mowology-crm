<?php
/**
 * Admin web shim to run the e-Transfer inbox poller on demand (for testing /
 * a manual catch-up). The real cron lives under /app/ which is web-denied, so
 * this includes it. Output is the poller's plain-text log.
 */
declare(strict_types=1);

$__dir = __DIR__;
for ($__i = 0; $__i < 5; $__i++) {
    $__dir = dirname($__dir);
    if (is_file($__dir . '/app/Core/paths.php')) { require_once $__dir . '/app/Core/paths.php'; break; }
}
unset($__dir, $__i);

require_once PUBLIC_ROOT . '/loginAuth/auth.php';
requireLogin();
if (!isAdmin()) { http_response_code(403); exit('Admin only'); }

require APP_ROOT . '/Modules/Accounting/Cron/etransfer_inbox_poll.php';

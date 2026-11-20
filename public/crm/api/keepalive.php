<?php
/**
 * Session keepalive endpoint.
 * Called by time-clock-widget.js every 10 minutes to prevent PHP session GC
 * from expiring an idle field worker's session between GPS pings.
 * Returns {"ok":true} when authenticated, HTTP 401 + {"ok":false} when not.
 */
declare(strict_types=1);
header('Content-Type: application/json');

if (!defined('APP_ROOT')) {
    $__dir = __DIR__;
    for ($__i = 0; $__i < 5; $__i++) {
        $__dir = dirname($__dir);
        if (is_file($__dir . '/app/Core/paths.php')) {
            require_once $__dir . '/app/Core/paths.php';
            break;
        }
    }
    unset($__dir, $__i);
}

require_once PUBLIC_ROOT . '/loginAuth/auth.php';

if (!isLoggedIn()) {
    http_response_code(401);
    echo json_encode(['ok' => false]);
    exit();
}

// Touch the session to reset the GC timer, then release the lock immediately
// so this lightweight request doesn't block concurrent page loads.
$_SESSION['last_activity'] = time();
session_write_close();

echo json_encode(['ok' => true, 'ts' => time()]);

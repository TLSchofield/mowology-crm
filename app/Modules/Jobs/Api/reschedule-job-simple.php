<?php
/**
 * @deprecated Use reschedule-stop.php instead.
 * This endpoint operated on the legacy `jobs` table which has been dropped.
 */

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

header('Content-Type: application/json');
http_response_code(410);
echo json_encode(['success' => false, 'error' => 'This endpoint is deprecated. Use reschedule-stop.php instead.']);

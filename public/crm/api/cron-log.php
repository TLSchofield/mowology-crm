<?php
/**
 * Shim — forwards to app/Modules/Database/Api/cron-log.php
 */
$__dir = __DIR__;
for ($__i = 0; $__i < 5; $__i++) {
    $__dir = dirname($__dir);
    if (is_file($__dir . '/app/Modules/Database/Api/cron-log.php')) {
        require $__dir . '/app/Modules/Database/Api/cron-log.php';
        exit;
    }
}
http_response_code(500);
echo json_encode(['error' => 'Module file not found']);

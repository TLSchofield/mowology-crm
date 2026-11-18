<?php
require_once __DIR__ . '/_guard.php';
/**
 * SHIM — contact_roster_sync.php
 * Real logic lives at /app/Modules/Clients/Cron/contact_roster_sync.php
 * DO NOT add new code here. Edit the target file instead.
 */
$__dir = __DIR__;
for ($__i = 0; $__i < 5; $__i++) {
    $__dir = dirname($__dir);
    if (is_file($__dir . '/app/Modules/Clients/Cron/contact_roster_sync.php')) {
        require $__dir . '/app/Modules/Clients/Cron/contact_roster_sync.php';
        exit;
    }
}
http_response_code(500);
echo json_encode(['error' => 'Module file not found']);

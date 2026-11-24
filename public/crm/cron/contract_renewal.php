<?php
require_once __DIR__ . '/_guard.php';
/**
 * LEGACY SHIM — contract_renewal.php
 * Real logic lives at /app/Modules/Contracts/Cron/contract_renewal.php
 * DO NOT add new code here. Edit the target file instead.
 */
$__dir = __DIR__;
for ($__i = 0; $__i < 5; $__i++) {
    $__dir = dirname($__dir);
    if (is_file($__dir . '/app/Modules/Contracts/Cron/contract_renewal.php')) {
        require $__dir . '/app/Modules/Contracts/Cron/contract_renewal.php';
        exit;
    }
}
http_response_code(500);
echo json_encode(['error' => 'Module file not found']);

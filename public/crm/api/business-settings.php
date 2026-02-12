<?php
/**
 * Legacy Shim: Business Settings API
 * Forwards to: /app/Modules/Settings/Api/business-settings.php
 */

$__dir = __DIR__;
for ($__i = 0; $__i < 5; $__i++) {
    $__candidate = $__dir . '/app/Modules/Settings/Api/business-settings.php';
    if (is_file($__candidate)) {
        require $__candidate;
        exit;
    }
    $__dir = dirname($__dir);
}
http_response_code(500);
echo json_encode(['error' => 'Module file not found']);

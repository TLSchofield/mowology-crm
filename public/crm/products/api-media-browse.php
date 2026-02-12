<?php
/**
 * Legacy Shim: Media Browse API
 * Forwards to: /app/Modules/Products/Api/api-media-browse.php
 */

$__dir = __DIR__;
for ($__i = 0; $__i < 5; $__i++) {
    $__candidate = $__dir . '/app/Modules/Products/Api/api-media-browse.php';
    if (is_file($__candidate)) {
        require $__candidate;
        exit;
    }
    $__dir = dirname($__dir);
}
http_response_code(500);
echo json_encode(['error' => 'Module file not found']);

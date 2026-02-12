<?php
/**
 * Legacy Shim: Handle Note Save
 * Forwards to: /app/Modules/Products/Api/handle-note-save.php
 */

$__dir = __DIR__;
for ($__i = 0; $__i < 5; $__i++) {
    $__candidate = $__dir . '/app/Modules/Products/Api/handle-note-save.php';
    if (is_file($__candidate)) {
        require $__candidate;
        exit;
    }
    $__dir = dirname($__dir);
}
http_response_code(500);
echo json_encode(['error' => 'Module file not found']);

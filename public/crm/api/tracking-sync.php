<?php
/**
 * Tracking Batch Sync API — shim
 * Routes to /app/Modules/Team/Api/tracking-sync.php
 */
$__dir = __DIR__;
for ($__i = 0; $__i < 5; $__i++) {
    $__dir = dirname($__dir);
    if (is_file($__dir . '/app/Modules/Team/Api/tracking-sync.php')) {
        require $__dir . '/app/Modules/Team/Api/tracking-sync.php';
        return;
    }
}
// Fallback: direct include for flat production structure
if (is_file(dirname(dirname(__DIR__)) . '/app/Modules/Team/Api/tracking-sync.php')) {
    require dirname(dirname(__DIR__)) . '/app/Modules/Team/Api/tracking-sync.php';
} else {
    http_response_code(500);
    echo json_encode(['error' => 'Module not found']);
}

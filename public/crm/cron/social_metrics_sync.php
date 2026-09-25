<?php
/**
 * Shim — routes web POST to /app/Modules/Social/Cron/metrics_sync.php
 * Callable via web POST (admin only) or directly by cron.
 */

ini_set('display_errors', '0');
register_shutdown_function(function () {
    $e = error_get_last();
    if ($e && ($e['type'] & (E_ERROR | E_PARSE | E_CORE_ERROR | E_COMPILE_ERROR))) {
        if (!headers_sent()) {
            header('Content-Type: application/json', true, 500);
        }
        echo json_encode([
            'success' => false,
            'error'   => 'PHP fatal: ' . $e['message']
                       . ' (' . basename($e['file']) . ':' . $e['line'] . ')',
        ]);
    }
});

$__dir = __DIR__;
for ($__i = 0; $__i < 6; $__i++) {
    $__dir = dirname($__dir);
    if (is_file($__dir . '/app/Core/paths.php')) {
        require_once $__dir . '/app/Core/paths.php';
        break;
    }
}
unset($__dir, $__i);

if (!defined('APP_ROOT')) {
    header('Content-Type: application/json', true, 500);
    echo json_encode(['success' => false, 'error' => 'Could not locate app/Core/paths.php']);
    exit;
}

require_once APP_ROOT . '/Modules/Social/Cron/metrics_sync.php';

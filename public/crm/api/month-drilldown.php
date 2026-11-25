<?php
/**
 * Month drill-down — statement lines beside book entries for one account and month (2026-10-25). READ ONLY. Admin only.
 *
 * GET ?account=1010&month=2026-01   the unpaired lines on each side and what they add up to
 * `mode` is not used; there is one read.
 */
declare(strict_types=1);
header('Content-Type: application/json');
header('Cache-Control: no-store');

$__dir = __DIR__;
for ($__i = 0; $__i < 5; $__i++) {
    $__dir = dirname($__dir);
    if (is_file($__dir . '/app/Core/paths.php')) {
        require_once $__dir . '/app/Core/paths.php';
        break;
    }
}
unset($__dir, $__i);

require_once PUBLIC_ROOT . '/loginAuth/auth.php';
requireLogin();
if (!isAdmin()) {
    http_response_code(403);
    echo json_encode(['ok' => false, 'error' => 'Admin only']);
    exit;
}
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'GET') {
    http_response_code(405);
    echo json_encode(['ok' => false, 'error' => 'Read only']);
    exit;
}
require_once APP_ROOT . '/Modules/Accounting/Services/MonthDrilldownService.php';
session_write_close();
set_time_limit(120);

try {
    $r = (new MonthDrilldownService(getDB()))->month((string)($_GET['account'] ?? '1010'), (string)($_GET['month'] ?? ''));
    echo json_encode(isset($r['error']) ? ['ok' => false, 'error' => $r['error']] : ['ok' => true] + $r, JSON_PRETTY_PRINT);
} catch (Throwable $e) {
    error_log('[month-drilldown] ' . $e->getMessage() . ' @ ' . $e->getFile() . ':' . $e->getLine());
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => 'Could not build the month']);
}

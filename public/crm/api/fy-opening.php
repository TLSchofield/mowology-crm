<?php
/**
 * FY2026 opening — JSON for /crm/accounting/fy-opening.php (2026-10-07).
 * Logic: Fy2026OpeningService. Admin (owner) only.
 *
 * GET  ?mode=report                          every line: CRM had / filed / adjustment (read only)
 * POST {mode:'book', signature, csrf_token}   book the opening entry (rebuilt + signature checked server-side)
 * POST {mode:'undo', csrf_token}              reverse it
 * `mode`, never `action` (Known-Failure-Patterns: the API router appends its own action).
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
require_once APP_ROOT . '/Modules/Accounting/Services/Fy2026OpeningService.php';

$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
$input  = $method === 'POST' ? (json_decode((string)file_get_contents('php://input'), true) ?? []) : $_GET;
$mode   = (string)($input['mode'] ?? 'report');
$user   = getCurrentUser();
$userId = (int)($user['id'] ?? 0);

if ($method === 'POST' && !verifyCSRFToken((string)($input['csrf_token'] ?? ''))) {
    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => 'Your session token is stale — reload the page.']);
    exit;
}
session_write_close();
set_time_limit(300);

$svc = new Fy2026OpeningService(getDB());
try {
    if ($method === 'GET' && $mode === 'report') {
        echo json_encode(['ok' => true] + $svc->preview());
    } elseif ($method === 'POST' && $mode === 'book') {
        echo json_encode($svc->book((string)($input['signature'] ?? ''), $userId));
    } elseif ($method === 'POST' && $mode === 'undo') {
        echo json_encode($svc->undo($userId));
    } else {
        http_response_code(400);
        echo json_encode(['ok' => false, 'error' => 'Unknown mode']);
    }
} catch (Throwable $e) {
    error_log('[fy-opening] ' . $mode . ': ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => 'Something went wrong — nothing further was changed. The error is in the PHP log.']);
}

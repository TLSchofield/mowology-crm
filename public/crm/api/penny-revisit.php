<?php
/**
 * Penny's revisit list — JSON for /crm/accounting/penny-revisit.php (2026-10-21).
 * Logic: PennyRevisitService. Owner only; CSRF on every POST. `mode`, never `action`
 * (Known-Failure-Patterns: the API router appends its own action).
 *
 * GET  ?mode=list                      proposals grouped by payee (+ applied, + the accountant's list)
 * POST {mode:'scan', dry_run:0|1}      look again at unlinked bank lines; dry_run only reports
 * POST {mode:'apply', ids:[...]}       the owner approves: move those proposed lines
 * POST {mode:'dismiss', ids:[...]}     the owner says no; never proposed again
 * POST {mode:'undo', ids:[...]}        put applied lines back where they came from
 */
declare(strict_types=1);
header('Content-Type: application/json');
header('Cache-Control: no-store');

$__dir = __DIR__;
for ($__i = 0; $__i < 5; $__i++) {
    $__dir = dirname($__dir);
    if (is_file($__dir . '/app/Core/paths.php')) { require_once $__dir . '/app/Core/paths.php'; break; }
}
unset($__dir, $__i);

require_once PUBLIC_ROOT . '/loginAuth/auth.php';
requireLogin();
if (!isAdmin()) {
    http_response_code(403);
    echo json_encode(['ok' => false, 'error' => 'Owner only']);
    exit;
}
require_once APP_ROOT . '/Modules/Accounting/Services/PennyRevisitService.php';

$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
$input  = $method === 'POST' ? (json_decode((string)file_get_contents('php://input'), true) ?? []) : $_GET;
$mode   = (string)($input['mode'] ?? 'list');
$user   = getCurrentUser();
$userId = (int)($user['id'] ?? 0);

if ($method === 'POST' && !verifyCSRFToken((string)($input['csrf_token'] ?? ''))) {
    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => 'Your session token is stale — reload the page.']);
    exit;
}
session_write_close();
@set_time_limit(300);

$svc = new PennyRevisitService(getDB());
$ids = static function ($v): array { return array_values(array_filter(array_map('intval', is_array($v) ? $v : []), static function ($i) { return $i > 0; })); };

try {
    $previewOnly = $method === 'POST' && $mode === 'scan' && !empty($input['dry_run']);   // read-only: allowed before the migration
    if (!$svc->ready() && !$previewOnly) {
        echo json_encode(['ok' => false, 'error' => 'Run migration 1395 first (the penny_revisits table).']);
        exit;
    }
    if ($method === 'GET' && $mode === 'list') {
        echo json_encode(['ok' => true, 'mode' => $svc->mode(), 'waiting' => $svc->listGroups('proposed'), 'applied' => $svc->listGroups('applied')]);
    } elseif ($method === 'POST' && $mode === 'scan') {
        echo json_encode(['ok' => true, 'scan' => $svc->scan(!empty($input['dry_run']))]);
    } elseif ($method === 'POST' && $mode === 'apply') {
        echo json_encode(['ok' => true] + $svc->apply($ids($input['ids'] ?? []), $userId));
    } elseif ($method === 'POST' && $mode === 'dismiss') {
        echo json_encode(['ok' => true, 'dismissed' => $svc->dismiss($ids($input['ids'] ?? []), $userId)]);
    } elseif ($method === 'POST' && $mode === 'undo') {
        echo json_encode(['ok' => true] + $svc->undo($ids($input['ids'] ?? []), $userId));
    } else {
        http_response_code(400);
        echo json_encode(['ok' => false, 'error' => 'Unknown mode']);
    }
} catch (Throwable $e) {
    error_log('[penny-revisit] ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => 'Server error']);
}

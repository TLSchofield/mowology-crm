<?php
/**
 * Penny's look-back review of 2026 — JSON for /crm/accounting/lookback.php (migration 1250).
 * Logic: LookbackService. Admin only (owner / admin).
 *
 * GET  ?mode=summary                                   counts by kind, $ / GST, GST per quarter, AI spend
 * GET  ?mode=proposals[&kind=][&status=open][&offset=]  proposals (read only)
 * POST {mode:'scan', ai_calls?: 0..40, dry_run?: bool, csrf_token}   look again (rules; AI only when ai_calls > 0)
 * POST {mode:'approve', id, csrf_token}                apply one proposal
 * POST {mode:'approve_kind', kind, csrf_token}         apply every high-confidence open one of a kind
 * POST {mode:'skip', id, note?, csrf_token}            leave it — never proposed again
 * POST {mode:'undo', id, csrf_token}                   put an applied change back
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
require_once APP_ROOT . '/Modules/Expenses/ExpenseConstants.php';
require_once APP_ROOT . '/Modules/Accounting/Services/LookbackService.php';

$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
$input  = $method === 'POST' ? (json_decode((string)file_get_contents('php://input'), true) ?? []) : $_GET;
$mode   = (string)($input['mode'] ?? 'summary');
$user   = getCurrentUser();

if ($method === 'POST' && !verifyCSRFToken((string)($input['csrf_token'] ?? ''))) {
    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => 'Your session token is stale — reload the page.']);
    exit;
}
session_write_close();
set_time_limit(300);

$db  = getDB();
$svc = new LookbackService($db);
if (!$svc->ready()) {
    echo json_encode(['ok' => false, 'error' => 'Run migration 1250 first (Database → Migrations).']);
    exit;
}
try {
    if ($method === 'GET' && $mode === 'summary') {
        echo json_encode(['ok' => true] + $svc->summary());
    } elseif ($method === 'GET' && $mode === 'proposals') {
        $status = (string)($input['status'] ?? 'open');
        if (!in_array($status, ['open', 'info', 'applied', 'skipped', 'undone', 'stale', 'failed', 'all'], true)) $status = 'open';
        $kind = (string)($input['kind'] ?? '');
        echo json_encode(['ok' => true, 'proposals' => $svc->proposals($kind !== '' ? $kind : null, $status, 100, max(0, (int)($input['offset'] ?? 0)))]);
    } elseif ($method === 'POST' && $mode === 'scan') {
        $ai = max(0, min(40, (int)($input['ai_calls'] ?? 0)));
        $r = $svc->scan(['ai_calls' => $ai, 'dry_run' => !empty($input['dry_run'])]);
        unset($r['list']);
        echo json_encode(['ok' => true] + $r);
    } elseif ($method === 'POST' && $mode === 'approve') {
        echo json_encode($svc->approve((int)($input['id'] ?? 0), (array)$user));
    } elseif ($method === 'POST' && $mode === 'approve_kind') {
        echo json_encode($svc->approveKind((string)($input['kind'] ?? ''), (array)$user));
    } elseif ($method === 'POST' && $mode === 'skip') {
        echo json_encode($svc->skip((int)($input['id'] ?? 0), (array)$user, mb_substr(trim((string)($input['note'] ?? '')), 0, 400)));
    } elseif ($method === 'POST' && $mode === 'undo') {
        echo json_encode($svc->undo((int)($input['id'] ?? 0), (array)$user));
    } else {
        http_response_code(400);
        echo json_encode(['ok' => false, 'error' => 'Unknown mode']);
    }
} catch (Throwable $e) {
    error_log('[lookback] ' . $mode . ': ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => 'Something went wrong — nothing further was changed. The error is in the PHP log.']);
}

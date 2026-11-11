<?php
/**
 * Penny's income clean-up — JSON for /crm/accounting/income-cleanup.php (2026-10-07).
 * Logic: IncomeCleanupService (proposal / book / skip / reverse) and
 * CreditCardPayableAuditService (2400 report). Admin only.
 *
 * GET  ?mode=proposal[&year=2026]       the grouped proposal + income/GST before → after (read only)
 * GET  ?mode=cc2400                     Credit Card Payable breakdown (read only)
 * POST {mode:'book', approved:{txId: signature, …}, csrf_token}   book approved lines
 * POST {mode:'skip', transaction_id, note?, csrf_token}            leave a line as it is
 * POST {mode:'unskip', transaction_id, csrf_token}
 * POST {mode:'reverse', log_id, csrf_token}                        undo one booking
 * POST {mode:'filing', from, to, filed_on?, basis, line_101?, notes?, csrf_token}  record a filed GST return
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
require_once APP_ROOT . '/Modules/Accounting/Services/IncomeCleanupService.php';
require_once APP_ROOT . '/Modules/Accounting/Services/CreditCardPayableAuditService.php';

$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
$input  = $method === 'POST' ? (json_decode((string)file_get_contents('php://input'), true) ?? []) : $_GET;
$mode   = (string)($input['mode'] ?? 'proposal');
$user   = getCurrentUser();
$userId = (int)($user['id'] ?? 0);
$year   = max(2024, min(2100, (int)($input['year'] ?? 2026)));

if ($method === 'POST' && !verifyCSRFToken((string)($input['csrf_token'] ?? ''))) {
    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => 'Your session token is stale — reload the page.']);
    exit;
}
session_write_close();
set_time_limit(300);

$db  = getDB();
$svc = new IncomeCleanupService($db);
try {
    if ($method === 'GET' && $mode === 'proposal') {
        echo json_encode(['ok' => true] + $svc->proposal($year));
    } elseif ($method === 'GET' && $mode === 'cc2400') {
        echo json_encode((new CreditCardPayableAuditService($db))->report());
    } elseif ($method === 'POST' && $mode === 'book') {
        $approved = [];
        foreach ((array)($input['approved'] ?? []) as $tx => $sig) {
            if ((int)$tx > 0) $approved[(int)$tx] = (string)$sig;
        }
        if (!$approved) { echo json_encode(['ok' => false, 'error' => 'Nothing approved.']); exit; }
        if (count($approved) > 300) { echo json_encode(['ok' => false, 'error' => 'At most 300 lines at once.']); exit; }
        echo json_encode(['ok' => true] + $svc->book($approved, $userId, $year));
    } elseif ($method === 'POST' && $mode === 'skip') {
        echo json_encode($svc->skip((int)($input['transaction_id'] ?? 0), $userId, mb_substr(trim((string)($input['note'] ?? '')), 0, 400), $year));
    } elseif ($method === 'POST' && $mode === 'unskip') {
        echo json_encode($svc->unskip((int)($input['transaction_id'] ?? 0), $userId));
    } elseif ($method === 'POST' && $mode === 'reverse') {
        echo json_encode($svc->reverse((int)($input['log_id'] ?? 0), $userId));
    } elseif ($method === 'POST' && $mode === 'filing') {
        $l101 = isset($input['line_101']) && $input['line_101'] !== '' ? round((float)$input['line_101'], 2) : null;
        echo json_encode($svc->recordFiling((string)($input['from'] ?? ''), (string)($input['to'] ?? ''), (string)($input['filed_on'] ?? '') ?: null,
                                            (string)($input['basis'] ?? 'unknown'), $l101, mb_substr(trim((string)($input['notes'] ?? '')), 0, 400), $userId));
    } else {
        http_response_code(400);
        echo json_encode(['ok' => false, 'error' => 'Unknown mode']);
    }
} catch (Throwable $e) {
    error_log('[income-cleanup] ' . $mode . ': ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => 'Something went wrong — nothing further was changed. The error is in the PHP log.']);
}

<?php
/**
 * Payments reconciled against invoices — JSON for /crm/accounting/payment-match.php (2026-10-07).
 * Logic: PaymentMatchService (CRM invoices + the Jobber import, one matcher). Admin only.
 *
 * GET  ?mode=dryrun                       READ ONLY: counts and $ by outcome on the live data. Writes nothing.
 * GET  ?mode=scan                         the page: deposits + proposals + open invoices + log (refreshes Penny's cached count)
 * POST {mode:'book', approved:{txId: signature, …}, csrf_token}           book approved lines (signature-checked)
 * POST {mode:'manual', transaction_id, targets:[{key, amount?}], csrf_token}  a deposit + the items Tim ticked
 * POST {mode:'skip', transaction_id, note?, csrf_token} / {mode:'unskip', transaction_id, csrf_token}
 * POST {mode:'undo', log_id, csrf_token}                                    undo one approval through the path that booked it
 * `mode`, never `action` (Known-Failure-Patterns: the API router appends its own action). JSON bodies are tiny (ids +
 * signatures), well under the host's ~1 MB limit.
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
require_once APP_ROOT . '/Modules/Accounting/Services/PaymentMatchService.php';

$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
$input  = $method === 'POST' ? (json_decode((string)file_get_contents('php://input'), true) ?? []) : $_GET;
$mode   = (string)($input['mode'] ?? 'dryrun');
$user   = getCurrentUser();
$userId = (int)($user['id'] ?? 0);

if ($method === 'POST' && !verifyCSRFToken((string)($input['csrf_token'] ?? ''))) {
    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => 'Your session token is stale — reload the page.']);
    exit;
}
session_write_close();
set_time_limit(300);

$svc = new PaymentMatchService(getDB());
try {
    if ($method === 'GET' && $mode === 'dryrun') {
        echo json_encode($svc->dryRun());
    } elseif ($method === 'GET' && $mode === 'scan') {
        $scan = $svc->scan();
        if ($scan['ready']) $svc->snapshot($scan['summary']);   // Penny's cached count — a cache, not a booking
        echo json_encode($scan);
    } elseif ($method === 'POST' && $mode === 'book') {
        $approved = [];
        foreach ((array)($input['approved'] ?? []) as $tx => $sig) {
            if ((int)$tx > 0) $approved[(int)$tx] = (string)$sig;
        }
        if (!$approved) { echo json_encode(['ok' => false, 'error' => 'Nothing approved.']); exit; }
        if (count($approved) > 300) { echo json_encode(['ok' => false, 'error' => 'At most 300 deposits at once.']); exit; }
        echo json_encode($svc->book($approved, $userId));
    } elseif ($method === 'POST' && $mode === 'manual') {
        $targets = [];
        foreach ((array)($input['targets'] ?? []) as $t) {
            if (!is_array($t) || empty($t['key'])) continue;
            $targets[] = ['key' => mb_substr((string)$t['key'], 0, 120), 'amount' => isset($t['amount']) && $t['amount'] !== '' ? (float)$t['amount'] : null];
        }
        if (count($targets) > 60) { echo json_encode(['ok' => false, 'message' => 'At most 60 items on one deposit.']); exit; }
        echo json_encode($svc->manual((int)($input['transaction_id'] ?? 0), $targets, $userId));
    } elseif ($method === 'POST' && $mode === 'skip') {
        echo json_encode($svc->skip((int)($input['transaction_id'] ?? 0), $userId, mb_substr(trim((string)($input['note'] ?? '')), 0, 400)));
    } elseif ($method === 'POST' && $mode === 'unskip') {
        echo json_encode($svc->unskip((int)($input['transaction_id'] ?? 0), $userId));
    } elseif ($method === 'POST' && $mode === 'undo') {
        echo json_encode($svc->undo((int)($input['log_id'] ?? 0), $userId));
    } else {
        http_response_code(400);
        echo json_encode(['ok' => false, 'error' => 'Unknown mode']);
    }
} catch (Throwable $e) {
    error_log('[payment-match] ' . $mode . ': ' . $e->getMessage() . ' @ ' . $e->getFile() . ':' . $e->getLine());
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => 'Something went wrong — nothing further was changed. The error is in the PHP log.']);
}

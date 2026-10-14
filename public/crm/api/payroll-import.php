<?php
/**
 * Payroll import + shareholder account — JSON for /crm/accounting/payroll-import.php (2026-10-07).
 * Logic: WavePayrollImportService, ShareholderAccountService. Admin (owner) only, CSRF on every POST.
 *
 * GET  ?mode=report                                              months, matches, hours, shareholder
 * POST multipart {mode:'upload', csrf_token, report: PDF}        read a Wave Wage & Tax Report
 * POST {mode:'upload_text', text}                                the same, pasted
 * POST {mode:'approve', run_id, tx_ids[], book_estimate}         book a month (+ move the ticked lines)
 * POST {mode:'undo', run_id} | {mode:'discard', run_id}
 * POST {mode:'set_shareholder', name}
 * POST {mode:'sh_apply', tx_ids[]} | {mode:'sh_undo', batch_id}  repayments / personal charges → 1300
 * POST {mode:'clearing_add', type, date, amount, withholdings, note} | {mode:'clearing_undo', id}
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
    echo json_encode(['ok' => false, 'error' => 'Owner only']);
    exit;
}
require_once APP_ROOT . '/Modules/Accounting/Services/WavePayrollImportService.php';
require_once APP_ROOT . '/Modules/Accounting/Services/ShareholderAccountService.php';

$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
$isMultipart = stripos((string)($_SERVER['CONTENT_TYPE'] ?? ''), 'multipart/form-data') !== false;
$input = $method === 'POST'
    ? ($isMultipart ? $_POST : (json_decode((string)file_get_contents('php://input'), true) ?? []))
    : $_GET;
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

$db = getDB();
$pay = new WavePayrollImportService($db);
$sh  = new ShareholderAccountService($db);
$ids = static fn($v) => array_values(array_filter(array_map('intval', is_array($v) ? $v : []), fn($i) => $i > 0));

try {
    if (!$pay->ready()) {
        echo json_encode(['ok' => false, 'error' => 'Run migration 1255 first (payroll tables).']);
        exit;
    }
    if ($method === 'GET' && $mode === 'report') {
        $r = $pay->report();
        if ($r['shareholder'] === null) $r['suggested_shareholder'] = (string)($user['full_name'] ?? '');
        echo json_encode(['ok' => true] + $r + ['shareholder_account' => $sh->report()]);
    } elseif ($method === 'POST' && $mode === 'upload') {
        $f = $_FILES['report'] ?? null;
        if (!$f || ($f['error'] ?? 1) !== UPLOAD_ERR_OK) { echo json_encode(['ok' => false, 'message' => 'Choose the Wave report PDF.']); exit; }
        if ((int)$f['size'] > 10 * 1024 * 1024) { echo json_encode(['ok' => false, 'message' => 'That file is over 10 MB.']); exit; }
        $head = (string)file_get_contents($f['tmp_name'], false, null, 0, 5);
        if ($head !== '%PDF-') { echo json_encode(['ok' => false, 'message' => 'That isn\'t a PDF.']); exit; }
        $text = WavePayrollImportService::pdfText($f['tmp_name']);
        @unlink($f['tmp_name']);   // the file itself is never kept
        echo json_encode($pay->import($text, basename((string)$f['name']), $userId));
    } elseif ($method === 'POST' && $mode === 'upload_text') {
        $text = (string)($input['text'] ?? '');
        if (strlen($text) > 2000000) { echo json_encode(['ok' => false, 'message' => 'That is too long.']); exit; }
        echo json_encode($pay->import($text, 'pasted ' . date('Y-m-d H:i'), $userId));
    } elseif ($method === 'POST' && $mode === 'approve') {
        echo json_encode($pay->approve((int)($input['run_id'] ?? 0), $ids($input['tx_ids'] ?? []), $userId, !empty($input['book_estimate'])));
    } elseif ($method === 'POST' && $mode === 'undo') {
        echo json_encode($pay->undo((int)($input['run_id'] ?? 0), $userId));
    } elseif ($method === 'POST' && $mode === 'discard') {
        echo json_encode($pay->discard((int)($input['run_id'] ?? 0)));
    } elseif ($method === 'POST' && $mode === 'set_shareholder') {
        echo json_encode($sh->setShareholderName((string)($input['name'] ?? ''), $userId));
    } elseif ($method === 'POST' && $mode === 'sh_apply') {
        echo json_encode($sh->apply($ids($input['tx_ids'] ?? []), $userId));
    } elseif ($method === 'POST' && $mode === 'sh_undo') {
        $batch = (string)($input['batch_id'] ?? '');
        if (!preg_match('/^sh-[0-9a-z-]{1,36}$/', $batch)) { echo json_encode(['ok' => false, 'message' => 'Which batch?']); exit; }
        echo json_encode($sh->undoBatch($batch, $userId));
    } elseif ($method === 'POST' && $mode === 'clearing_add') {
        echo json_encode($sh->addClearing((string)($input['type'] ?? ''), (string)($input['date'] ?? ''), (float)($input['amount'] ?? 0),
                                          (float)($input['withholdings'] ?? 0), (string)($input['note'] ?? ''), $userId));
    } elseif ($method === 'POST' && $mode === 'clearing_undo') {
        echo json_encode($sh->undoClearing((int)($input['id'] ?? 0), $userId));
    } else {
        http_response_code(400);
        echo json_encode(['ok' => false, 'error' => 'Unknown mode']);
    }
} catch (Throwable $e) {
    error_log('[payroll-import] ' . $mode . ': ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => 'Something went wrong — the error is in the PHP log.']);
}

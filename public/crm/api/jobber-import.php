<?php
/**
 * Jobber import — JSON for /crm/accounting/jobber-import.php (2026-10-07).
 * Logic: JobberImportService (CSV → history) + JobberLedgerService (2026 → journal). Admin only.
 *
 * GET  ?mode=report                                         what is imported + the 2026 proposals
 * POST {mode:'preview', csv, mapping?}                      read a CSV: columns found, matches, counts (nothing written)
 * POST {mode:'import', csv, mapping?, create, filename}     store it (create = none | recent | all: contacts for unmatched clients)
 * POST {mode:'book_revenue', signature}                     2026 Jobber invoices → revenue
 * POST {mode:'book_deposits', picks:[{key, signature}]}     the ticked deposits → against Jobber invoices
 * POST {mode:'link_manual', transaction_id, numbers}        one deposit by hand, by Jobber invoice #s
 * POST {mode:'book_income', transaction_id}                 a deposit with no Jobber invoice → income
 * POST {mode:'link_crm', jobber_id, invoice_number}         an open Jobber invoice recreated in the CRM
 * POST {mode:'undo', batch_id}                              undo one approval
 * Every POST carries csrf_token. `mode`, never `action` (the API router appends its own action).
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
require_once APP_ROOT . '/Modules/Accounting/Services/JobberImportService.php';
require_once APP_ROOT . '/Modules/Accounting/Services/JobberLedgerService.php';

$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
if ($method === 'POST' && isset($_POST['payload'])) {
    // Multipart: the host rejects JSON bodies over ~1 MB (413), so a CSV comes as a file upload.
    $input = json_decode((string)$_POST['payload'], true) ?? [];
    if (isset($_FILES['csv_file']) && is_uploaded_file($_FILES['csv_file']['tmp_name'])) {
        $input['csv'] = (string)file_get_contents($_FILES['csv_file']['tmp_name']);
    }
} else {
    $input = $method === 'POST' ? (json_decode((string)file_get_contents('php://input'), true) ?? []) : $_GET;
}
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
$import = new JobberImportService($db);
$ledger = new JobberLedgerService($db);
$mapping = [];
if (is_array($input['mapping'] ?? null)) {
    foreach ($input['mapping'] as $k => $v) $mapping[(string)$k] = $v === null ? null : (string)$v;
}
try {
    if ($method === 'GET' && $mode === 'report') {
        echo json_encode(['ok' => true, 'summary' => $import->summary(), 'ledger' => $ledger->report()]);
    } elseif ($method === 'POST' && $mode === 'preview') {
        echo json_encode(['ok' => true] + $import->preview((string)($input['csv'] ?? ''), $mapping));
    } elseif ($method === 'POST' && $mode === 'import') {
        $create = (string)($input['create'] ?? 'none');
        if (!in_array($create, ['none', 'recent', 'all'], true)) $create = 'none';
        echo json_encode($import->import((string)($input['csv'] ?? ''), $mapping, $create, $userId, mb_substr((string)($input['filename'] ?? ''), 0, 200)));
    } elseif ($method === 'POST' && $mode === 'book_revenue') {
        echo json_encode($ledger->bookRevenue((string)($input['signature'] ?? ''), $userId));
    } elseif ($method === 'POST' && $mode === 'book_deposits') {
        echo json_encode($ledger->bookDeposits(is_array($input['picks'] ?? null) ? $input['picks'] : [], $userId));
    } elseif ($method === 'POST' && $mode === 'link_manual') {
        $nums = is_array($input['numbers'] ?? null) ? $input['numbers'] : preg_split('/[\s,]+/', (string)($input['numbers'] ?? ''), -1, PREG_SPLIT_NO_EMPTY);
        echo json_encode($ledger->linkManual((int)($input['transaction_id'] ?? 0), $nums ?: [], $userId));
    } elseif ($method === 'POST' && $mode === 'book_income') {
        echo json_encode($ledger->bookAsIncome((int)($input['transaction_id'] ?? 0), $userId));
    } elseif ($method === 'POST' && $mode === 'link_crm') {
        echo json_encode($ledger->linkCrmInvoice((int)($input['jobber_id'] ?? 0), (string)($input['invoice_number'] ?? ''), $userId));
    } elseif ($method === 'POST' && $mode === 'undo') {
        $batch = (string)($input['batch_id'] ?? '');
        if (!preg_match('/^jbl-[0-9a-z-]{1,36}$/', $batch)) { echo json_encode(['ok' => false, 'message' => 'Which approval?']); exit; }
        echo json_encode($ledger->undo($batch, $userId));
    } else {
        http_response_code(400);
        echo json_encode(['ok' => false, 'error' => 'Unknown mode']);
    }
} catch (Throwable $e) {
    error_log('[jobber-import] ' . $mode . ': ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => 'Something went wrong — nothing further was changed. The error is in the PHP log.']);
}

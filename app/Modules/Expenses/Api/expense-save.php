<?php
/**
 * iOS Expense Save API — JWT-authenticated
 *
 * POST JSON: expense fields from the iOS review form.
 * No CSRF token required — JWT Bearer is the auth mechanism.
 *
 * Returns: { success: true, expense_id: N }
 */
declare(strict_types=1);

if (!defined('APP_ROOT')) {
    $__dir = __DIR__;
    for ($__i = 0; $__i < 5; $__i++) {
        $__dir = dirname($__dir);
        if (is_file($__dir . '/app/Core/paths.php')) {
            require_once $__dir . '/app/Core/paths.php';
            break;
        }
    }
    unset($__dir, $__i);
}

header('Content-Type: application/json');

try {
    require_once APP_ROOT . '/Core/Auth/JwtAuth.php';
    require_once PUBLIC_ROOT . '/loginAuth/auth.php';
    require_once CRM_INCLUDES . '/functions.php';

    $jwtUser = requireJwt();
    $userId  = (int)$jwtUser['id'];

    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        http_response_code(405);
        echo json_encode(['success' => false, 'error' => 'POST required']);
        exit;
    }

    $input = json_decode(file_get_contents('php://input'), true);
    if (!is_array($input)) {
        echo json_encode(['success' => false, 'error' => 'JSON body required']);
        exit;
    }

    $expenseDate = $input['expense_date'] ?? date('Y-m-d');
    $total       = (float)($input['total'] ?? 0);

    // Mobile submits for desktop review: only draft / pending_approval are accepted here.
    // approved / rejected / forwarded require the audited ExpenseApprovalService paths.
    $requestedStatus = (string)($input['status'] ?? 'pending_approval');
    $status = in_array($requestedStatus, ['draft', 'pending_approval'], true) ? $requestedStatus : 'pending_approval';

    if ($total <= 0 && empty($input['description'])) {
        echo json_encode(['success' => false, 'error' => 'Total amount or description is required']);
        exit;
    }

    // One photo, one expense (see ExpenseCreateGuard): a repeat of this save answers with
    // the expense already made from the photo instead of inserting another.
    require_once APP_ROOT . '/Modules/Expenses/Services/ExpenseCreateGuard.php';
    $createGuard  = new ExpenseCreateGuard(getDB());
    $guardMediaId = !empty($input['receipt_media_id']) ? (int)$input['receipt_media_id'] : null;
    $existingId   = $createGuard->claim($guardMediaId);
    if ($existingId !== null) {
        echo json_encode(ExpenseCreateGuard::existingResponse($existingId));
        exit;
    }

    $anomalyFlags = '';
    $anomalyScore = 0;
    try {
        require_once APP_ROOT . '/Services/Receipts/AnomalyDetector.php';
        $anomalyData   = array_merge($input, ['total' => $total, 'expense_date' => $expenseDate, 'created_by' => $userId]);
        $anomalyResult = detectAnomalies($anomalyData, getDB());
        $anomalyFlags  = $anomalyResult['flags'] ?? '';
        $anomalyScore  = $anomalyResult['score'] ?? 0;
    } catch (Throwable $e) {
        error_log('Anomaly detection error: ' . $e->getMessage());
    }

    $db = getDB();
    // Through the expense gate (migration 1233): the insert, its line items, the printed
    // facts, the duplicate check, the capture baseline + line-item lessons (when OCR'd),
    // line prices and the audit row — the same door as the desktop save.
    require_once APP_ROOT . '/Modules/Expenses/Services/ExpenseGate.php';
    try {
        $ocrd = !empty($input['raw_ocr_json']) && !empty($input['ocr_parsed']);
        $created = (new ExpenseGate($db))->apply(null, ExpenseGate::rowFromInput($input, [
            'expense_date'  => $expenseDate,
            'total'         => $total,
            'anomaly_flags' => $anomalyFlags ?: null,
            'anomaly_score' => $anomalyScore,
            'status'        => $status,
            'created_by'    => $userId,
        ]) + (!empty($input['line_items']) && is_array($input['line_items']) ? ['line_items' => $input['line_items']] : []),
            ['id' => $userId, 'kind' => 'user'], 'ios_save', [
            'ocr_parsed'  => $ocrd ? $input['ocr_parsed'] : null,
            'learn_lines' => $ocrd ? $input : null,
            'price_intel' => true,
        ]);
        $expenseId = (int)$created['expense_id'];
    } finally {
        $createGuard->release($guardMediaId);   // the INSERT is in: the next request sees it
    }

    // "Save & Send" — same one-tap flow as the Android review card (mobileSaveExpense(true)
    // → receipt-send.php). The expense is already committed above; a send failure is
    // reported, never fatal, so the record is never lost.
    $sent      = false;
    $sendError = null;
    if (!empty($input['and_send'])) {
        if (!jwtUserHasPermission($jwtUser, 'expenses.send')) {
            $sendError = 'Permission denied: expenses.send required';
        } elseif (empty($input['receipt_media_id'])) {
            $sendError = 'No receipt image attached to this expense';
        } else {
            try {
                require_once APP_ROOT . '/Services/Receipts/ReceiptService.php';
                $sendResult = sendReceiptToAccounting([
                    'expense_id'          => $expenseId,
                    'media_id'            => (int)$input['receipt_media_id'],
                    'vendor'              => $input['vendor_name_raw'] ?? 'Unknown',
                    'subtotal'            => (string)($input['amount'] ?? '0.00'),
                    'gst_amount'          => (string)($input['gst_amount'] ?? '0.00'),
                    'total'               => (string)$total,
                    'date'                => $expenseDate,
                    'job_id'              => $input['job_id'] ?? null,
                    'description'         => $input['description'] ?? '',
                    'accounting_category' => $input['accounting_category'] ?? '',
                ], $userId);
                $sent = !empty($sendResult['success']);
                if (!$sent) {
                    $sendError = $sendResult['error'] ?? 'Send failed';
                }
            } catch (Throwable $e) {
                $sendError = $e->getMessage();
            }
        }
    }

    echo json_encode([
        'success'    => true,
        'expense_id' => $expenseId,
        'sent'       => $sent,
        'send_error' => $sendError,
    ]);

} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => 'Save failed: ' . $e->getMessage()]);
}

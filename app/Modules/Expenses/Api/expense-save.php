<?php
/**
 * Expense Save API — JWT-authenticated
 *
 * POST /api/expenses/expense-save
 * Authorization: Bearer <jwt>
 * Content-Type: application/json
 *
 * Creates a new expense record from a reviewed receipt.
 * Called by the iOS app after the user confirms OCR-parsed fields.
 * No CSRF required — stateless JWT auth.
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

    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        http_response_code(405);
        echo json_encode(['success' => false, 'error' => 'POST required']);
        exit;
    }

    $jwtUser = requireJwt();
    $userId  = (int)$jwtUser['id'];

    $input = json_decode(file_get_contents('php://input'), true);
    if (!$input) {
        http_response_code(400);
        echo json_encode(['success' => false, 'error' => 'Invalid JSON body']);
        exit;
    }

    $total       = (float)($input['total'] ?? 0);
    $expenseDate = $input['expense_date'] ?? date('Y-m-d');

    // Validate/clamp expense_date — OCR can emit "2090-..." from a "10/90"
    // on the shoulder of a receipt. Reject any year outside [2020, current+1]
    // and fall back to today so a bad row can't be saved.
    if (preg_match('/^(\d{4})-/', $expenseDate, $m)) {
        $y       = (int)$m[1];
        $minYear = 2020;
        $maxYear = (int)date('Y') + 1;
        if ($y < $minYear || $y > $maxYear) {
            error_log("expense-save: clamped impossible expense_date {$expenseDate} -> today (user {$userId})");
            $expenseDate = date('Y-m-d');
        }
    } else {
        // Not YYYY-MM-DD shape — fall back to today.
        $expenseDate = date('Y-m-d');
    }

    if ($total <= 0) {
        http_response_code(400);
        echo json_encode(['success' => false, 'error' => 'Total amount is required']);
        exit;
    }

    $db = getDB();

    // Anomaly detection — non-critical, failures don't block the save
    $anomalyFlags = null;
    $anomalyScore = 0;
    try {
        require_once APP_ROOT . '/Services/Receipts/AnomalyDetector.php';
        $anomalyData   = array_merge($input, [
            'total'        => $total,
            'expense_date' => $expenseDate,
            'created_by'   => $userId,
        ]);
        $anomalyResult = detectAnomalies($anomalyData, $db);
        $anomalyFlags  = $anomalyResult['flags'] ?? null;
        $anomalyScore  = (int)($anomalyResult['score'] ?? 0);
    } catch (Throwable $e) {
        error_log('Expense save anomaly error: ' . $e->getMessage());
    }

    $stmt = $db->prepare("
        INSERT INTO expenses
            (expense_date, vendor_id, vendor_name_raw, amount, gst_amount, total,
             accounting_category, payment_method, receipt_media_id,
             receipt_lat, receipt_lng, anomaly_flags, anomaly_score,
             job_id, notes, status, created_by)
        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
    ");
    $stmt->execute([
        $expenseDate,
        !empty($input['vendor_id'])      ? (int)$input['vendor_id']      : null,
        $input['vendor_name_raw']        ?? null,
        (float)($input['amount']         ?? 0),
        (float)($input['gst_amount']     ?? 0),
        $total,
        $input['accounting_category']    ?? null,
        $input['payment_method']         ?? null,
        !empty($input['receipt_media_id']) ? (int)$input['receipt_media_id'] : null,
        !empty($input['receipt_lat'])    ? (float)$input['receipt_lat']   : null,
        !empty($input['receipt_lng'])    ? (float)$input['receipt_lng']   : null,
        $anomalyFlags,
        $anomalyScore,
        !empty($input['job_id'])         ? (int)$input['job_id']          : null,
        $input['notes']                  ?? null,
        $input['status']                 ?? 'draft',
        $userId,
    ]);

    $expenseId = (int)$db->lastInsertId();

    // Record OCR corrections for vendor learning — non-critical
    if (!empty($input['vendor_id']) && !empty($input['ocr_parsed'])) {
        try {
            require_once APP_ROOT . '/Services/Receipts/ReceiptLearning.php';
            $ocrParsed = is_string($input['ocr_parsed'])
                ? json_decode($input['ocr_parsed'], true)
                : $input['ocr_parsed'];
            if (is_array($ocrParsed)) {
                applyLearnedPatterns((int)$input['vendor_id'], $ocrParsed, '');
            }
        } catch (Throwable $e) {
            error_log('Receipt learning error: ' . $e->getMessage());
        }
    }

    echo json_encode([
        'success'    => true,
        'expense_id' => $expenseId,
    ]);

} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => 'Server error']);
}

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

    if ($total <= 0 && empty($input['description'])) {
        echo json_encode(['success' => false, 'error' => 'Total amount or description is required']);
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

    $db   = getDB();
    $stmt = $db->prepare("
        INSERT INTO expenses
            (expense_date, vendor_id, vendor_name_raw, description, amount, gst_amount, pst_amount, total,
             accounting_category, gbp_category, payment_method, receipt_media_id,
             receipt_lat, receipt_lng, match_confidence, anomaly_flags, anomaly_score, raw_ocr_json,
             job_id, notes, status, created_by)
        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
    ");
    $stmt->execute([
        $expenseDate,
        !empty($input['vendor_id'])       ? (int)$input['vendor_id']       : null,
        $input['vendor_name_raw']         ?? null,
        $input['description']             ?? null,
        (float)($input['amount']          ?? 0),
        (float)($input['gst_amount']      ?? 0),
        (float)($input['pst_amount']      ?? 0),
        $total,
        $input['accounting_category']     ?? null,
        $input['gbp_category']            ?? null,
        $input['payment_method']          ?? null,
        !empty($input['receipt_media_id']) ? (int)$input['receipt_media_id'] : null,
        !empty($input['receipt_lat'])      ? (float)$input['receipt_lat']    : null,
        !empty($input['receipt_lng'])      ? (float)$input['receipt_lng']    : null,
        (int)($input['match_confidence']  ?? 0),
        $anomalyFlags ?: null,
        $anomalyScore,
        $input['raw_ocr_json']            ?? null,
        !empty($input['job_id'])          ? (int)$input['job_id']           : null,
        $input['notes']                   ?? null,
        'draft',
        $userId,
    ]);

    $expenseId = (int)$db->lastInsertId();

    // Save line items if the OCR/review payload included them
    if (!empty($input['line_items']) && is_array($input['line_items'])) {
        require_once APP_ROOT . '/Services/Receipts/ExpenseLineItems.php';
        saveLineItems($db, $expenseId, $input['line_items']);

        if (!empty($input['vendor_id'])) {
            try {
                require_once APP_ROOT . '/Services/Receipts/PriceIntelligence.php';
                recordLineItemPrices($expenseId, (int)$input['vendor_id'], $input['line_items'], $expenseDate);
            } catch (Throwable $e) {
                error_log('Price intelligence error: ' . $e->getMessage());
            }
        }
    }

    // Record OCR corrections for self-learning
    if (!empty($input['raw_ocr_json']) && !empty($input['ocr_parsed'])) {
        try {
            require_once APP_ROOT . '/Services/Receipts/ReceiptLearning.php';
            $ocrParsed = is_string($input['ocr_parsed'])
                ? json_decode($input['ocr_parsed'], true)
                : $input['ocr_parsed'];
            if (is_array($ocrParsed)) {
                recordCorrections(
                    !empty($input['vendor_id']) ? (int)$input['vendor_id'] : null,
                    $input['vendor_name_raw'] ?? null,
                    $ocrParsed,
                    $input,
                    $input['raw_ocr_json']
                );
            }
        } catch (Throwable $e) {
            error_log('OCR learning error: ' . $e->getMessage());
        }
    }

    echo json_encode(['success' => true, 'expense_id' => $expenseId]);

} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => 'Save failed: ' . $e->getMessage()]);
}

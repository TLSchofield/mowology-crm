<?php
/**
 * POST /api/expenses/receipt-capture — a phone's receipt in one call (Android native receipts 1.7.0).
 *
 * Auth: Authorization: Bearer <app token> (the same JWT as the iPhone); needs expenses.edit, as the web capture.
 * multipart/form-data:
 *   receipt_photo  image (required)
 *   client_ref     the phone's own reference for this receipt, 8–64 [A-Za-z0-9_-] (required) — a repeat
 *                  answers with the expense already made
 *   total, note, visit_id, lat, lng   optional, what the crew typed / picked
 * 200 {success, duplicate, expense_id, date, total, vendor, status}; 4xx = refused (the phone keeps the photo
 * and shows why, never resends); 429 / 5xx = try again later. Rules: ReceiptCaptureService.
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
    require_once APP_ROOT . '/Core/ApiResponse.php';
    require_once PUBLIC_ROOT . '/loginAuth/auth.php';
    require_once CRM_INCLUDES . '/functions.php';
    require_once APP_ROOT . '/Modules/Expenses/Services/ExpenseGate.php';
    require_once APP_ROOT . '/Modules/Expenses/Services/ReceiptCaptureService.php';

    $jwtUser = requireJwt();
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        http_response_code(405);
        echo json_encode(['success' => false, 'error' => 'POST required']);
        exit;
    }
    if (!jwtUserHasPermission($jwtUser, 'expenses.edit')) {
        http_response_code(403);
        echo json_encode(['success' => false, 'error' => 'Your login can\'t add receipts - ask the office']);
        exit;
    }
    $ref = ReceiptCaptureService::cleanRef($_POST['client_ref'] ?? '');
    if ($ref === null) {
        http_response_code(400);
        echo json_encode(['success' => false, 'error' => 'Missing receipt reference - update the app']);
        exit;
    }

    $out = (new ReceiptCaptureService(getDB()))->capture((int)$jwtUser['id'], $_FILES['receipt_photo'] ?? [],
        ReceiptCaptureService::crewFields($_POST), $ref, date('Y-m-d'));
    http_response_code($out['http']);
    echo json_encode($out['body']);
} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => 'Receipt not saved yet: ' . ApiResponse::message($e, 'receipt-capture')]);
}

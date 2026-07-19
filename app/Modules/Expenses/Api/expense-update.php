<?php
/**
 * iOS Expense Update API — JWT-authenticated
 *
 * POST /api/expenses/expense-update
 * Body (JSON): { id, expense_date, vendor_name_raw, amount, gst_amount, total,
 *                accounting_category, payment_method, description, notes }
 *
 * Edits the user-correctable fields of an existing expense so field crew / owners can
 * fix OCR mistakes (wrong dates, vendors, totals) from the phone without switching to
 * the web app. Ownership- and status-guarded inside ExpenseService::update(); status,
 * approval and forwarding are untouched (those go through receipt-actions.php).
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
    require_once APP_ROOT . '/Modules/Expenses/Services/ExpenseService.php';

    $jwtUser = requireJwt();

    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        http_response_code(405);
        echo json_encode(['success' => false, 'error' => 'Method not allowed']);
        exit;
    }

    if (!jwtUserHasPermission($jwtUser, 'expenses.edit')) {
        http_response_code(403);
        echo json_encode(['success' => false, 'error' => 'Permission denied: expenses.edit required']);
        exit;
    }

    $input = json_decode(file_get_contents('php://input'), true);
    if (!is_array($input)) {
        http_response_code(400);
        echo json_encode(['success' => false, 'error' => 'Invalid request body']);
        exit;
    }

    $expenseId   = (int)($input['id'] ?? 0);
    $currentUser = [
        'id'       => (int)$jwtUser['id'],
        'is_admin' => jwtIsAdmin($jwtUser['role'] ?? ''),
    ];

    $result = (new ExpenseService(getDB()))->update($expenseId, $currentUser, $input);
    echo json_encode($result);

} catch (Throwable $e) {
    // Service throws domain errors (not found / wrong owner / already sent) as plain
    // Exceptions — surface their message so the app can show something actionable.
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
}

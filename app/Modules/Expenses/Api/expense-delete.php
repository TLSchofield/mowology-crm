<?php
/**
 * iOS Expense Delete API — JWT-authenticated.
 *
 * POST JSON: { id: int }
 * Returns:   { success: true, message, expense_id }
 *
 * Mirrors the web swipe-to-delete (expenses.php action=delete) through the shared
 * ExpenseService::delete(): crew may delete their own drafts, admins/managers any, and
 * nothing already forwarded to accounting can be deleted from either client.
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
    require_once APP_ROOT . '/Services/Receipts/ExpenseLineItems.php';
    require_once APP_ROOT . '/Modules/Expenses/Services/ExpenseService.php';

    $jwtUser = requireJwt();

    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        http_response_code(405);
        echo json_encode(['success' => false, 'error' => 'POST required']);
        exit;
    }

    if (!jwtUserHasPermission($jwtUser, 'expenses.edit')) {
        http_response_code(403);
        echo json_encode(['success' => false, 'error' => 'Permission denied: expenses.edit required']);
        exit;
    }

    $input = json_decode(file_get_contents('php://input'), true);
    if (!is_array($input)) {
        echo json_encode(['success' => false, 'error' => 'JSON body required']);
        exit;
    }

    $result = (new ExpenseService(getDB()))->delete(
        (int)($input['id'] ?? 0),
        ['id' => (int)$jwtUser['id'], 'is_admin' => jwtIsAdmin($jwtUser['role'] ?? '')]
    );
    echo json_encode($result);

} catch (Exception $e) {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => 'Delete failed: ' . $e->getMessage()]);
}

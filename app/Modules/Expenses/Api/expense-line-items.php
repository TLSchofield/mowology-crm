<?php
/**
 * iOS Expense Line Items API — JWT-authenticated.
 *
 * GET  /api/expenses/expense-line-items?expense_id=N
 *      → { success, line_items: [...], line_items_source }
 * POST /api/expenses/expense-line-items  JSON body:
 *      { op: 'update', line_item_id, name, quantity?, unit_price?, line_total? }
 *      { op: 'add',    expense_id, name, quantity?, unit_price?, line_total?, product_id?, sku_raw? }
 *      { op: 'delete', line_item_id }
 *      { op: 'link',   line_item_id, product_id|null }
 *      → { success, line_item? }
 *
 * Mirrors the web edit modal's line-item actions through the shared
 * ExpenseLineItemService, so a correction made on the phone teaches the parser
 * exactly like one made on the desktop. Ownership: crew act on their own
 * expenses, admins/managers on any; forwarded expenses are read-only.
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
    require_once APP_ROOT . '/Modules/Expenses/Services/ExpenseLineItemService.php';

    $jwtUser = requireJwt();
    $userId  = (int)$jwtUser['id'];
    $isAdmin = jwtIsAdmin($jwtUser['role'] ?? '');
    $db      = getDB();
    $svc     = new ExpenseLineItemService($db);

    $assertCanEdit = static function (int $expenseId) use ($db, $userId, $isAdmin): void {
        $stmt = $db->prepare("SELECT created_by, status, forwarded_to_accounting FROM expenses WHERE id = ?");
        $stmt->execute([$expenseId]);
        $e = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$e) {
            http_response_code(404);
            echo json_encode(['success' => false, 'error' => 'Expense not found']);
            exit;
        }
        if (!$isAdmin && (int)$e['created_by'] !== $userId) {
            http_response_code(403);
            echo json_encode(['success' => false, 'error' => 'You can only edit your own expenses']);
            exit;
        }
        if ($e['status'] === 'forwarded' || (int)$e['forwarded_to_accounting'] === 1) {
            http_response_code(409);
            echo json_encode(['success' => false, 'error' => 'This expense has been sent to accounting and can no longer be edited']);
            exit;
        }
    };

    if ($_SERVER['REQUEST_METHOD'] === 'GET') {
        $expenseId = (int)($_GET['expense_id'] ?? 0);
        if (!$expenseId) {
            http_response_code(400);
            echo json_encode(['success' => false, 'error' => 'expense_id required']);
            exit;
        }
        $stmt = $db->prepare("SELECT created_by FROM expenses WHERE id = ?");
        $stmt->execute([$expenseId]);
        $owner = $stmt->fetchColumn();
        if ($owner === false || (!$isAdmin && (int)$owner !== $userId)) {
            http_response_code(403);
            echo json_encode(['success' => false, 'error' => 'Not allowed']);
            exit;
        }
        $source = null;
        try {
            $s = $db->prepare("SELECT line_items_source FROM expenses WHERE id = ?");
            $s->execute([$expenseId]);
            $source = $s->fetchColumn() ?: null;
        } catch (Throwable $e) { /* column arrives with migration 1115 */ }

        echo json_encode(['success' => true, 'line_items' => $svc->listForExpense($expenseId), 'line_items_source' => $source]);
        exit;
    }

    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        http_response_code(405);
        echo json_encode(['success' => false, 'error' => 'GET or POST required']);
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
        echo json_encode(['success' => false, 'error' => 'JSON body required']);
        exit;
    }

    $op = (string)($input['op'] ?? '');
    $lineItemId = (int)($input['line_item_id'] ?? 0);

    $expenseIdFor = static function (int $lineItemId) use ($db): int {
        $stmt = $db->prepare("SELECT expense_id FROM expense_line_items WHERE id = ?");
        $stmt->execute([$lineItemId]);
        return (int)$stmt->fetchColumn();
    };

    switch ($op) {
        case 'update':
            $assertCanEdit($expenseIdFor($lineItemId));
            echo json_encode(['success' => true, 'line_item' => $svc->update($lineItemId, $input)]);
            break;

        case 'add':
            $expenseId = (int)($input['expense_id'] ?? 0);
            $assertCanEdit($expenseId);
            echo json_encode(['success' => true, 'line_item' => $svc->add($expenseId, $input)]);
            break;

        case 'delete':
            $assertCanEdit($expenseIdFor($lineItemId));
            $svc->delete($lineItemId);
            echo json_encode(['success' => true]);
            break;

        case 'link':
            $assertCanEdit($expenseIdFor($lineItemId));
            $productId = isset($input['product_id']) && $input['product_id'] !== null && $input['product_id'] !== ''
                ? (int)$input['product_id'] : null;
            echo json_encode(['success' => true, 'line_item' => $svc->link($lineItemId, $productId)]);
            break;

        default:
            http_response_code(400);
            echo json_encode(['success' => false, 'error' => 'Unknown op']);
    }

} catch (Exception $e) {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => 'Line item action failed: ' . $e->getMessage()]);
}

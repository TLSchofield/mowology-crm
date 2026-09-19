<?php
/**
 * Expense Metadata API — JWT-authenticated
 *
 * GET /app/Modules/Expenses/Api/expense-meta.php
 *
 * Returns the accounting categories, payment methods, and GBP categories
 * used across the expense module so mobile apps don't hardcode these lists.
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
    require_once APP_ROOT . '/Modules/Expenses/ExpenseConstants.php';

    requireJwt();

    echo json_encode([
        'success'                => true,
        'accounting_categories'  => EXPENSE_ACCOUNTING_CATEGORIES,
        'payment_methods'        => EXPENSE_PAYMENT_METHODS,
        'gbp_categories'         => EXPENSE_GBP_CATEGORIES,
    ]);

} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
}

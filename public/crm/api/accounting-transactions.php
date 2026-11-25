<?php
declare(strict_types=1);
$__dir = __DIR__;
for ($__i = 0; $__i < 6; $__i++) {
    $__dir = dirname($__dir);
    if (is_file($__dir . '/app/Core/paths.php')) {
        require_once $__dir . '/app/Core/paths.php';
        break;
    }
}
if (($_GET['action'] ?? '') === 'list' && isset($_GET['expense_id'])) {
    $_GET['expense_id'] = is_numeric($_GET['expense_id']) && (int)$_GET['expense_id'] > 0
        ? (int)$_GET['expense_id']
        : '';
}
// Shim — routes to /app/Modules/Accounting/Api/transactions.php
require_once APP_ROOT . '/Modules/Accounting/Api/transactions.php';

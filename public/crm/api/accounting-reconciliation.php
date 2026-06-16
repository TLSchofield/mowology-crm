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
// Shim — routes to /app/Modules/Accounting/Api/reconciliation.php
require_once APP_ROOT . '/Modules/Accounting/Api/reconciliation.php';

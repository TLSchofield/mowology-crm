<?php
// Shim — delegates to app/Modules/Referrals/Api/referrals.php
$__dir = __DIR__;
for ($__i = 0; $__i < 5; $__i++) {
    $__dir = dirname($__dir);
    if (is_file($__dir . '/app/Core/paths.php')) {
        require_once $__dir . '/app/Core/paths.php';
        break;
    }
}
require_once APP_ROOT . '/Modules/Referrals/Api/referrals.php';

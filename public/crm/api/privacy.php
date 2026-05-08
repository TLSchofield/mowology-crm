<?php
// Shim — forwards to app/Modules/Privacy/Api/privacy.php
$__dir = __DIR__;
for ($__i = 0; $__i < 7; $__i++) {
    if (is_file($__dir . '/app/Core/paths.php')) {
        require_once $__dir . '/app/Core/paths.php';
        break;
    }
    $__dir = dirname($__dir);
}
unset($__dir, $__i);
require APP_ROOT . '/Modules/Privacy/Api/privacy.php';

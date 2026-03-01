<?php
/**
 * Icon Sets API shim — public web entry point.
 * Delegates to /app/Modules/CMS/Api/api-icon-sets.php
 */
$__dir = __DIR__;
for ($__i = 0; $__i < 5; $__i++) {
    $__dir = dirname($__dir);
    if (is_file($__dir . '/app/Core/paths.php')) {
        require_once $__dir . '/app/Core/paths.php';
        break;
    }
}
unset($__dir, $__i);

require_once APP_ROOT . '/Modules/CMS/Api/api-icon-sets.php';

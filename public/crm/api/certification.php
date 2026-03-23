<?php
/**
 * Shim: /public/crm/api/certification.php → app/Modules/Quiz/Api/certification.php
 */
declare(strict_types=1);
$__dir = __DIR__;
for ($__i = 0; $__i < 5; $__i++) {
    $__dir = dirname($__dir);
    if (is_file($__dir . '/app/Core/paths.php')) {
        require_once $__dir . '/app/Core/paths.php';
        break;
    }
}
unset($__dir, $__i);
require_once APP_ROOT . '/Modules/Quiz/Api/certification.php';

<?php
/**
 * Legacy shim — forwards to app/Modules/Database/Api/apply-migrations.php
 */

$__dir = __DIR__;
for ($__i = 0; $__i < 5; $__i++) {
    $__dir = dirname($__dir);
    if (is_file($__dir . '/app/Modules/Database/Api/apply-migrations.php')) {
        require_once $__dir . '/app/Modules/Database/Api/apply-migrations.php';
        exit;
    }
}
http_response_code(500);
die('Could not locate app/Modules/Database/Api/apply-migrations.php');

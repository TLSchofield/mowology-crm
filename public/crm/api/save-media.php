<?php
/**
 * LEGACY SHIM — save-media.php
 * Real logic lives at /app/Modules/CMS/Api/save-media.php
 * DO NOT add new code here. Edit the target file instead.
 */
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
require_once APP_ROOT . '/Modules/CMS/Api/save-media.php';

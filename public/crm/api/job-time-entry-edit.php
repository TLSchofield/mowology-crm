<?php
/**
 * SHIM — job-time-entry-edit.php
 * Real logic lives at /app/Modules/Team/Api/job-time-entry-edit.php
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
require_once APP_ROOT . '/Modules/Team/Api/job-time-entry-edit.php';

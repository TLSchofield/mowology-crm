<?php
/**
 * LEGACY SHIM — functions.php
 * Real logic lives at /app/Services/CrmFunctions.php
 * This file exists to preserve backward compatibility during migration.
 * DO NOT add new code here. Edit the target file instead.
 */
if (!defined('APP_ROOT')) {
    // Search upward for app/Core/paths.php (works in both local dev and production)
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
require_once APP_ROOT . '/Services/CrmFunctions.php';

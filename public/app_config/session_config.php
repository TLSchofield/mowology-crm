<?php
declare(strict_types=1);

/**
 * LEGACY SHIM — session_config.php
 * Real logic lives at /app/Core/session_config.php
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
require_once APP_ROOT . '/Core/session_config.php';

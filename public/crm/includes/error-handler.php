<?php
/**
 * LEGACY SHIM — error-handler.php
 * Real logic lives at /app/Core/ErrorHandler.php
 * This file exists to preserve backward compatibility during migration.
 * DO NOT add new code here. Edit the target file instead.
 */
if (!defined('APP_ROOT')) {
    require_once dirname(__DIR__, 3) . '/app/Core/paths.php';
}
require_once APP_ROOT . '/Core/ErrorHandler.php';

<?php
declare(strict_types=1);

/**
 * LEGACY SHIM — session_config.php
 * Real logic lives at /app/Core/session_config.php
 * This file exists to preserve backward compatibility during migration.
 * DO NOT add new code here. Edit the target file instead.
 */
if (!defined('APP_ROOT')) {
    require_once dirname(__DIR__, 2) . '/app/Core/paths.php';
}
require_once APP_ROOT . '/Core/session_config.php';

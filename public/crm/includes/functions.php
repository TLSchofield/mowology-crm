<?php
/**
 * LEGACY SHIM — functions.php
 * Real logic lives at /app/Services/CrmFunctions.php
 * This file exists to preserve backward compatibility during migration.
 * DO NOT add new code here. Edit the target file instead.
 */
if (!defined('APP_ROOT')) {
    require_once dirname(__DIR__, 3) . '/app/Core/paths.php';
}
require_once APP_ROOT . '/Services/CrmFunctions.php';

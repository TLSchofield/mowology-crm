<?php
declare(strict_types=1);

define('ITTAI_DIRECTORY_MODE', true);
if (!defined('APP_ROOT')) {
    for ($directory = __DIR__, $index = 0; $index < 7; $index++, $directory = dirname($directory)) {
        if (is_file($directory . '/app/Core/paths.php')) {
            require_once $directory . '/app/Core/paths.php';
            break;
        }
    }
}

require_once APP_ROOT . '/Core/config.php';
require_once APP_ROOT . '/Modules/Tools/Services/SavedNumbersService.php';
require_once APP_ROOT . '/Modules/Tools/Services/ToolCalculator.php';
require_once APP_ROOT . '/Modules/Tools/Services/ToolUsageCounter.php';
require_once __DIR__ . '/layout.php';

/** Returns whether the current request is a POST action. */
function validPost(): bool
{
    return ($_SERVER['REQUEST_METHOD'] ?? '') === 'POST';
}

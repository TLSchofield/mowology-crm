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
require_once APP_ROOT . '/Modules/Tools/Services/ToolCalculator.php';
require_once APP_ROOT . '/Modules/Tools/Services/ToolUsageCounter.php';

header('Cache-Control: no-store');
if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    http_response_code(405);
    exit;
}

$body = json_decode(file_get_contents('php://input') ?: '{}', true);
if (
    is_array($body)
    && ($body['event'] ?? '') === 'calc'
    && in_array($body['tool'] ?? '', ToolCalculator::tools(), true)
    && ($db = Database::platform())
) {
    try {
        (new ToolUsageCounter($db))->increment((string) $body['tool']);
    } catch (Throwable $exception) {
        error_log($exception->getMessage());
    }
}
http_response_code(204);

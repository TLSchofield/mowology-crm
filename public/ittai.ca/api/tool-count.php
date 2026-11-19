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
require_once APP_ROOT . '/Modules/Tools/Services/ToolThrottle.php';
require_once APP_ROOT . '/Modules/Tools/Services/ToolUsageCounter.php';

header('Cache-Control: no-store');
if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    http_response_code(405);
    exit;
}
if ((int) ($_SERVER['CONTENT_LENGTH'] ?? 0) > 16384) {
    http_response_code(413);
    exit;
}
$rawBody = file_get_contents('php://input', false, null, 0, 16385);
if ($rawBody !== false && strlen($rawBody) > 16384) {
    http_response_code(413);
    exit;
}
$ip = (string) ($_SERVER['REMOTE_ADDR'] ?? 'unknown');
if ((new ToolThrottle())->limited('count-ip:' . $ip, 20, 3600)) {
    http_response_code(204);
    exit;
}

// This count is approximate, untrusted telemetry; it is never business data.
$body = json_decode($rawBody ?: '{}', true);
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

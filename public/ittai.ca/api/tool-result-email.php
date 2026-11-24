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
require_once APP_ROOT . '/Modules/Tools/Services/SavedNumbersService.php';
require_once APP_ROOT . '/Modules/Tools/Services/ToolResultMailer.php';
require_once APP_ROOT . '/Modules/Tools/Services/ToolThrottle.php';
require_once APP_ROOT . '/Services/Messaging/PlatformMailer.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');
header('X-Content-Type-Options: nosniff');

/** Sends a JSON response and stops execution. */
function answer(int $code, array $body): void
{
    http_response_code($code);
    echo json_encode($body);
    exit;
}

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    answer(405, ['error' => 'Method not allowed']);
}
if ((int) ($_SERVER['CONTENT_LENGTH'] ?? 0) > 16384) {
    answer(413, ['error' => 'Request too large']);
}
$rawBody = file_get_contents('php://input', false, null, 0, 16385);
if ($rawBody !== false && strlen($rawBody) > 16384) {
    answer(413, ['error' => 'Request too large']);
}
if (!defined('ITTAI_MAIL_FROM') || (string) ITTAI_MAIL_FROM === '') {
    answer(503, ['error' => 'Email is not available.']);
}
$body = json_decode($rawBody ?: '{}', true);
if (!is_array($body)) {
    answer(400, ['error' => 'Invalid request']);
}
if (trim((string) ($body['hp'] ?? '')) !== '' || time() - ((int) ($body['t'] ?? 0) / 1000) < 3) {
    answer(200, ['message' => 'Sent']);
}
$email = strtolower(trim((string) ($body['email'] ?? '')));
if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
    answer(422, ['error' => 'Enter a valid email.']);
}
$tool = (string) ($body['tool'] ?? '');
if (!in_array($tool, ToolCalculator::tools(), true)) {
    answer(422, ['error' => 'Unknown calculator.']);
}
$ip = (string) ($_SERVER['REMOTE_ADDR'] ?? 'unknown');
$emailHash = hash('sha256', $email);
$throttle = new ToolThrottle();
if ($throttle->limited('ip:' . $ip, 5, 600) || $throttle->limited('email-hour:' . $emailHash, 3, 3600) || $throttle->limited('email-day:' . $emailHash, 10, 86400)) {
    answer(429, ['error' => 'Too many tries. Please wait a few minutes.']);
}
try {
    $inputs = ToolCalculator::allowlist($tool, is_array($body['inputs'] ?? null) ? $body['inputs'] : []);
    $calculator = new ToolCalculator();
    $calculator->calculate($tool, $inputs);
} catch (Throwable $exception) {
    answer(422, ['error' => 'Check the calculator numbers.']);
}
if ($throttle->limited('budget:' . gmdate('Y-m-d'), 300, 86400)) {
    answer(429, ['error' => 'Too many requests today. Please try again tomorrow.']);
}

$saved = null;
$confirmUrl = null;
$deleteUrl = null;
$save = isset($body['save']) && $body['save'] === true;
if ($save && ($db = Database::platform())) {
    try {
        $saved = (new SavedNumbersService($db))->createPending(
            $email,
            $tool,
            $inputs,
            '/tools/' . $tool . '/'
        );
        $confirmUrl = 'https://ittai.ca/tools/confirm/?t=' . rawurlencode($saved['confirm_token']);
        $deleteUrl = 'https://ittai.ca/tools/delete/?t=' . rawurlencode($saved['delete_token']);
    } catch (Throwable $exception) {
        error_log('Save pending failed: ' . $exception->getMessage());
        $saved = null;
    }
}

$mail = (new ToolResultMailer($calculator))->render($tool, $inputs, $confirmUrl, $deleteUrl);
if (!sendPlatformEmail($email, $mail['subject'], $mail['html'], $mail['plain'])) {
    answer(503, ['error' => 'Email could not be sent. Try again.']);
}
$reply = ['message' => 'Sent', 'saved' => $save ? ($saved ? 'pending' : 'unavailable') : 'not_requested'];
if ($save && !$saved) {
    $reply['message'] = "Saving isn't switched on yet; your result was sent.";
}
answer(200, $reply);

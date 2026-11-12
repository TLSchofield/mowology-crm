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
require_once APP_ROOT . '/Services/Messaging/SmtpMailer.php';

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

/** Applies a small file-backed request limit. */
function limited(string $key, int $limit, int $seconds): bool
{
    $directory = sys_get_temp_dir() . '/ittai-tool-throttle';
    if (!is_dir($directory) && !@mkdir($directory, 0700, true) && !is_dir($directory)) {
        return true;
    }
    $file = $directory . '/' . hash('sha256', $key);
    $now = time();
    $times = [];
    $handle = @fopen($file, 'c+');
    if (!$handle) {
        return true;
    }
    flock($handle, LOCK_EX);
    $raw = stream_get_contents($handle);
    foreach (explode("\n", (string) $raw) as $value) {
        if ((int) $value > $now - $seconds) {
            $times[] = (int) $value;
        }
    }
    $hit = count($times) >= $limit;
    if (!$hit) {
        $times[] = $now;
    }
    ftruncate($handle, 0);
    rewind($handle);
    fwrite($handle, implode("\n", $times));
    fflush($handle);
    flock($handle, LOCK_UN);
    fclose($handle);
    return $hit;
}

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    answer(405, ['error' => 'Method not allowed']);
}
if (!defined('ITTAI_MAIL_FROM') || (string) ITTAI_MAIL_FROM === '') {
    answer(503, ['error' => 'Email is not available.']);
}
$body = json_decode(file_get_contents('php://input') ?: '{}', true);
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
if (limited('ip:' . $ip, 5, 600) || limited('email-hour:' . $emailHash, 3, 3600) || limited('email-day:' . $emailHash, 10, 86400)) {
    answer(429, ['error' => 'Too many tries. Please wait a few minutes.']);
}

try {
    $inputs = ToolCalculator::allowlist($tool, is_array($body['inputs'] ?? null) ? $body['inputs'] : []);
    $calculator = new ToolCalculator();
    $calculator->calculate($tool, $inputs);
} catch (Throwable $exception) {
    answer(422, ['error' => 'Check the calculator numbers.']);
}

$saved = null;
$confirmUrl = null;
$deleteUrl = null;
$save = !empty($body['save']);
if ($save && ($db = Database::platform())) {
    try {
        $saved = (new SavedNumbersService($db))->createPending(
            $email,
            $tool,
            $inputs,
            !empty($body['news']),
            !empty($body['benchmarks']),
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
if (!sendCrmEmail($email, $mail['subject'], $mail['html'])) {
    answer(503, ['error' => 'Email could not be sent. Try again.']);
}
$reply = ['message' => 'Sent', 'saved' => $save ? ($saved ? 'pending' : 'unavailable') : 'not_requested'];
if ($save && !$saved) {
    $reply['message'] = "Saving isn't switched on yet; your result was sent.";
}
answer(200, $reply);

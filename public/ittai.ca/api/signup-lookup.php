<?php
declare(strict_types=1);

/** POST {"email":"owner@example.com"}; locate the owner's one unclaimed business. Phones only: no CORS. */

define('ITTAI_DIRECTORY_MODE', true);

if (!defined('APP_ROOT')) {
    $__dir = __DIR__;
    for ($__i = 0; $__i < 6; $__i++) {
        $__dir = dirname($__dir);
        if (is_file($__dir . '/app/Core/paths.php')) {
            require_once $__dir . '/app/Core/paths.php';
            break;
        }
    }
    unset($__dir, $__i);
}
require_once APP_ROOT . '/Core/config.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');
header('X-Content-Type-Options: nosniff');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['error' => 'Method not allowed']);
    exit;
}

$body = json_decode(file_get_contents('php://input') ?: '{}', true);
if (!is_array($body)) $body = [];
$email = isset($body['email']) ? strtolower(trim((string)$body['email'])) : '';
if ($email === '' || filter_var($email, FILTER_VALIDATE_EMAIL) === false) {
    http_response_code(404);
    echo json_encode(['error' => 'not_available']);
    exit;
}

require_once APP_ROOT . '/Core/Auth/auth.php';
require_once APP_ROOT . '/Modules/Auth/Services/SignUpDirectory.php';

$result = (new SignUpDirectory())->lookup($email, $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0');
if ($result['status'] === 'ok') {
    http_response_code(200);
    echo json_encode(['business' => $result['business']]);
} elseif ($result['status'] === 'rate_limited') {
    http_response_code(429);
    echo json_encode(['error' => 'too_many_attempts']);
} else {
    http_response_code(404);
    echo json_encode(['error' => 'not_available']);
}

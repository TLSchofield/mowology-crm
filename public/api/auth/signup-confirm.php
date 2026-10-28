<?php
declare(strict_types=1);

if (!defined('APP_ROOT')) {
    $__dir = __DIR__;
    for ($__i = 0; $__i < 6; $__i++) { $__dir = dirname($__dir); if (is_file($__dir . '/app/Core/paths.php')) { require_once $__dir . '/app/Core/paths.php'; break; } }
    unset($__dir, $__i);
}
require_once APP_ROOT . '/Core/config.php';
require_once APP_ROOT . '/Core/Auth/auth.php';
require_once APP_ROOT . '/Core/Auth/JwtService.php';
require_once APP_ROOT . '/Modules/Onboarding/Services/SignUpService.php';

header('Content-Type: application/json; charset=utf-8');
header('X-Content-Type-Options: nosniff');
if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') { http_response_code(405); echo json_encode(['ok' => false, 'error' => 'method_not_allowed']); exit; }
if (Tenant::isDefault()) { http_response_code(404); echo json_encode(['ok' => false, 'error' => 'not_available']); exit; }
$decoded = json_decode((string)file_get_contents('php://input'), true);
$body = is_array($decoded) ? $decoded : [];
$email = strtolower(trim((string)($body['email'] ?? '')));
$ip = (string)($_SERVER['REMOTE_ADDR'] ?? '0.0.0.0');
$rateKey = 'signup:' . $email;
if (isLoginRateLimited($rateKey, $ip)) { http_response_code(429); echo json_encode(['ok' => false, 'error' => 'rate_limited']); exit; }
$fields = SignUpService::validate($body, true);
if ($fields) { recordFailedLogin($rateKey, $ip); http_response_code(400); echo json_encode(['ok' => false, 'error' => 'validation', 'fields' => $fields]); exit; }
$result = (new SignUpService(getDB(), Tenant::db()[0]))->confirm($body, $ip, static function (array $user): string { return generateMobileJwt($user); });
if (!$result['ok']) {
    if (in_array((int)$result['status'], [400, 409], true)) recordFailedLogin($rateKey, $ip);
    http_response_code((int)$result['status']);
    $out = ['ok' => false, 'error' => $result['error']];
    if (isset($result['fields'])) $out['fields'] = $result['fields'];
    if (isset($result['message'])) $out['message'] = $result['message'];
    echo json_encode($out); exit;
}
clearLoginAttempts($rateKey, $ip);
$user = $result['user'];
$businessName = (string)(getDB()->query('SELECT company_name FROM business_settings ORDER BY id ASC LIMIT 1')->fetchColumn() ?: '');
$host = (string)(parse_url(Tenant::appUrl(), PHP_URL_HOST) ?: '');
http_response_code(201);
echo json_encode(['ok' => true, 'token' => (string)$result['token'],
    'user' => ['id' => (int)$user['id'], 'email' => (string)$user['email'], 'name' => (string)$user['full_name'], 'role' => 'admin'],
    'expires_in' => 2592000, 'business' => ['key' => Tenant::key(), 'host' => $host, 'name' => $businessName],
    'onboarding' => ['next' => $result['next']]]);

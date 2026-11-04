<?php
declare(strict_types=1);

if (!defined('APP_ROOT')) {
    $__dir = __DIR__;
    for ($__i = 0; $__i < 6; $__i++) { $__dir = dirname($__dir); if (is_file($__dir . '/app/Core/paths.php')) { require_once $__dir . '/app/Core/paths.php'; break; } }
    unset($__dir, $__i);
}
require_once APP_ROOT . '/Core/config.php';
require_once APP_ROOT . '/Core/Auth/auth.php';
require_once APP_ROOT . '/Services/Messaging/MessagingService.php';
require_once APP_ROOT . '/Modules/Onboarding/Services/SignUpService.php';
require_once APP_ROOT . '/Modules/Onboarding/Services/SignUpRequestThrottle.php';

header('Content-Type: application/json; charset=utf-8');
header('X-Content-Type-Options: nosniff');
if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') { http_response_code(405); echo json_encode(['ok' => false, 'error' => 'method_not_allowed']); exit; }
if (Tenant::isDefault()) { http_response_code(404); echo json_encode(['ok' => false, 'error' => 'not_available']); exit; }

$decoded = json_decode((string)file_get_contents('php://input'), true);
$body = is_array($decoded) ? $decoded : [];
$email = strtolower(trim((string)($body['email'] ?? '')));
$ip = (string)($_SERVER['REMOTE_ADDR'] ?? '0.0.0.0');
$fields = SignUpService::validate($body);
if ($fields) { recordFailedLogin('signup:' . $email, $ip); http_response_code(400); echo json_encode(['ok' => false, 'error' => 'validation', 'fields' => $fields]); exit; }
if (!SignUpRequestThrottle::reserve($email, $ip, 'isLoginRateLimited', 'recordFailedLogin')) { http_response_code(429); echo json_encode(['ok' => false, 'error' => 'rate_limited']); exit; }

$service = new SignUpService(getDB(), Tenant::db()[0]);
$result = $service->requestCode($body);
if (!$result['ok']) {
    http_response_code((int)$result['status']);
    $out = ['ok' => false, 'error' => $result['error']];
    if (isset($result['fields'])) $out['fields'] = $result['fields'];
    echo json_encode($out); exit;
}

$code = (string)$result['code'];
$local = defined('LOCAL_TEST_SITE') && LOCAL_TEST_SITE === true;
$html = '<p>Your Ittai code is <strong>' . h($code) . '</strong>.</p><p>It expires in 15 minutes.</p>';
$emailSent = false;
try { $emailSent = sendCrmEmail($email, 'Your Ittai code', $html); } catch (Throwable $e) { error_log('[signup] Email failed: ' . get_class($e)); }
if (!$emailSent && !$local) { $service->clearCode(); http_response_code(502); echo json_encode(['ok' => false, 'error' => 'send_failed']); exit; }
$sent = ['email'];
try {
    $sms = sendSms((string)$body['mobile'], 'Your Ittai code is ' . $code . '. It expires in 15 minutes.');
    if (!empty($sms['success'])) $sent[] = 'sms';
    else error_log('[signup] SMS failed');
} catch (Throwable $e) { error_log('[signup] SMS failed: ' . get_class($e)); }
echo json_encode(SignUpService::requestResponse($sent, $code, $local));

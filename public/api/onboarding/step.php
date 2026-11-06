<?php
declare(strict_types=1);

if (!defined('APP_ROOT')) {
    $__dir = __DIR__;
    for ($__i = 0; $__i < 6; $__i++) {
        $__dir = dirname($__dir);
        if (is_file($__dir . '/app/Core/paths.php')) { require_once $__dir . '/app/Core/paths.php'; break; }
    }
    unset($__dir, $__i);
}
require_once APP_ROOT . '/Core/config.php';
require_once APP_ROOT . '/Core/Auth/JwtAuth.php';
require_once APP_ROOT . '/Modules/Onboarding/Services/OnboardingStepService.php';
require_once APP_ROOT . '/Modules/Onboarding/Services/OnboardingApiPresenter.php';

header('Content-Type: application/json; charset=utf-8');
header('X-Content-Type-Options: nosniff');

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    http_response_code(405);
    echo json_encode(['ok' => false, 'error' => 'method_not_allowed']);
    exit;
}
$jwtUser = requireJwt();
if (!jwtIsAdmin((string)$jwtUser['role'])) {
    http_response_code(403);
    echo json_encode(['ok' => false, 'error' => 'forbidden']);
    exit;
}
$decoded = json_decode((string)file_get_contents('php://input'), true);
$body = is_array($decoded) ? $decoded : [];
$body['_site_url'] = Tenant::appUrl();
$body['_ip'] = (string)($_SERVER['REMOTE_ADDR'] ?? '');
$body['_ua'] = (string)($_SERVER['HTTP_USER_AGENT'] ?? '');
$body['knows'] = $body['knows'] ?? '';   // an app that does not say which new steps it knows knows none
$result = (new OnboardingStepService(getDB(), Tenant::db()[0]))->apply((int)$jwtUser['id'], $body);
if (!$result['ok']) {
    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => (string)($result['error'] ?? 'validation'), 'message' => (string)$result['message']]);
    exit;
}
$key = trim((string)($body['key'] ?? ''));
$data = OnboardingApiPresenter::data(getDB(), (int)$jwtUser['id'], Tenant::db()[0], OnboardingService::knowsFrom($body['knows']));
if (isset($result['matches'])) { $data[$key]['matches'] = $result['matches']; $data[$key]['registry'] = $result['registry'] ?? ''; }
if (isset($result['invite'])) $data[$key]['invite'] = $result['invite'];
$out = ['ok' => true, 'next' => $result['next'], 'data' => $data[$key] ?? []];
if (!empty($result['message'])) $out['message'] = $result['message'];
echo json_encode($out);

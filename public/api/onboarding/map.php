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
require_once APP_ROOT . '/Modules/Onboarding/Services/OnboardingService.php';
require_once APP_ROOT . '/Modules/Onboarding/Services/OnboardingApiPresenter.php';

header('Content-Type: application/json; charset=utf-8');
header('X-Content-Type-Options: nosniff');

$method = (string)($_SERVER['REQUEST_METHOD'] ?? 'GET');
if (!in_array($method, ['GET', 'POST'], true)) {
    http_response_code(405);
    echo json_encode(['ok' => false, 'error' => 'method_not_allowed']);
    exit;
}

$jwtUser = requireJwt();
$svc = new OnboardingService(new OnboardingContext(getDB(), (int)$jwtUser['id']));
if ($method === 'POST') {
    if (!jwtIsAdmin((string)$jwtUser['role'])) {
        http_response_code(403);
        echo json_encode(['ok' => false, 'error' => 'forbidden']);
        exit;
    }
    $decoded = json_decode((string)file_get_contents('php://input'), true);
    $body = is_array($decoded) ? $decoded : [];
    $key = trim((string)($body['key'] ?? ''));
    $action = trim((string)($body['do'] ?? ''));
    if ($svc->module($key) === null) {
        http_response_code(400);
        echo json_encode(['ok' => false, 'error' => 'validation', 'message' => 'No such step.']);
        exit;
    }
    if (!in_array($action, ['skip', 'unskip', 'move_up', 'move_down'], true)) {
        http_response_code(400);
        echo json_encode(['ok' => false, 'error' => 'validation', 'message' => 'Choose a valid action.']);
        exit;
    }
    if ($action === 'skip' || $action === 'unskip') $changed = $svc->skip($key, $action === 'skip');
    else $changed = $svc->move($key, $action === 'move_up' ? -1 : 1);
    if (($changed['ok'] ?? false) !== true) {
        http_response_code(400);
        echo json_encode(['ok' => false, 'error' => 'refused', 'message' => (string)($changed['message'] ?? '')]);
        exit;
    }
}

$data = OnboardingApiPresenter::data(getDB(), (int)$jwtUser['id'], Tenant::db()[0]);
echo json_encode(OnboardingApiPresenter::present($svc->map(), $svc->next(), $svc->readyToFinish(), $data));

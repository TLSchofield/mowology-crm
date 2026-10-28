<?php
declare(strict_types=1);

if (!defined('APP_ROOT')) {
    $__dir = __DIR__;
    for ($__i = 0; $__i < 6; $__i++) { $__dir = dirname($__dir); if (is_file($__dir . '/app/Core/paths.php')) { require_once $__dir . '/app/Core/paths.php'; break; } }
    unset($__dir, $__i);
}
require_once APP_ROOT . '/Core/config.php';
require_once APP_ROOT . '/Core/Auth/JwtAuth.php';
require_once APP_ROOT . '/Modules/QuickBooks/Services/QboConnectionService.php';
header('Content-Type: application/json; charset=utf-8');
header('X-Content-Type-Options: nosniff');
if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'GET') { http_response_code(405); echo json_encode(['ok' => false, 'error' => 'method_not_allowed']); exit; }
$user = requireJwt();
if (!jwtIsAdmin((string)$user['role'])) { http_response_code(403); echo json_encode(['ok' => false, 'error' => 'forbidden']); exit; }
$db = getDB();
$service = new QboConnectionService($db);
$missing = $service->missing();
if ($missing) { http_response_code(409); echo json_encode(['ok' => false, 'error' => 'not_set_up', 'message' => 'QuickBooks is not set up: ' . implode('; ', $missing)]); exit; }
$state = [];
$url = $service->startUrl($state);
$saved = $state['qbo_oauth'];
$saved['user_id'] = (int)$user['id'];
QboConnectionService::savePhoneState($db, $saved);
echo json_encode(['ok' => true, 'url' => $url]);

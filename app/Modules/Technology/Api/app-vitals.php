<?php
/**
 * App vitals intake — POST /crm/api/app-vitals.php  (iPhone + Android, same body; see AppVitalsService)
 * Auth: JWT Bearer (the crew apps). Responds {accepted, duplicate, rejected}. Never blocks the app: the apps
 * send it fire-and-forget from an offline-safe queue and retry a batch that did not get a 200.
 */
declare(strict_types=1);
header('Content-Type: application/json; charset=utf-8');
header('X-Content-Type-Options: nosniff');

if (!defined('APP_ROOT')) {
    $__dir = __DIR__;
    for ($__i = 0; $__i < 6; $__i++) {
        $__dir = dirname($__dir);
        if (is_file($__dir . '/app/Core/paths.php')) { require_once $__dir . '/app/Core/paths.php'; break; }
    }
    unset($__dir, $__i);
}

try {
    require_once APP_ROOT . '/Core/config.php';
    require_once APP_ROOT . '/Core/Auth/JwtAuth.php';
    require_once APP_ROOT . '/Modules/Technology/Services/AppVitalsService.php';

    $jwtUser = getJwtUser();
    $userId = (int)($jwtUser['id'] ?? 0);
    if ($userId <= 0) { http_response_code(401); echo json_encode(['error' => 'Unauthorized', 'code' => 'no_auth']); exit; }
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') { http_response_code(405); echo json_encode(['error' => 'POST required']); exit; }

    $raw = (string)file_get_contents('php://input', false, null, 0, 65537);
    if (strlen($raw) > 65536) { http_response_code(413); echo json_encode(['error' => 'Batch over 64 KB']); exit; }
    $input = json_decode($raw, true);
    if (!is_array($input)) { http_response_code(400); echo json_encode(['error' => 'JSON body required']); exit; }

    $result = (new AppVitalsService(getDB()))->ingest(Tenant::key(), $userId, $input, gmdate('Y-m-d H:i:s'));
    echo json_encode($result + ['success' => true]);
} catch (Throwable $e) {
    error_log('app-vitals: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['error' => 'Could not store the batch', 'code' => 'server_error']);
}

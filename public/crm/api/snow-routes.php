<?php
/**
 * Salt & snow routes — admin actions.
 * POST {mode:'assign_crew', csrf_token} → put the winter crew (ops_settings snow_route_crew_user_ids,
 *      lead first) on every active route plan that has no crew yet, and on their future stops.
 * ?mode=, not ?action= (see the /api/ router note in the vault).
 */
declare(strict_types=1);

$__dir = __DIR__;
for ($__i = 0; $__i < 5; $__i++) {
    $__dir = dirname($__dir);
    if (is_file($__dir . '/app/Core/paths.php')) { require_once $__dir . '/app/Core/paths.php'; break; }
}
unset($__dir, $__i);

require_once PUBLIC_ROOT . '/loginAuth/auth.php';
require_once CRM_INCLUDES . '/functions.php';
require_once CRM_INCLUDES . '/plan-functions.php';
require_once APP_ROOT . '/Modules/Contracts/Services/SnowContractService.php';

header('Content-Type: application/json; charset=utf-8');
requireLogin();
if (!isAdmin()) { http_response_code(403); echo json_encode(['ok' => false, 'error' => 'Admin only']); exit; }

$in = json_decode(file_get_contents('php://input'), true) ?: $_POST;
if ($_SERVER['REQUEST_METHOD'] !== 'POST' || !verifyCSRFToken($in['csrf_token'] ?? '')) {
    http_response_code(403);
    echo json_encode(['ok' => false, 'error' => 'POST with a valid CSRF token.']);
    exit;
}
session_write_close();

try {
    $svc = new SnowContractService(getDB());
    if (($in['mode'] ?? '') === 'assign_crew') {
        echo json_encode(['ok' => true, 'crew' => $svc->routeCrew(), 'plans' => $svc->assignCrewToUncrewedRoutes()]);
    } else {
        echo json_encode(['ok' => false, 'error' => 'Unknown mode']);
    }
} catch (Throwable $e) {
    error_log('snow-routes: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => 'Could not update the routes.']);
}

<?php
/**
 * Salt & snow routes — actions.
 * POST {mode:'assign_crew', csrf_token} (admin) → put the winter crew (ops_settings snow_route_crew_user_ids,
 *      lead first) on every active route plan that has no crew yet, and on their future stops.
 * POST {mode:'start_run', kind:'salt'|'snow', csrf_token} (office or winter crew) → open a run: the route
 *      stops appear in every schedule and can be started (SnowRunGate, 2026-11-03).
 * POST {mode:'end_run', csrf_token} → close it: untouched route stops hide again.
 * POST {mode:'run_status', date?, csrf_token} → the state the schedule banner shows.
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
require_once APP_ROOT . '/Modules/Schedule/Services/SnowRunGate.php';

header('Content-Type: application/json; charset=utf-8');
requireLogin();
$user = getCurrentUser();

$in = json_decode(file_get_contents('php://input'), true) ?: $_POST;
if ($_SERVER['REQUEST_METHOD'] !== 'POST' || !verifyCSRFToken((string)($in['csrf_token'] ?? ''))) {
    http_response_code(403);
    echo json_encode(['ok' => false, 'error' => 'POST with a valid CSRF token.']);
    exit;
}
session_write_close();

$mode   = (string)($in['mode'] ?? '');
$userId = (int)($user['id'] ?? 0);
$role   = (string)($user['role'] ?? '');

try {
    $db = getDB();
    if (in_array($mode, ['start_run', 'end_run', 'run_status'], true)) {
        $gate = new SnowRunGate($db);
        $date = (string)($in['date'] ?? date('Y-m-d'));
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) $date = date('Y-m-d');
        if ($mode === 'run_status') {
            echo json_encode(['ok' => true] + $gate->status($date, $role, $userId));
            exit;
        }
        if (!$gate->mayControl($role, $userId)) {
            http_response_code(403);
            echo json_encode(['ok' => false, 'error' => 'Only the office or the winter crew can start or end a run.']);
            exit;
        }
        if (!$gate->ready()) {
            http_response_code(409);
            echo json_encode(['ok' => false, 'error' => 'Salt & snow runs are not set up yet (migration 1432).']);
            exit;
        }
        if ($mode === 'start_run') {
            $res = $gate->start((string)($in['kind'] ?? 'salt'), $userId);
            if (empty($res['success'])) {
                http_response_code(400);
                echo json_encode(['ok' => false, 'error' => (string)($res['error'] ?? 'Could not start the run.')]);
                exit;
            }
            if (function_exists('logActivity')) {
                try { logActivity($userId, null, 'snow_run_started', ucfirst((string)($res['run']['kind'] ?? 'salt')) . ' run started'); } catch (Throwable $e) { /* trail only */ }
            }
            echo json_encode(['ok' => true] + $gate->status($date, $role, $userId));
            exit;
        }
        $res = $gate->end($userId);
        if (!empty($res['ended']) && function_exists('logActivity')) {
            try { logActivity($userId, null, 'snow_run_ended', 'Salt/snow run ended'); } catch (Throwable $e) { /* trail only */ }
        }
        echo json_encode(['ok' => true, 'ended' => !empty($res['ended'])] + $gate->status($date, $role, $userId));
        exit;
    }

    if (!isAdmin()) { http_response_code(403); echo json_encode(['ok' => false, 'error' => 'Admin only']); exit; }
    $svc = new SnowContractService($db);
    if ($mode === 'assign_crew') {
        echo json_encode(['ok' => true, 'crew' => $svc->routeCrew(), 'plans' => $svc->assignCrewToUncrewedRoutes()]);
    } else {
        echo json_encode(['ok' => false, 'error' => 'Unknown mode']);
    }
} catch (Throwable $e) {
    error_log('snow-routes: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => 'Could not update the routes.']);
}

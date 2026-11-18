<?php
declare(strict_types=1);

/**
 * app/Modules/Schedule/Api/snow-run.php
 *
 * Mobile Schedule API — the salt & snow run (SnowRunGate, 2026-11-03).
 * Route stops are listed and startable only while a run is open.
 *
 * GET  /api/schedule/snow-run?date=YYYY-MM-DD     → status (see SnowRunGate::status)
 * POST /api/schedule/snow-run  { "action": "start", "kind": "salt"|"snow" }
 * POST /api/schedule/snow-run  { "action": "end" }
 * Authorization: Bearer <jwt>. Start/end: admin, manager or the winter crew (ops_settings
 * snow_route_crew_user_ids); anyone else gets 403. Same rules as the web /crm/api/snow-routes.php.
 */

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

header('Content-Type: application/json; charset=utf-8');
header('X-Content-Type-Options: nosniff');

require_once APP_ROOT . '/Core/config.php';
require_once APP_ROOT . '/Core/Auth/JwtAuth.php';
require_once APP_ROOT . '/Modules/Schedule/Services/SnowRunGate.php';

$jwtUser = requireJwt();
$userId  = (int)$jwtUser['id'];
$role    = (string)$jwtUser['role'];

try {
    $gate = new SnowRunGate(getDB());

    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        $date = trim((string)($_GET['date'] ?? date('Y-m-d')));
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) $date = date('Y-m-d');
        echo json_encode(['success' => true] + $gate->status($date, $role, $userId));
        exit;
    }

    $body   = json_decode(file_get_contents('php://input'), true) ?? [];
    $action = trim((string)($body['action'] ?? ''));

    if (!$gate->mayControl($role, $userId)) {
        http_response_code(403);
        echo json_encode(['success' => false, 'error' => 'Only the office or the winter crew can start or end a run.']);
        exit;
    }
    if (!$gate->ready()) {
        http_response_code(409);
        echo json_encode(['success' => false, 'error' => 'Salt & snow runs are not set up yet (migration 1432).']);
        exit;
    }

    if ($action === 'start') {
        $res = $gate->start((string)($body['kind'] ?? 'salt'), $userId);
        if (empty($res['success'])) {
            http_response_code(400);
            echo json_encode($res);
            exit;
        }
        if (function_exists('logActivity')) {
            try { logActivity($userId, null, 'snow_run_started', ucfirst((string)($res['run']['kind'] ?? 'salt')) . ' run started'); } catch (Throwable $e) { /* trail only */ }
        }
        echo json_encode($res + $gate->status(date('Y-m-d'), $role, $userId));
        exit;
    }
    if ($action === 'end') {
        $res = $gate->end($userId);
        if (!empty($res['ended']) && function_exists('logActivity')) {
            try { logActivity($userId, null, 'snow_run_ended', 'Salt/snow run ended'); } catch (Throwable $e) { /* trail only */ }
        }
        echo json_encode($res + $gate->status(date('Y-m-d'), $role, $userId));
        exit;
    }

    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'action must be start or end']);
} catch (Throwable $e) {
    error_log('api/schedule/snow-run: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => 'Could not update the run.']);
}

<?php
declare(strict_types=1);

/**
 * app/Modules/Schedule/Api/reschedule-stop.php
 *
 * Mobile Schedule API — Move a Calendar Stop to Another Date
 *
 * POST /api/schedule/reschedule-stop
 * Authorization: Bearer <jwt>
 * Content-Type: application/json
 *
 * Body: { "stop_id": 100, "new_date": "2026-10-05", "force": false }
 *
 * Response 200 (moved):   { "success": true, "stop_id": ..., "old_date": ..., "new_date": ..., "merged": false }
 * Response 200 (warning): { "success": false, "warning": true, "message": "Crew day is over capacity (9.5h) — continue?" }
 *                         — resend with "force": true to move anyway.
 *
 * Admin/manager only — same restriction as crew assignment.
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
require_once APP_ROOT . '/Modules/Jobs/Services/StopRescheduleService.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['error' => 'Method not allowed']);
    exit;
}

$jwtUser = requireJwt();

if (!jwtIsAdmin($jwtUser['role'])) {
    http_response_code(403);
    echo json_encode(['error' => 'Admin only']);
    exit;
}

$body    = json_decode(file_get_contents('php://input'), true) ?? [];
$stopId  = isset($body['stop_id']) ? (int)$body['stop_id'] : 0;
$newDate = isset($body['new_date']) ? (string)$body['new_date'] : '';
$force   = !empty($body['force']);

if ($stopId <= 0) {
    http_response_code(400);
    echo json_encode(['error' => 'stop_id is required']);
    exit;
}

if (!StopRescheduleService::isValidDate($newDate)) {
    http_response_code(400);
    echo json_encode(['error' => 'new_date must be YYYY-MM-DD']);
    exit;
}

try {
    $db          = getDB();
    $rescheduler = new StopRescheduleService($db);
    $stop        = $rescheduler->getStop($stopId);

    if ($newDate === $stop['stop_date']) {
        throw new Exception('This stop is already on that date');
    }
    if (!$rescheduler->isMovable($stopId)) {
        throw new Exception('Only a stop with work still to do can be moved — this one is finished or has a timer running');
    }

    if (!$force) {
        $warning = $rescheduler->capacityWarning($stopId, $newDate);
        if ($warning) {
            echo json_encode(['success' => false, 'warning' => true] + $warning);
            exit;
        }
    }

    $moved = $rescheduler->reschedule($stopId, $newDate, null, null, true);
    echo json_encode(['success' => true] + $moved);
} catch (Exception $e) {
    http_response_code(400);
    echo json_encode(['error' => $e->getMessage()]);
} catch (Throwable $e) {
    error_log('[schedule/reschedule-stop] ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['error' => 'Server error']);
}

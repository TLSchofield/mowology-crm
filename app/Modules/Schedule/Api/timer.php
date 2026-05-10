<?php
declare(strict_types=1);

/**
 * app/Modules/Schedule/Api/timer.php
 *
 * Mobile Job Timer API — JWT authenticated
 *
 * POST /api/schedule/timer
 *   Body: {"action": "start", "visit_id": N}  — start a visit timer
 *   Body: {"action": "stop",  "visit_id": N}  — stop a visit timer
 *
 * Responses match TimerStartResponse / TimerStopResponse in TimeClockModels.swift.
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

$jwtUser = requireJwt();

require_once APP_ROOT . '/Modules/Team/Services/TimeclockFunctions.php';

$userId = (int)$jwtUser['id'];

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['error' => 'Method not allowed']);
    exit;
}

$body    = json_decode(file_get_contents('php://input'), true) ?? [];
$action  = $body['action']   ?? '';
$visitId = isset($body['visit_id']) ? (int)$body['visit_id'] : 0;

if ($visitId <= 0) {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'Invalid visit_id']);
    exit;
}

// ── Start Timer ───────────────────────────────────────────────────────────────

if ($action === 'start') {
    try {
        $entryId = startVisitTimer($visitId, $userId);

        $row = getDB()->prepare(
            "SELECT start_time FROM job_time_entries WHERE id = ? LIMIT 1"
        );
        $row->execute([$entryId]);
        $startTime = ($row->fetch(PDO::FETCH_ASSOC))['start_time'] ?? null;

        echo json_encode([
            'success'       => true,
            'entry_id'      => $entryId,
            'visit_id'      => $visitId,
            'start_time'    => $startTime,
            'auto_clock_in' => false,
            'message'       => null,
        ]);
    } catch (Exception $e) {
        $msg  = $e->getMessage();
        $code = (strpos($msg, 'already running') !== false) ? 409 : 422;
        http_response_code($code);
        echo json_encode(['success' => false, 'message' => $msg]);
    }
    exit;
}

// ── Stop Timer ────────────────────────────────────────────────────────────────

if ($action === 'stop') {
    try {
        $durationMinutes = stopVisitTimer($visitId, $userId);

        echo json_encode([
            'success'          => true,
            'visit_id'         => $visitId,
            'duration_minutes' => $durationMinutes,
            'visit_completed'  => true,
            'message'          => null,
        ]);
    } catch (Exception $e) {
        http_response_code(422);
        echo json_encode(['success' => false, 'message' => $e->getMessage()]);
    }
    exit;
}

http_response_code(400);
echo json_encode(['success' => false, 'message' => 'Invalid action. Use start or stop']);

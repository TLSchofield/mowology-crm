<?php
declare(strict_types=1);

/**
 * app/Modules/Schedule/Api/clock.php
 *
 * POST /api/schedule/clock
 * Authorization: Bearer <jwt>
 * Body (JSON): { action: "clock_in" | "clock_out", lat?: float, lng?: float }
 *
 * Clock in or out for the authenticated crew member.
 *
 * Response 200 (clock_in):
 * {
 *   "success": true,
 *   "clocked_in": true,
 *   "entry_id": 42,
 *   "clock_in": "2026-05-03 08:00:00",
 *   "elapsed_seconds": 0,
 *   "message": "Clocked in successfully."
 * }
 *
 * Response 200 (clock_out):
 * {
 *   "success": true,
 *   "clocked_in": false,
 *   "clock_out": "2026-05-03 16:30:00",
 *   "total_minutes": 510,
 *   "message": "Clocked out successfully."
 * }
 *
 * PRODUCTION SAFETY
 * ─────────────────
 * clockIn() in TimeclockFunctions.php throws if the user is already clocked in.
 * The 409 response below converts that exception into a non-500 response so the
 * iOS app can show a sensible message rather than a generic error.
 *
 * clockOut() throws if the user is not currently clocked in. Same handling.
 *
 * GPS coordinates (lat/lng) are optional. When provided they:
 *   1. Are stored on the time_clock_entries row for audit
 *   2. Create a crew_location_history row if location_tracking_enabled = 1
 * When absent, the clock event is recorded without GPS data (no error).
 *
 * Only POST is accepted. GET requests will return 405.
 */

// ── Bootstrap ─────────────────────────────────────────────────────────────────
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
require_once APP_ROOT . '/Modules/Team/Services/TimeclockFunctions.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'error' => 'POST required']);
    exit;
}

$jwtUser = requireJwt();
$userId  = (int)$jwtUser['id'];

$input  = (array)(json_decode(file_get_contents('php://input'), true) ?? []);
$action = $input['action'] ?? '';
$lat    = isset($input['lat']) ? (float)$input['lat'] : null;
$lng    = isset($input['lng']) ? (float)$input['lng'] : null;

if (!in_array($action, ['clock_in', 'clock_out'], true)) {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'action must be clock_in or clock_out']);
    exit;
}

try {
    if ($action === 'clock_in') {
        try {
            $entryId = clockIn($userId, $lat, $lng);
        } catch (Exception $e) {
            // Already clocked in — return 409 so iOS can show a sensible message
            http_response_code(409);
            echo json_encode(['success' => false, 'error' => $e->getMessage()]);
            exit;
        }

        http_response_code(200);
        echo json_encode([
            'success'         => true,
            'clocked_in'      => true,
            'entry_id'        => $entryId,
            'clock_in'        => date('Y-m-d H:i:s'),
            'elapsed_seconds' => 0,
            'message'         => 'Clocked in successfully.',
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

    } else {
        // clock_out
        try {
            $totalMinutes = clockOut($userId, $lat, $lng);
        } catch (Exception $e) {
            // Not clocked in — return 409
            http_response_code(409);
            echo json_encode(['success' => false, 'error' => $e->getMessage()]);
            exit;
        }

        http_response_code(200);
        echo json_encode([
            'success'       => true,
            'clocked_in'    => false,
            'clock_out'     => date('Y-m-d H:i:s'),
            'total_minutes' => $totalMinutes,
            'message'       => 'Clocked out successfully.',
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }

} catch (Throwable $e) {
    error_log('[schedule/clock] ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => 'Failed to process clock action.']);
}

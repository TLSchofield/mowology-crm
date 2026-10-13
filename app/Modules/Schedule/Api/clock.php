<?php
declare(strict_types=1);

/**
 * app/Modules/Schedule/Api/clock.php
 *
 * Mobile Time Clock API — Clock In / Clock Out / Status
 * Authorization: Bearer <jwt>
 *
 * GET  ?mode=status
 * GET  ?mode=week&start=YYYY-MM-DD   — caller's own timesheet for that week
 * POST {action: 'clock_in',  lat?, lng?}
 * POST {action: 'clock_out', lat?, lng?, notes?}
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

try {
    require_once APP_ROOT . '/Core/config.php';
    require_once APP_ROOT . '/Core/ApiResponse.php'; // generic errors to the user, details to the log
    require_once APP_ROOT . '/Core/Auth/JwtAuth.php';
    require_once CRM_INCLUDES . '/functions.php';
    require_once CRM_INCLUDES . '/timeclock-functions.php';
    require_once APP_ROOT . '/Core/IdempotencyHelper.php';
    require_once APP_ROOT . '/Modules/Team/Services/OfflineTime.php';

    $jwtUser = requireJwt();
    $userId  = (int)$jwtUser['id'];

    if ($_SERVER['REQUEST_METHOD'] === 'GET') {
        // Prefer ?mode= — under /api/ the .htaccess QSA rewrite owns the `action`
        // query param (it carries the route), so a caller's ?action= collides with it.
        $action = trim($_GET['mode'] ?? $_GET['action'] ?? '');
    } else {
        $input  = json_decode(file_get_contents('php://input'), true) ?? [];
        $action = trim($input['action'] ?? '');
    }

    $db = getDB();

    // A queued punch resent twice (two resends racing, a retry after a lost response) must
    // record once: the phone sends the same Idempotency-Key every time (ClockQueue item id).
    $idempKey = trim($_SERVER['HTTP_IDEMPOTENCY_KEY'] ?? '');
    if ($idempKey !== '' && in_array($action, ['clock_in', 'clock_out'], true)) {
        $cached = idempotencyCheck($db, $idempKey, $userId);
        if ($cached !== null) { echo $cached; exit; }
    }
    // A punch the phone queued offline carries client_time. It may have no location (no fix in
    // a dead zone) — it is still recorded, noted, rather than refused and lost.
    // `queued` is sent only on a replay from the phone's queue (a live tap sends client_time too).
    $queued = !empty($input['queued']);
    $clockReply = function (array $payload) use ($db, $idempKey, $userId, $action): void {
        $json = json_encode($payload);
        if ($idempKey !== '') idempotencyStore($db, $idempKey, $userId, 'clock', $action, $json);
        echo $json;
    };

    switch ($action) {

        case 'week':
            // The caller's own timesheet for the week containing ?start= (default: this week).
            require_once APP_ROOT . '/Modules/Team/Services/TimesheetService.php';
            echo json_encode(
                ['success' => true] + TimesheetService::forUser($userId, (string)($_GET['start'] ?? ''))
            );
            break;

        case 'status':
            // Clear any stale entry from a prior day before reporting status.
            resolveStaleClockEntry($userId);

            $entry     = getActiveClockEntry($userId);
            $activeJob = getActiveVisitTimer($userId);

            if ($entry) {
                echo json_encode([
                    'success'         => true,
                    'clocked_in'      => true,
                    'entry_id'        => (int)$entry['id'],
                    'clock_in'        => $entry['clock_in'],
                    'elapsed_seconds' => max(0, (int)($entry['elapsed_seconds'] ?? 0)),
                    'active_job'      => $activeJob ? [
                        'id'               => (int)$activeJob['id'],
                        'visit_id'         => (int)$activeJob['visit_id'],
                        'job_title'        => $activeJob['job_title']        ?? null,
                        'property_address' => $activeJob['property_address'] ?? null,
                        'start_time'       => $activeJob['start_time'],
                        'elapsed_seconds'  => max(0, time() - strtotime($activeJob['start_time'])),
                    ] : null,
                ]);
            } else {
                echo json_encode([
                    'success'    => true,
                    'clocked_in' => false,
                    'active_job' => null,
                ]);
            }
            break;

        case 'clock_in':
            // Clear stale prior-day entries first.
            resolveStaleClockEntry($userId);

            // Check if already clocked in.
            $existing = getActiveClockEntry($userId);
            if ($existing) {
                echo json_encode([
                    'success'         => true,
                    'clocked_in'      => true,
                    'entry_id'        => (int)$existing['id'],
                    'clock_in'        => $existing['clock_in'],
                    'elapsed_seconds' => max(0, (int)($existing['elapsed_seconds'] ?? 0)),
                    'message'         => 'Already clocked in',
                ]);
                break;
            }

            $lat = isset($input['lat']) ? (float)$input['lat'] : null;
            $lng = isset($input['lng']) ? (float)$input['lng'] : null;

            // Delegates to the shared Team clock-in path (TimeclockFunctions::clockIn)
            // instead of reimplementing the INSERT — keeps the GPS-ping-to-crew-map
            // side effect in sync with the desktop path too.
            // Queued offline? Record when it was tapped, not when it arrived (OfflineTime).
            $when = OfflineTime::accept($input['client_time'] ?? null, date('Y-m-d H:i:s'));
            if ($when['reject']) {
                if (function_exists('logActivity')) logActivity($userId, null, 'clock_in_not_recorded', $when['note']);
                $clockReply(['success' => true, 'recorded' => false, 'clocked_in' => false,
                             'time_flag' => $when['flag'], 'message' => $when['note']]);
                break;
            }
            $entryId = clockIn($userId, $lat, $lng, !$queued, $when['from_phone'] ? $when['at'] : null);
            $note = trim(($when['note'] ?? '') . ($queued && ($lat === null || $lng === null) ? ' No location (offline).' : ''));
            if ($note !== '') {
                getDB()->prepare("UPDATE time_clock_entries SET notes = TRIM(CONCAT(COALESCE(notes, ''), ' ', ?)) WHERE id = ?")
                       ->execute([$note, $entryId]);
            }

            $clockReply([
                'success'    => true,
                'clocked_in' => true,
                'entry_id'   => $entryId,
                'clock_in'   => $when['at'],
                'time_flag'  => $when['flag'],
                'message'    => 'Clocked in',
            ]);
            break;

        case 'clock_out':
            $entry = getActiveClockEntry($userId);
            if (!$entry) {
                echo json_encode([
                    'success'    => true,
                    'clocked_in' => false,
                    'message'    => 'Not clocked in',
                ]);
                break;
            }

            $lat   = isset($input['lat'])   ? (float)$input['lat']  : null;
            $lng   = isset($input['lng'])   ? (float)$input['lng']  : null;
            $notes = $input['notes'] ?? null;

            // Delegates to the shared Team clock-out path (TimeclockFunctions::clockOut)
            // instead of reimplementing the UPDATE. This was previously duplicated inline
            // and skipped ensureTimesheetExists() — a mobile-only clock-out never created
            // or updated the weekly timesheet row, so the shift could never surface for
            // approval or payroll. Calling the shared function fixes that.
            $when = OfflineTime::accept($input['client_time'] ?? null, date('Y-m-d H:i:s'), (string)$entry['clock_in']);
            if ($when['reject']) {
                if (function_exists('logActivity')) logActivity($userId, null, 'clock_out_not_recorded', $when['note']);
                $clockReply(['success' => true, 'recorded' => false, 'clocked_in' => true,
                             'time_flag' => $when['flag'], 'message' => $when['note']]);
                break;
            }
            if ($when['note']) $notes = trim(((string)$notes) . ' ' . $when['note']);
            $total = clockOut($userId, $lat, $lng, $notes, $when['from_phone'] ? $when['at'] : null);

            $clockReply([
                'success'         => true,
                'clocked_in'      => false,
                'entry_id'        => (int)$entry['id'],
                'clock_out'       => $when['at'],
                'time_flag'       => $when['flag'],
                'total_minutes'   => $total,
                'message'         => 'Clocked out',
            ]);
            break;

        default:
            http_response_code(400);
            echo json_encode(['success' => false, 'message' => "Unknown action: {$action}"]);
    }

} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Server error: ' . ApiResponse::message($e, 'clock')]);
}

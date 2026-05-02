<?php
declare(strict_types=1);

/**
 * app/Modules/Schedule/Api/clock.php
 *
 * Mobile Time Clock API — Clock In / Clock Out / Status
 * Authorization: Bearer <jwt>
 *
 * GET  ?action=status
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
    require_once APP_ROOT . '/Core/Auth/JwtAuth.php';
    require_once CRM_INCLUDES . '/functions.php';
    require_once CRM_INCLUDES . '/timeclock-functions.php';

    $jwtUser = requireJwt();
    $userId  = (int)$jwtUser['id'];

    if ($_SERVER['REQUEST_METHOD'] === 'GET') {
        $action = trim($_GET['action'] ?? '');
    } else {
        $input  = json_decode(file_get_contents('php://input'), true) ?? [];
        $action = trim($input['action'] ?? '');
    }

    $db = getDB();

    switch ($action) {

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

            $stmt = $db->prepare(
                "INSERT INTO time_clock_entries
                 (user_id, clock_in, clock_in_lat, clock_in_lng, status, created_at)
                 VALUES (?, NOW(), ?, ?, 'active', NOW())"
            );
            $stmt->execute([$userId, $lat, $lng]);
            $entryId = (int)$db->lastInsertId();

            echo json_encode([
                'success'    => true,
                'clocked_in' => true,
                'entry_id'   => $entryId,
                'clock_in'   => date('Y-m-d H:i:s'),
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

            $clockIn = strtotime($entry['clock_in']);
            $total   = max(0, (int)round((time() - $clockIn) / 60));

            $db->prepare(
                "UPDATE time_clock_entries
                 SET clock_out = NOW(), clock_out_lat = ?, clock_out_lng = ?,
                     total_minutes = ?, notes = ?, status = 'completed'
                 WHERE id = ?"
            )->execute([$lat, $lng, $total, $notes, (int)$entry['id']]);

            echo json_encode([
                'success'         => true,
                'clocked_in'      => false,
                'entry_id'        => (int)$entry['id'],
                'clock_out'       => date('Y-m-d H:i:s'),
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
    echo json_encode(['success' => false, 'message' => 'Server error: ' . $e->getMessage()]);
}

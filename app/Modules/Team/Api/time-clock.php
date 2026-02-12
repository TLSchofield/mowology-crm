<?php
/**
 * Time Clock API — Clock In / Clock Out / Status
 * POST: {action: 'clock_in', lat?, lng?}
 * POST: {action: 'clock_out', lat?, lng?, notes?}
 * GET:  ?action=status
 */
declare(strict_types=1);
header('Content-Type: application/json');

if (!defined('APP_ROOT')) {
    $__dir = __DIR__;
    for ($__i = 0; $__i < 5; $__i++) {
        $__dir = dirname($__dir);
        if (is_file($__dir . '/app/Core/paths.php')) {
            require_once $__dir . '/app/Core/paths.php';
            break;
        }
    }
    unset($__dir, $__i);
}

try {
    require_once PUBLIC_ROOT . '/loginAuth/auth.php';
    require_once CRM_INCLUDES . '/functions.php';
    require_once CRM_INCLUDES . '/timeclock-functions.php';

    requireLogin();
    $user = getCurrentUser();

    // Check if time clock is enabled for this user's role
    if (!isTimeClockEnabledForRole($user['role'])) {
        http_response_code(403);
        echo json_encode(['error' => 'Time clock not enabled for your role']);
        exit;
    }

    // Determine action from GET or POST
    if ($_SERVER['REQUEST_METHOD'] === 'GET') {
        $action = $_GET['action'] ?? '';
    } else {
        $input = json_decode(file_get_contents('php://input'), true);
        $action = $input['action'] ?? '';
    }

    $db = getDB();

    // Get tracking flag from database (not in session)
    $trackStmt = $db->prepare("SELECT location_tracking_enabled FROM users WHERE id = ?");
    $trackStmt->execute([$user['id']]);
    $trackRow = $trackStmt->fetch(PDO::FETCH_ASSOC);
    $locationTrackingEnabled = $trackRow ? (bool)$trackRow['location_tracking_enabled'] : false;

    switch ($action) {
        case 'status':
            $entry = getActiveClockEntry($user['id']);
            $activeJob = getActiveVisitTimer($user['id']);

            if ($entry) {
                echo json_encode([
                    'success' => true,
                    'clocked_in' => true,
                    'entry_id' => (int)$entry['id'],
                    'clock_in' => $entry['clock_in'],
                    'elapsed_seconds' => max(0, (int)$entry['elapsed_seconds']),
                    'location_tracking_enabled' => $locationTrackingEnabled,
                    'active_job' => $activeJob ? [
                        'id' => (int)$activeJob['id'],
                        'visit_id' => (int)$activeJob['visit_id'],
                        'job_title' => $activeJob['job_title'],
                        'job_number' => $activeJob['job_number'],
                        'start_time' => $activeJob['start_time'],
                        'elapsed_seconds' => max(0, (int)$activeJob['elapsed_seconds']),
                    ] : null,
                ]);
            } else {
                echo json_encode([
                    'success' => true,
                    'clocked_in' => false,
                    'location_tracking_enabled' => $locationTrackingEnabled,
                    'active_job' => null,
                ]);
            }
            break;

        case 'clock_in':
            $lat = isset($input['lat']) ? (float)$input['lat'] : null;
            $lng = isset($input['lng']) ? (float)$input['lng'] : null;

            $entryId = clockIn($user['id'], $lat, $lng);

            echo json_encode([
                'success' => true,
                'message' => 'Clocked in successfully',
                'entry_id' => $entryId,
                'clock_in' => date('Y-m-d H:i:s'),
            ]);
            break;

        case 'clock_out':
            $lat = isset($input['lat']) ? (float)$input['lat'] : null;
            $lng = isset($input['lng']) ? (float)$input['lng'] : null;
            $notes = $input['notes'] ?? null;

            // Stop any active visit timers first
            $activeJob = getActiveVisitTimer($user['id']);
            if ($activeJob) {
                stopVisitTimer((int)$activeJob['visit_id'], $user['id'], $lat, $lng, 'Auto-stopped on clock out');
            }

            $totalMinutes = clockOut($user['id'], $lat, $lng, $notes);

            echo json_encode([
                'success' => true,
                'message' => 'Clocked out successfully',
                'total_minutes' => $totalMinutes,
                'total_formatted' => formatMinutesAsHours($totalMinutes),
            ]);
            break;

        default:
            http_response_code(400);
            echo json_encode(['error' => 'Invalid action. Use: status, clock_in, clock_out']);
    }

} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['error' => $e->getMessage()]);
}

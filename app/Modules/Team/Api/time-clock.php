<?php
/**
 * Time Clock API — Clock In / Clock Out / Status / Toggle Tracking
 * POST: {action: 'clock_in', lat?, lng?, user_id?}
 * POST: {action: 'clock_out', lat?, lng?, notes?, user_id?}
 * POST: {action: 'toggle_tracking'} — toggle own location tracking on/off
 * GET:  ?action=status
 *
 * Admin/Manager can pass user_id to clock in/out another user.
 * If user_id is omitted, defaults to the logged-in user (existing behavior).
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
    requirePermission('timer.start');

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

    // Admin/Manager override: allow clocking in/out a different user
    $targetUserId = $user['id'];
    if (!empty($input['user_id']) && (int)$input['user_id'] !== $user['id']) {
        if (!in_array($user['role'], ['admin', 'manager'])) {
            http_response_code(403);
            echo json_encode(['error' => 'Only admin/manager can clock in other users']);
            exit;
        }
        $targetUserId = (int)$input['user_id'];

        // Verify target user exists and is active
        $targetStmt = $db->prepare("SELECT id, full_name, role, is_active FROM users WHERE id = ? LIMIT 1");
        $targetStmt->execute([$targetUserId]);
        $targetUser = $targetStmt->fetch(PDO::FETCH_ASSOC);
        if (!$targetUser || !$targetUser['is_active']) {
            http_response_code(404);
            echo json_encode(['error' => 'User not found or inactive']);
            exit;
        }
        if (!isTimeClockEnabledForRole($targetUser['role'])) {
            http_response_code(403);
            echo json_encode(['error' => 'Time clock not enabled for that user\'s role']);
            exit;
        }
    }

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

            $entryId = clockIn($targetUserId, $lat, $lng);

            // Log if admin clocked in someone else
            if ($targetUserId !== $user['id']) {
                logActivity($user['id'], null, 'Admin clock-in for user #' . $targetUserId, 'Clocked in by ' . ($user['name'] ?? 'admin'));
            }

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

            // If admin is clocking out someone else, add note
            if ($targetUserId !== $user['id']) {
                $adminNote = 'Clocked out by ' . ($user['name'] ?? 'admin');
                $notes = $notes ? $notes . ' — ' . $adminNote : $adminNote;
            }

            // Stop any active visit timers first
            $activeJob = getActiveVisitTimer($targetUserId);
            if ($activeJob) {
                stopVisitTimer((int)$activeJob['visit_id'], $targetUserId, $lat, $lng, 'Auto-stopped on clock out');
            }

            $totalMinutes = clockOut($targetUserId, $lat, $lng, $notes);

            // Log if admin clocked out someone else
            if ($targetUserId !== $user['id']) {
                logActivity($user['id'], null, 'Admin clock-out for user #' . $targetUserId, formatMinutesAsHours($totalMinutes) . ' total');
            }

            echo json_encode([
                'success' => true,
                'message' => 'Clocked out successfully',
                'total_minutes' => $totalMinutes,
                'total_formatted' => formatMinutesAsHours($totalMinutes),
            ]);
            break;

        case 'toggle_tracking':
            // User toggles their own location tracking on/off
            $newValue = $locationTrackingEnabled ? 0 : 1;
            $toggleStmt = $db->prepare("UPDATE users SET location_tracking_enabled = ? WHERE id = ?");
            $toggleStmt->execute([$newValue, $user['id']]);

            echo json_encode([
                'success' => true,
                'location_tracking_enabled' => (bool)$newValue,
                'message' => $newValue ? 'Location tracking enabled' : 'Location tracking disabled',
            ]);
            break;

        default:
            http_response_code(400);
            echo json_encode(['error' => 'Invalid action. Use: status, clock_in, clock_out, toggle_tracking']);
    }

} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['error' => $e->getMessage()]);
}

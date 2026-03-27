<?php
/**
 * Time Clock API — Clock In / Clock Out / Status
 * POST: {action: 'clock_in', lat?, lng?, user_id?}
 * POST: {action: 'clock_out', lat?, lng?, notes?, user_id?}
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

    // Release the PHP session lock immediately — this API only reads session data
    // (user identity) and does not write to $_SESSION. Holding the lock blocks
    // concurrent page navigations on Android WebView, causing ERR_FAILED (~5s timeout).
    session_write_close();

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

    // Get tracking flag, device type, and per-user ping rate from database
    $trackStmt = $db->prepare("SELECT location_tracking_enabled, IFNULL(device_type, 'personal') AS device_type, IFNULL(location_ping_rate, 'high') AS location_ping_rate FROM users WHERE id = ?");
    $trackStmt->execute([$user['id']]);
    $trackRow = $trackStmt->fetch(PDO::FETCH_ASSOC);
    $locationTrackingEnabled = $trackRow ? (bool)$trackRow['location_tracking_enabled'] : false;
    $deviceType = $trackRow ? $trackRow['device_type'] : 'personal';

    // Per-user ping rate overrides global setting
    $pingRateMap = ['low' => 600000, 'medium' => 120000, 'high' => 30000];
    $userPingRate = $trackRow ? ($trackRow['location_ping_rate'] ?? 'high') : 'high';
    $gpsIntervalStandard = $pingRateMap[$userPingRate] ?? 30000;

    // Heightened interval from global settings (used during high-risk jobs)
    $gpsIntervalHeightened = (int)getTimeClockSetting('gps_interval_heightened_ms', '10000');

    switch ($action) {
        case 'status':
            $entry = getActiveClockEntry($user['id']);
            $activeJob = getActiveVisitTimer($user['id']);

            $statusBase = [
                'success' => true,
                'location_tracking_enabled' => $locationTrackingEnabled,
                'device_type' => $deviceType,
                'gps_interval_standard_ms' => $gpsIntervalStandard,
                'gps_interval_heightened_ms' => $gpsIntervalHeightened,
                'auto_arrival_enabled' => getTimeClockSetting('auto_arrival_enabled', '1') === '1',
            ];

            if ($entry) {
                echo json_encode(array_merge($statusBase, [
                    'clocked_in' => true,
                    'entry_id' => (int)$entry['id'],
                    'clock_in' => $entry['clock_in'],
                    'elapsed_seconds' => max(0, (int)$entry['elapsed_seconds']),
                    'active_job' => $activeJob ? [
                        'id' => (int)$activeJob['id'],
                        'visit_id' => (int)$activeJob['visit_id'],
                        'job_title' => $activeJob['job_title'],
                        'job_number' => $activeJob['job_number'],
                        'start_time' => $activeJob['start_time'],
                        'elapsed_seconds' => max(0, (int)$activeJob['elapsed_seconds']),
                    ] : null,
                ]));
            } else {
                echo json_encode(array_merge($statusBase, [
                    'clocked_in' => false,
                    'active_job' => null,
                ]));
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

            // Truck cascade — if this driver has an assigned truck device, clock it out too
            $truckStmt = $db->prepare("SELECT assigned_truck_user_id FROM users WHERE id = ? LIMIT 1");
            $truckStmt->execute([$targetUserId]);
            $truckRow = $truckStmt->fetch(PDO::FETCH_ASSOC);
            $truckCascaded = false;
            if ($truckRow && !empty($truckRow['assigned_truck_user_id'])) {
                $truckUserId = (int)$truckRow['assigned_truck_user_id'];
                try {
                    $truckActive = getActiveClockEntry($truckUserId);
                    if ($truckActive) {
                        // Stop any active visit timer on the truck too
                        $truckJob = getActiveVisitTimer($truckUserId);
                        if ($truckJob) {
                            stopVisitTimer((int)$truckJob['visit_id'], $truckUserId, $lat, $lng, 'Auto-stopped on driver clock out');
                        }
                        clockOut($truckUserId, $lat, $lng, 'Auto-clocked out — driver clocked out');
                        $truckCascaded = true;
                    }
                } catch (Exception $e) {
                    // Truck not clocked in or already clocked out — not an error
                }
            }

            echo json_encode([
                'success' => true,
                'message' => 'Clocked out successfully',
                'total_minutes' => $totalMinutes,
                'total_formatted' => formatMinutesAsHours($totalMinutes),
                'truck_cascaded' => $truckCascaded,
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

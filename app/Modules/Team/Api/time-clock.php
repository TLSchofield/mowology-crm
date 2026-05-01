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

    // Get tracking flag and device type from database
    $trackStmt = $db->prepare("SELECT location_tracking_enabled, IFNULL(device_type, 'personal') AS device_type FROM users WHERE id = ?");
    $trackStmt->execute([$user['id']]);
    $trackRow = $trackStmt->fetch(PDO::FETCH_ASSOC);
    $locationTrackingEnabled = $trackRow ? (bool)$trackRow['location_tracking_enabled'] : false;
    $deviceType = $trackRow ? $trackRow['device_type'] : 'personal';

    // Per-user ping rate — column added in migration 1016; fall back to 'high' if missing.
    $pingRateMap = ['low' => 600000, 'medium' => 120000, 'high' => 30000];
    $userPingRate = 'high';
    try {
        $rateStmt = $db->prepare("SELECT location_ping_rate FROM users WHERE id = ?");
        $rateStmt->execute([$user['id']]);
        $rateRow = $rateStmt->fetch(PDO::FETCH_ASSOC);
        $userPingRate = $rateRow['location_ping_rate'] ?? 'high';
    } catch (PDOException $e) {
        // Column does not exist yet (migration 1016 not run) — use default 'high' (30 s)
    }
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
                'is_driver' => !empty($user['is_driver']),
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

            // Clear any stale entry from a prior day before attempting clock-in.
            resolveStaleClockEntry($targetUserId);

            // Idempotent: if already clocked in today, return the existing entry
            // rather than throwing. This handles the case where the Capacitor bridge
            // threw after a successful clock-in, leaving the driver in a stuck state
            // where every retry gets "already clocked in" and the pre-trip never opens.
            $existingEntry = getActiveClockEntry($targetUserId);
            if ($existingEntry) {
                $entryId = (int)$existingEntry['id'];
                $alreadyClockedIn = true;
            } else {
                $entryId = clockIn($targetUserId, $lat, $lng);
                $alreadyClockedIn = false;
            }

            // Log if admin clocked in someone else (skip if idempotent re-request)
            if (!$alreadyClockedIn && $targetUserId !== $user['id']) {
                logActivity($user['id'], null, 'Admin clock-in for user #' . $targetUserId, 'Clocked in by ' . ($user['name'] ?? 'admin'));
            }

            // Trip-report gate: drivers must start a fresh pre-trip for
            // every clock-in cycle (multi-trip-per-day support). We
            // require pre-trip when there's no "open" trip row for
            // today — meaning either no rows at all, or the latest row
            // is already closed (previous shift's post-trip filed).
            $preTripRequired = false;
            try {
                $drvStmt = $db->prepare("SELECT COALESCE(is_driver, 0) AS is_driver FROM users WHERE id = ? LIMIT 1");
                $drvStmt->execute([$targetUserId]);
                $isDriver = (int)($drvStmt->fetchColumn() ?: 0);
                if ($isDriver) {
                    $tripStmt = $db->prepare("
                        SELECT COUNT(*) FROM vehicle_trip_reports
                        WHERE driver_id = ?
                          AND report_date = ?
                          AND pre_trip_at IS NOT NULL
                          AND post_trip_at IS NULL
                    ");
                    $tripStmt->execute([$targetUserId, date('Y-m-d')]);
                    $openTripCount = (int)$tripStmt->fetchColumn();
                    // No open trip → this is a new shift, pre-trip needed.
                    if ($openTripCount === 0) {
                        $preTripRequired = true;
                    }
                }
            } catch (Throwable $e) {
                error_log('[time-clock] pre-trip gate check failed: ' . $e->getMessage());
            }

            echo json_encode([
                'success'            => true,
                'message'            => $alreadyClockedIn ? 'Already clocked in' : 'Clocked in successfully',
                'entry_id'           => $entryId,
                'clock_in'           => date('Y-m-d H:i:s'),
                'pre_trip_required'  => $preTripRequired,
                'already_clocked_in' => $alreadyClockedIn,
            ]);
            break;

        case 'clock_out':
            $lat = isset($input['lat']) ? (float)$input['lat'] : null;
            $lng = isset($input['lng']) ? (float)$input['lng'] : null;
            $notes = $input['notes'] ?? null;
            $override = !empty($input['override_trip_report']);

            // Trip-report gate: block clock-out if this driver has an
            // OPEN trip for today (pre_trip_at set, post_trip_at null).
            // Admin can force through with override_trip_report=1.
            // Multi-trip-aware: each shift's trip must be closed before
            // the matching clock-out.
            if (!$override) {
                try {
                    $drvStmt = $db->prepare("SELECT COALESCE(is_driver, 0) AS is_driver FROM users WHERE id = ? LIMIT 1");
                    $drvStmt->execute([$targetUserId]);
                    $isDriver = (int)($drvStmt->fetchColumn() ?: 0);
                    if ($isDriver) {
                        $tripStmt = $db->prepare("
                            SELECT id
                            FROM vehicle_trip_reports
                            WHERE driver_id = ?
                              AND report_date = ?
                              AND pre_trip_at IS NOT NULL
                              AND post_trip_at IS NULL
                            ORDER BY id DESC
                            LIMIT 1
                        ");
                        $tripStmt->execute([$targetUserId, date('Y-m-d')]);
                        $tripRow = $tripStmt->fetch(PDO::FETCH_ASSOC);
                        if ($tripRow) {
                            http_response_code(409);
                            echo json_encode([
                                'success'             => false,
                                'error'               => 'Complete your post-trip vehicle check before clocking out.',
                                'error_code'          => 'post_trip_required',
                                'post_trip_required'  => true,
                                'trip_report_id'      => (int)$tripRow['id'],
                                'trip_sequence'       => 1,
                            ]);
                            exit;
                        }
                    }
                } catch (Throwable $e) {
                    error_log('[time-clock] post-trip gate check failed: ' . $e->getMessage());
                    // Fail open — never trap a driver because of a DB error.
                }
            }

            // If admin is clocking out someone else, add note
            if ($targetUserId !== $user['id']) {
                $adminNote = 'Clocked out by ' . ($user['name'] ?? 'admin');
                $notes = $notes ? $notes . ' — ' . $adminNote : $adminNote;
            }

            // Stop any active visit timers first. Wrapped in try/catch so a stale/orphaned
            // job_time_entry row (e.g. visit deleted, FK mismatch) cannot block clock-out.
            // The clock_out itself must always run — a stuck user is worse than a stale timer.
            $activeJob = getActiveVisitTimer($targetUserId);
            if ($activeJob) {
                try {
                    stopVisitTimer((int)$activeJob['visit_id'], $targetUserId, $lat, $lng, 'Auto-stopped on clock out');
                } catch (Exception $e) {
                    error_log('[time-clock] stopVisitTimer failed for user #' . $targetUserId . ' visit #' . (int)$activeJob['visit_id'] . ': ' . $e->getMessage());
                }
            }

            $totalMinutes = clockOut($targetUserId, $lat, $lng, $notes);

            // Log if admin clocked out someone else
            if ($targetUserId !== $user['id']) {
                logActivity($user['id'], null, 'Admin clock-out for user #' . $targetUserId, formatMinutesAsHours($totalMinutes) . ' total');
            }

            // Truck cascade — if this driver has an assigned truck device, clock it out too.
            // Wrapped entirely in try/catch so any DB error (e.g. migration not yet run)
            // can never prevent the clock-out success response from reaching the client.
            $truckCascaded = false;
            try {
                $truckStmt = $db->prepare("SELECT assigned_truck_user_id FROM users WHERE id = ? LIMIT 1");
                $truckStmt->execute([$targetUserId]);
                $truckRow = $truckStmt->fetch(PDO::FETCH_ASSOC);
                if ($truckRow && !empty($truckRow['assigned_truck_user_id'])) {
                    $truckUserId = (int)$truckRow['assigned_truck_user_id'];
                    $truckActive = getActiveClockEntry($truckUserId);
                    if ($truckActive) {
                        $truckJob = getActiveVisitTimer($truckUserId);
                        if ($truckJob) {
                            stopVisitTimer((int)$truckJob['visit_id'], $truckUserId, $lat, $lng, 'Auto-stopped on driver clock out');
                        }
                        clockOut($truckUserId, $lat, $lng, 'Auto-clocked out — driver clocked out');
                        $truckCascaded = true;
                    }
                }
            } catch (Exception $e) {
                // Cascade failure is non-fatal — column may not exist yet (run migration 1004)
            }

            echo json_encode([
                'success' => true,
                'message' => 'Clocked out successfully',
                'total_minutes' => $totalMinutes,
                'total_formatted' => formatMinutesAsHours($totalMinutes),
                'truck_cascaded' => $truckCascaded,
            ]);
            break;

        case 'admin_void':
            // Admin/manager only: force-complete an active clock entry for any user.
            if (!in_array($user['role'], ['admin', 'manager'])) {
                http_response_code(403);
                echo json_encode(['error' => 'Admin or manager role required']);
                exit;
            }
            if (empty($input['user_id'])) {
                http_response_code(400);
                echo json_encode(['error' => 'user_id required']);
                exit;
            }
            $voidUserId = (int)$input['user_id'];
            $voidReason = trim($input['reason'] ?? 'admin override');
            $voidEntry  = getActiveClockEntry($voidUserId);
            if (!$voidEntry) {
                http_response_code(404);
                echo json_encode(['error' => 'No active clock entry for this user']);
                exit;
            }
            $autoHours = max(1, (int)getTimeClockSetting('auto_clock_out_hours', 10));
            $db->prepare("
                UPDATE time_clock_entries
                SET clock_out     = NOW(),
                    total_minutes = TIMESTAMPDIFF(MINUTE, clock_in, NOW()),
                    status        = 'completed',
                    notes         = CONCAT(COALESCE(notes,''), ' [admin voided by #', ?, ': ', ?, ']'),
                    edited_at     = NOW()
                WHERE id = ?
            ")->execute([$user['id'], $voidReason, $voidEntry['id']]);
            logActivity($user['id'], null, 'Admin void clock entry #' . $voidEntry['id'] . ' for user #' . $voidUserId, $voidReason);
            echo json_encode(['success' => true, 'message' => 'Clock entry voided']);
            break;

        default:
            http_response_code(400);
            echo json_encode(['error' => 'Invalid action. Use: status, clock_in, clock_out, admin_void']);
    }

} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['error' => $e->getMessage()]);
}

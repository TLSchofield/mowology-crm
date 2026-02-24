<?php
/**
 * Visit Timer API — Start / Stop / Pause / Active status
 * POST: {action: 'start', visit_id, lat?, lng?, auto_started?}
 * POST: {action: 'stop', visit_id, lat?, lng?, notes?, complete_visit?}
 * POST: {action: 'pause', visit_id, lat?, lng?}
 * GET:  ?action=active
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
    require_once CRM_INCLUDES . '/plan-functions.php';

    // Load location functions if available
    $locFuncsPath = CRM_INCLUDES . '/location-functions.php';
    if (file_exists($locFuncsPath)) {
        require_once $locFuncsPath;
    }

    requireLogin();
    $user = getCurrentUser();
    requirePermission('timer.start');

    if ($_SERVER['REQUEST_METHOD'] === 'GET') {
        $action = $_GET['action'] ?? '';
    } else {
        $input = json_decode(file_get_contents('php://input'), true);
        $action = $input['action'] ?? '';
    }

    switch ($action) {
        case 'active':
            $activeTimer = getActiveVisitTimer($user['id']);
            echo json_encode([
                'success' => true,
                'active_timer' => $activeTimer ? [
                    'id' => (int)$activeTimer['id'],
                    'visit_id' => (int)$activeTimer['visit_id'],
                    'job_title' => $activeTimer['job_title'],
                    'job_number' => $activeTimer['job_number'],
                    'property_address' => $activeTimer['property_address'],
                    'company_name' => $activeTimer['company_name'],
                    'start_time' => $activeTimer['start_time'],
                    'elapsed_seconds' => time() - strtotime($activeTimer['start_time']),
                ] : null,
            ]);
            break;

        case 'start':
            $visitId = (int)($input['visit_id'] ?? 0);
            if (!$visitId) {
                throw new Exception('visit_id is required');
            }

            $lat = isset($input['lat']) ? (float)$input['lat'] : null;
            $lng = isset($input['lng']) ? (float)$input['lng'] : null;
            $autoStarted = !empty($input['auto_started']);

            $entryId = startVisitTimer($visitId, $user['id'], $lat, $lng, $autoStarted);

            // Resolve tracking requirements for this visit (product defaults + plan overrides)
            $trackingReqs = [];
            if (function_exists('resolveTrackingRequirements')) {
                $trackingReqs = resolveTrackingRequirements($visitId);
            }

            echo json_encode([
                'success' => true,
                'message' => 'Visit timer started',
                'entry_id' => $entryId,
                'visit_id' => $visitId,
                'start_time' => date('Y-m-d H:i:s'),
                'auto_started' => $autoStarted,
                'auto_clock_in' => (bool)($trackingReqs['auto_clock_in'] ?? false),
                'tracking_level' => $trackingReqs['tracking_level'] ?? 'standard',
                'require_photos' => (bool)($trackingReqs['require_photos'] ?? false),
                'require_gps' => (bool)($trackingReqs['require_gps'] ?? false),
                'require_clock_in' => (bool)($trackingReqs['require_clock_in'] ?? false),
            ]);
            break;

        case 'stop':
            $visitId = (int)($input['visit_id'] ?? 0);
            if (!$visitId) {
                throw new Exception('visit_id is required');
            }

            $lat = isset($input['lat']) ? (float)$input['lat'] : null;
            $lng = isset($input['lng']) ? (float)$input['lng'] : null;
            $notes = $input['notes'] ?? null;
            $completeVisit = $input['complete_visit'] ?? true;

            $duration = stopVisitTimer($visitId, $user['id'], $lat, $lng, $notes, (bool)$completeVisit);

            echo json_encode([
                'success' => true,
                'message' => 'Visit timer stopped',
                'visit_id' => $visitId,
                'duration_minutes' => $duration,
                'duration_formatted' => formatMinutesAsHours($duration),
                'visit_completed' => (bool)$completeVisit,
            ]);
            break;

        case 'pause':
            $visitId = (int)($input['visit_id'] ?? 0);
            if (!$visitId) {
                throw new Exception('visit_id is required');
            }

            $lat = isset($input['lat']) ? (float)$input['lat'] : null;
            $lng = isset($input['lng']) ? (float)$input['lng'] : null;

            $duration = pauseVisitTimer($visitId, $user['id'], $lat, $lng);

            echo json_encode([
                'success' => true,
                'message' => 'Visit timer paused',
                'visit_id' => $visitId,
                'duration_minutes' => $duration,
            ]);
            break;

        case 'plan_time_log':
            // GET ?action=plan_time_log&plan_id=N
            // Returns all time entries for every visit belonging to this plan,
            // plus GPS-ping-derived on-site time for assigned crew without timer entries.
            $planId = (int)($_GET['plan_id'] ?? 0);
            if (!$planId) throw new Exception('plan_id is required');

            $db = getDB();

            // All timestamps are displayed in America/Vancouver (Pacific) time.
            // Stored timestamps may be in EST (legacy, before nowPacific() fix) or Pacific (new).
            // We use UNIX_TIMESTAMP() to get the true UTC epoch of each stored value,
            // then format it in Pacific using PHP's DateTime — this works regardless of
            // what timezone the server stored the value in.
            $pacificTz = new DateTimeZone('America/Vancouver');

            /**
             * Convert any stored MySQL datetime string to a Pacific datetime string.
             * UNIX_TIMESTAMP of the stored value gives the true UTC epoch,
             * which we then format in Pacific time.
             * Returns null if the input is empty/null.
             */
            $toPacific = function(?string $ts) use ($pacificTz): ?string {
                if (!$ts) return null;
                // strtotime interprets the string using PHP's local timezone (EST on this server)
                // which gives the correct UTC epoch for EST-stored values.
                // For Pacific-stored values (new), strtotime also gives correct UTC epoch.
                // Either way, formatting in Pacific via DateTime produces the correct local time.
                $epoch = strtotime($ts);
                if ($epoch === false) return null;
                return (new DateTime('@' . $epoch))->setTimezone($pacificTz)->format('Y-m-d H:i:s');
            };

            // Fetch all job_time_entries for this plan's visits
            $stmt = $db->prepare("
                SELECT
                    jte.id,
                    jte.visit_id,
                    jv.visit_number,
                    jv.scheduled_date,
                    jv.status AS visit_status,
                    jte.user_id,
                    u.full_name AS crew_name,
                    jte.start_time,
                    jte.end_time,
                    jte.duration_minutes,
                    jte.status AS entry_status,
                    jte.auto_started,
                    jte.notes
                FROM job_time_entries jte
                JOIN job_visits jv ON jte.visit_id = jv.id
                JOIN users u ON jte.user_id = u.id
                WHERE jv.plan_id = ?
                  AND jte.status IN ('active', 'completed', 'edited')
                ORDER BY jte.start_time ASC
            ");
            $stmt->execute([$planId]);
            $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

            // Track which (visit_id, user_id) pairs already have COMPLETED timer entries.
            // Active timers (no end_time) don't block GPS — GPS shows the full on-site window.
            $timerCovered = []; // key: "visitId_userId" — only set for completed/edited entries
            $totalMinutes = 0;
            $entries = [];

            foreach ($rows as $r) {
                // Only block GPS for this crew+visit if their timer is actually complete with real duration
                if (in_array($r['entry_status'] ?? '', ['completed', 'edited']) && $r['end_time']) {
                    $timerCovered[$r['visit_id'] . '_' . $r['user_id']] = true;
                }

                // Convert stored timestamps to Pacific (works for both EST-stored and Pacific-stored values)
                $startCorrected = $toPacific($r['start_time']);
                $endCorrected   = $toPacific($r['end_time']);

                // Recalculate duration from corrected times
                $mins = (int)($r['duration_minutes'] ?? 0);
                if ($startCorrected && $endCorrected) {
                    $mins = (int)round((strtotime($endCorrected) - strtotime($startCorrected)) / 60);
                }
                $totalMinutes += $mins;

                $entries[] = [
                    'id'               => (int)$r['id'],
                    'visit_id'         => (int)$r['visit_id'],
                    'visit_number'     => $r['visit_number'],
                    'scheduled_date'   => $r['scheduled_date'],
                    'visit_status'     => $r['visit_status'],
                    'crew_name'        => $r['crew_name'],
                    'start_time'       => $startCorrected,
                    'end_time'         => $endCorrected,
                    'duration_minutes' => $mins,
                    'duration_formatted' => formatMinutesAsHours($mins),
                    'entry_status'     => $r['entry_status'],
                    'auto_started'     => (bool)$r['auto_started'],
                    'notes'            => $r['notes'],
                    'source'           => 'timer',
                ];
            }

            // --- GPS-ping-based time for crew without timer entries ---
            // Get all visits for this plan with property coordinates + assigned crew.
            // Prefer non-cancelled visits; order by status so active/in_progress come first.
            $visitStmt = $db->prepare("
                SELECT
                    jv.id AS visit_id,
                    jv.visit_number,
                    jv.scheduled_date,
                    jv.status AS visit_status,
                    jv.stop_id,
                    p.latitude AS prop_lat,
                    p.longitude AS prop_lng,
                    cs.crew_id AS stop_crew_id
                FROM job_visits jv
                JOIN job_plans jp ON jv.plan_id = jp.id
                JOIN properties p ON jp.property_id = p.id
                LEFT JOIN calendar_stops cs ON jv.stop_id = cs.id
                WHERE jv.plan_id = ?
                  AND p.latitude IS NOT NULL
                  AND p.longitude IS NOT NULL
                ORDER BY
                    FIELD(jv.status, 'in_progress', 'scheduled', 'completed', 'skipped', 'cancelled') ASC,
                    jv.scheduled_date ASC
            ");
            $visitStmt->execute([$planId]);
            $visitRows = $visitStmt->fetchAll(PDO::FETCH_ASSOC);

            // Track which crew+date combos already have GPS pings attributed
            // to avoid counting the same pings twice across multiple visits sharing a stop
            $gpsCovered = []; // key: "userId_date"

            // For each visit, get all assigned crew from junction table
            foreach ($visitRows as $vr) {
                if (!$vr['stop_crew_id']) continue;
                // Skip cancelled visits for GPS attribution — pings will be picked up
                // by the non-cancelled visit sharing the same stop
                if ($vr['visit_status'] === 'cancelled') continue;

                $propLat = (float)$vr['prop_lat'];
                $propLng = (float)$vr['prop_lng'];
                $visitDate = $vr['scheduled_date'];

                // Get all crew assigned to this stop (lead + junction)
                $crewStmt = $db->prepare("
                    SELECT DISTINCT u.id AS user_id, u.full_name
                    FROM (
                        SELECT crew_id AS uid FROM calendar_stops WHERE id = (
                            SELECT stop_id FROM job_visits WHERE id = ?
                        ) AND crew_id IS NOT NULL
                        UNION
                        SELECT user_id AS uid FROM calendar_stop_crew WHERE stop_id = (
                            SELECT stop_id FROM job_visits WHERE id = ?
                        )
                    ) assigned
                    JOIN users u ON u.id = assigned.uid
                    WHERE u.location_tracking_enabled = 1
                ");
                $crewStmt->execute([$vr['visit_id'], $vr['visit_id']]);
                $assignedCrew = $crewStmt->fetchAll(PDO::FETCH_ASSOC);

                foreach ($assignedCrew as $crewMember) {
                    $coverKey = $vr['visit_id'] . '_' . $crewMember['user_id'];
                    if (isset($timerCovered[$coverKey])) continue; // already have timer data

                    // Skip if we already attributed GPS pings for this crew on this date
                    // (same crew+date pings were already counted against a prior visit from the same stop)
                    $gpsKey = $crewMember['user_id'] . '_' . $vr['scheduled_date'];
                    if (isset($gpsCovered[$gpsKey])) continue;

                    // Fetch GPS pings for this crew member on the visit date within ~150m of property
                    // Use Haversine approx in degrees: 150m ≈ 0.00135 degrees lat, adjust lng by cos(lat)
                    $latDelta = 0.00135; // ~150m
                    $lngDelta = 0.00135 / max(cos(deg2rad($propLat)), 0.001);

                    // Use epoch-based day boundaries (Pacific) so filter works regardless of
                    // whether pings are stored in EST (legacy) or Pacific (new)
                    $pacificTz   = new DateTimeZone('America/Vancouver');
                    $dayEpochStart = (new DateTime($visitDate . ' 00:00:00', $pacificTz))->getTimestamp();
                    $dayEpochEnd   = (new DateTime($visitDate . ' 23:59:59', $pacificTz))->getTimestamp();

                    $pingStmt = $db->prepare("
                        SELECT timestamp, latitude, longitude
                        FROM crew_location_history
                        WHERE crew_id = ?
                          AND UNIX_TIMESTAMP(timestamp) >= ?
                          AND UNIX_TIMESTAMP(timestamp) <= ?
                          AND latitude  BETWEEN ? AND ?
                          AND longitude BETWEEN ? AND ?
                        ORDER BY timestamp ASC
                    ");
                    $pingStmt->execute([
                        $crewMember['user_id'],
                        $dayEpochStart, $dayEpochEnd,
                        $propLat - $latDelta, $propLat + $latDelta,
                        $propLng - $lngDelta, $propLng + $lngDelta,
                    ]);
                    $pings = $pingStmt->fetchAll(PDO::FETCH_ASSOC);

                    if (empty($pings)) continue;

                    // Convert ping timestamps to Pacific (works for both EST-stored and Pacific-stored pings)
                    $firstCorrected = $toPacific($pings[0]['timestamp']);
                    $lastCorrected  = $toPacific($pings[count($pings) - 1]['timestamp']);

                    $gpsMins = (int)round((strtotime($lastCorrected) - strtotime($firstCorrected)) / 60);
                    $totalMinutes += $gpsMins;
                    $gpsCovered[$gpsKey] = true; // mark as attributed so duplicate visits don't re-count

                    $entries[] = [
                        'id'               => null,
                        'visit_id'         => (int)$vr['visit_id'],
                        'visit_number'     => $vr['visit_number'],
                        'scheduled_date'   => $vr['scheduled_date'],
                        'visit_status'     => $vr['visit_status'],
                        'crew_name'        => $crewMember['full_name'],
                        'start_time'       => $firstCorrected,
                        'end_time'         => $lastCorrected,
                        'duration_minutes' => $gpsMins,
                        'duration_formatted' => formatMinutesAsHours($gpsMins),
                        'entry_status'     => 'completed',
                        'auto_started'     => false,
                        'notes'            => count($pings) . ' GPS pings',
                        'source'           => 'gps',
                    ];
                }
            }

            // Sort all entries by start_time
            usort($entries, function($a, $b) {
                return strcmp($a['start_time'] ?? '', $b['start_time'] ?? '');
            });

            echo json_encode([
                'success'         => true,
                'plan_id'         => $planId,
                'total_minutes'   => $totalMinutes,
                'total_formatted' => formatMinutesAsHours($totalMinutes),
                'entries'         => $entries,
            ]);
            break;

        default:
            http_response_code(400);
            echo json_encode(['error' => 'Invalid action. Use: active, start, stop, pause, plan_time_log']);
    }

} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['error' => $e->getMessage()]);
}

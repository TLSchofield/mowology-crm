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
    require_once APP_ROOT . '/Core/ApiResponse.php'; // generic errors to the user, details to the log
    require_once APP_ROOT . '/Core/IdempotencyHelper.php';
    require_once CRM_INCLUDES . '/functions.php';
    require_once CRM_INCLUDES . '/timeclock-functions.php';
    require_once CRM_INCLUDES . '/plan-functions.php';
    // NOT loading location-functions.php here: it defines its own, different
    // findNearbyProperties($crewLat, $crewLng, $radiusKm) — a name collision
    // with plan-functions.php's findNearbyProperties(float $lat, float $lng,
    // int $radiusM, int $limit) (different signature/units/purpose). Loading
    // both fatals with "Cannot redeclare findNearbyProperties()". This file
    // doesn't call either variant, so it never needed the include.

    $isJwt = strpos($_SERVER['HTTP_AUTHORIZATION'] ?? $_SERVER['REDIRECT_HTTP_AUTHORIZATION'] ?? '', 'Bearer ') === 0;
    $user = requireLoginOrJwt();
    requirePermission('timer.start');

    // Idempotency key — sent by iOS/Capacitor clients as a UUID per action attempt.
    $idempKey = trim($_SERVER['HTTP_IDEMPOTENCY_KEY'] ?? '');

    if ($_SERVER['REQUEST_METHOD'] === 'GET') {
        $action = $_GET['action'] ?? '';
    } else {
        $input = json_decode(file_get_contents('php://input'), true);
        if (!is_array($input)) $input = [];
        $action = $input['action'] ?? '';

        // Session POSTs carry the CSRF token (X-CSRF-Token header or csrf_token in the body);
        // JWT callers (iOS) are exempt. A stale token answers CSRF_INVALID so the page refreshes
        // it from /crm/api/get-csrf.php and retries once (2026-10-12 review, as visit-pull-forward).
        if (!$isJwt) {
            $token = (string)($_SERVER['HTTP_X_CSRF_TOKEN'] ?? $input['csrf_token'] ?? '');
            if (!verifyCSRFToken($token)) {
                http_response_code(403);
                echo json_encode(['success' => false, 'error' => 'Invalid CSRF token — refresh and retry', 'code' => 'CSRF_INVALID']);
                exit;
            }
        }
    }

    // Plan time log / GPS pings show every crew member's on-site times and positions — office only
    // (2026-10-12 review: any login could read anyone's GPS trail through these).
    if (in_array($action, ['plan_time_log', 'gps_pings'], true)
        && !array_filter(['schedule.edit', 'team.edit'], function ($p) use ($isJwt, $user) {
               return $isJwt ? jwtUserHasPermission($user, $p) : userHasPermission($p);
           })) {
        http_response_code(403);
        echo json_encode(['success' => false, 'error' => 'Not allowed']);
        exit;
    }

    // When the phone says it happened: client_time (ISO) from the app, else the web offline
    // queue's X-Queued-At (ms). OfflineTime::accept() decides whether to trust it.
    $clientTime = null;
    if (!empty($input['client_time'])) {
        $clientTime = (string)$input['client_time'];
    } elseif (!empty($_SERVER['HTTP_X_QUEUED_AT']) && ctype_digit((string)$_SERVER['HTTP_X_QUEUED_AT'])) {
        $clientTime = date('c', intdiv((int)$_SERVER['HTTP_X_QUEUED_AT'], 1000));
    }
    $isQueued = !empty($input['queued']) || !empty($_SERVER['HTTP_X_QUEUED_AT']);

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

            // Crew on the visit or office (#15, Fable audit 2026-10-12) — checked here, before the gates,
            // idempotency replay and auto clock-in, not only deep inside startJobTimer().
            if (!userMayActOnVisit($visitId, (int)$user['id'])) {
                http_response_code(403);
                echo json_encode(['success' => false, 'error' => 'You are not assigned to this visit']);
                exit;
            }

            // Return cached response if this key was already processed.
            if ($idempKey) {
                $db = getDB();
                $cached = idempotencyCheck($db, $idempKey, $user['id']);
                if ($cached !== null) { echo $cached; exit; }
            }

            $lat = isset($input['lat']) ? (float)$input['lat'] : null;
            $lng = isset($input['lng']) ? (float)$input['lng'] : null;
            $autoStarted = !empty($input['auto_started']);

            // A gated user (TrackingSetupGate) must clock in through the checked path;
            // starting a job must not clock them in around it.
            require_once APP_ROOT . '/Modules/Team/Services/TrackingSetupGate.php';
            if (TrackingSetupGate::appliesTo(getTimeClockSetting(TrackingSetupGate::SETTING, ''), (int)$user['id'])
                && !getActiveClockEntry($user['id'])) {
                http_response_code(403);
                echo json_encode(['success' => false, 'error' => 'Clock in first, using the Clock In button.', 'tracking_setup_required' => true]);
                break;
            }

            // Special request gate (inert unless ops_settings.special_requests_enabled): an unread
            // client request on this visit answers 409 to a live tap. Only offline-queue.js replays
            // carry Idempotency-Key / X-Queued-At here — they pass (logged) so they never stick.
            require_once APP_ROOT . '/Modules/Operations/Services/SpecialRequestGate.php';
            SpecialRequestGate::enforce(getDB(), $visitId, (int)$user['id'],
                $idempKey !== '' || !empty($_SERVER['HTTP_X_QUEUED_AT']),
                $_SERVER['HTTP_X_QUEUED_AT'] ?? null, $autoStarted ? 'auto_start' : 'start', 'error');

            // Queued offline? Record when it was tapped, not when it arrived (OfflineTime) —
            // same rule as app/Modules/Schedule/Api/timer.php.
            require_once APP_ROOT . '/Modules/Team/Services/OfflineTime.php';
            $when = OfflineTime::accept($clientTime, date('Y-m-d H:i:s'));
            if ($when['reject']) {   // over 7 days old: record nothing (the office enters it by hand)
                if (function_exists('logActivity')) logActivity($user['id'], null, 'job_start_not_recorded', "Visit {$visitId}: " . $when['note']);
                echo json_encode(['success' => true, 'recorded' => false, 'visit_id' => $visitId, 'message' => $when['note']]);
                break;
            }
            $tapped = $when['from_phone'] ? $when['at'] : null;

            $entryId = startVisitTimer($visitId, $user['id'], $lat, $lng, $autoStarted, $tapped, $isQueued);
            if ($isQueued && ($lat === null || $lng === null)) {
                $when['note'] = trim(($when['note'] ?? '') . ' No location (offline).');
            }
            if ($when['note']) {
                getDB()->prepare("UPDATE job_time_entries SET notes = TRIM(CONCAT(COALESCE(notes, ''), ' ', ?)) WHERE id = ?")
                       ->execute([$when['note'], $entryId]);
            }

            // Auto clock-in globally if user is not already clocked in — through clockIn(), at the
            // same tapped time as the timer.
            $autoClockInPerformed = false;
            $autoClockInTime      = null;
            try {
                if (!getActiveClockEntry($user['id'])) {
                    clockIn($user['id'], $lat, $lng, false, $tapped);
                    $autoClockInPerformed = true;
                    $autoClockInTime      = $when['at'];
                }
            } catch (Exception $e) {
                // Non-fatal — timer already started successfully
            }

            // Resolve tracking requirements for this visit (product defaults + plan overrides)
            $trackingReqs = [];
            if (function_exists('resolveTrackingRequirements')) {
                $trackingReqs = resolveTrackingRequirements($visitId);
            }

            $responseStart = json_encode([
                'success' => true,
                'message' => 'Visit timer started',
                'entry_id' => $entryId,
                'visit_id' => $visitId,
                'start_time' => $when['at'],
                'time_flag' => $when['flag'],
                'auto_started' => $autoStarted,
                'auto_clock_in' => (bool)($trackingReqs['auto_clock_in'] ?? false),
                'tracking_level' => $trackingReqs['tracking_level'] ?? 'standard',
                'require_photos' => (bool)($trackingReqs['require_photos'] ?? false),
                'require_gps' => (bool)($trackingReqs['require_gps'] ?? false),
                'require_clock_in' => (bool)($trackingReqs['require_clock_in'] ?? false),
                'auto_clock_in_performed' => $autoClockInPerformed,
                'auto_clock_in_time'      => $autoClockInTime,
            ]);
            if ($idempKey) {
                if (!isset($db)) $db = getDB();
                idempotencyStore($db, $idempKey, $user['id'], 'job-timer', 'start', $responseStart);
            }
            echo $responseStart;
            break;

        case 'stop':
            $visitId = (int)($input['visit_id'] ?? 0);
            if (!$visitId) {
                throw new Exception('visit_id is required');
            }

            // Return cached response if this key was already processed.
            if ($idempKey) {
                $db = getDB();
                $cached = idempotencyCheck($db, $idempKey, $user['id']);
                if ($cached !== null) { echo $cached; exit; }
            }

            // Same ownership rule as start (2026-10-12): crew on the visit, own timer, or admin/manager.
            if (!userMayActOnVisit($visitId, (int)$user['id'])) {
                http_response_code(403);
                echo json_encode(['success' => false, 'error' => 'You are not assigned to this visit']);
                exit;
            }

            $lat = isset($input['lat']) ? (float)$input['lat'] : null;
            $lng = isset($input['lng']) ? (float)$input['lng'] : null;
            $notes = $input['notes'] ?? null;
            $completeVisit = $input['complete_visit'] ?? true;

            // Queued offline? The stop counts from when it was tapped — never before this
            // person's own timer started (OfflineTime notBefore).
            require_once APP_ROOT . '/Modules/Team/Services/OfflineTime.php';
            $ownStart = getDB()->prepare("SELECT MAX(start_time) FROM job_time_entries
                                          WHERE visit_id = ? AND user_id = ? AND status = 'active' AND end_time IS NULL");
            $ownStart->execute([$visitId, $user['id']]);
            $startedAt = $ownStart->fetchColumn() ?: null;
            $when = OfflineTime::accept($clientTime, date('Y-m-d H:i:s'), $startedAt ? (string)$startedAt : null);
            if ($when['reject']) {
                if (function_exists('logActivity')) logActivity($user['id'], null, 'job_stop_not_recorded', "Visit {$visitId}: " . $when['note']);
                echo json_encode(['success' => true, 'recorded' => false, 'visit_id' => $visitId, 'message' => $when['note']]);
                break;
            }
            if ($when['note']) $notes = trim(((string)$notes) . ' ' . $when['note']);

            $duration = stopVisitTimer($visitId, $user['id'], $lat, $lng, $notes, (bool)$completeVisit,
                                       $when['from_phone'] ? $when['at'] : null);

            // Special-request extra work answered before completion goes on the visit's extras now.
            if ($completeVisit) {
                require_once APP_ROOT . '/Modules/Operations/Services/SpecialRequestGate.php';
                SpecialRequestGate::afterCompletion(getDB(), $visitId);
            }

            $responseStop = json_encode([
                'success' => true,
                'message' => 'Visit timer stopped',
                'visit_id' => $visitId,
                'duration_minutes' => $duration,
                'duration_formatted' => formatMinutesAsHours($duration),
                'visit_completed' => (bool)$completeVisit,
            ]);
            if ($idempKey) {
                if (!isset($db)) $db = getDB();
                idempotencyStore($db, $idempKey, $user['id'], 'job-timer', 'stop', $responseStop);
            }
            echo $responseStop;
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

            // MySQL session TZ is set to America/Vancouver in Database::pdo() (config.php),
            // so NOW() and stored timestamps are already Pacific. No conversion needed.
            $pacificTz = new DateTimeZone('America/Vancouver');

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

                // Timestamps already stored in Pacific (MySQL session TZ = America/Vancouver)
                $startCorrected = $r['start_time'];
                $endCorrected   = $r['end_time'];

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

                    // Select UNIX_TIMESTAMP(timestamp) so ping times are timezone-agnostic UTC epochs.
                    // This handles both EST-stored (legacy NOW()) and Pacific-stored pings correctly.
                    $pingStmt = $db->prepare("
                        SELECT UNIX_TIMESTAMP(timestamp) as epoch, latitude, longitude
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

                    // UNIX_TIMESTAMP() returns true UTC epoch — convert directly to Pacific
                    $firstCorrected = (new DateTime('@' . $pings[0]['epoch']))->setTimezone($pacificTz)->format('Y-m-d H:i:s');
                    $lastCorrected  = (new DateTime('@' . $pings[count($pings) - 1]['epoch']))->setTimezone($pacificTz)->format('Y-m-d H:i:s');

                    $gpsMins = (int)round(($pings[count($pings) - 1]['epoch'] - $pings[0]['epoch']) / 60);
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

        case 'gps_pings':
            // GET ?action=gps_pings&plan_id=N
            // Returns raw GPS pings for all crew assigned to this plan, filtered
            // to within ~200m of the property. Used to render a map alongside the time log.
            $planId = (int)($_GET['plan_id'] ?? 0);
            if (!$planId) throw new Exception('plan_id is required');

            $db = getDB();

            // Get property coordinates via plan → property
            $propRow = $db->prepare("
                SELECT p.latitude, p.longitude
                FROM job_plans jp
                JOIN properties p ON jp.property_id = p.id
                WHERE jp.id = ?
            ");
            $propRow->execute([$planId]);
            $prop = $propRow->fetch(PDO::FETCH_ASSOC);

            if (!$prop || !$prop['latitude'] || !$prop['longitude']) {
                echo json_encode(['success' => true, 'pings' => [], 'prop_lat' => null, 'prop_lng' => null]);
                break;
            }

            $propLat = (float)$prop['latitude'];
            $propLng = (float)$prop['longitude'];

            // ~200m bounding box — captures on-site pings without pulling in
            // an entire neighbourhood driving route from service vehicles.
            $latDelta = 0.0018;
            $lngDelta = 0.0018 / max(cos(deg2rad($propLat)), 0.001);

            // Search ALL location-tracked users — not just plan-assigned crew.
            // Service vehicles (trucks, equipment) are tracked but may not appear
            // in calendar_stops for a specific plan. The map shows whoever was near
            // the property on the job dates, giving the full on-site picture.
            $crewStmt = $db->prepare("SELECT id, full_name FROM users WHERE location_tracking_enabled = 1");
            $crewStmt->execute();
            $allCrew = $crewStmt->fetchAll(PDO::FETCH_ASSOC);

            if (empty($allCrew)) {
                echo json_encode(['success' => true, 'pings' => [], 'prop_lat' => $propLat, 'prop_lng' => $propLng]);
                break;
            }

            $crewIds   = array_column($allCrew, 'id');
            $crewNames = array_column($allCrew, 'full_name', 'id');

            $pacificTz = new DateTimeZone('America/Vancouver');

            // Build date ranges from actual time entry dates (not just scheduled dates).
            // This handles timers that ran on a different calendar day than the scheduled visit.
            $dateStmt = $db->prepare("
                SELECT DISTINCT DATE(jte.start_time) AS entry_date
                FROM job_time_entries jte
                JOIN job_visits jv ON jte.visit_id = jv.id
                WHERE jv.plan_id = ?
                  AND jte.start_time IS NOT NULL
                ORDER BY entry_date DESC
                LIMIT 50
            ");
            $dateStmt->execute([$planId]);
            $entryDates = array_column($dateStmt->fetchAll(PDO::FETCH_ASSOC), 'entry_date');

            // Also include scheduled visit dates as fallback
            $schedStmt = $db->prepare("
                SELECT DISTINCT scheduled_date FROM job_visits WHERE plan_id = ? ORDER BY scheduled_date DESC LIMIT 50
            ");
            $schedStmt->execute([$planId]);
            $schedDates = array_column($schedStmt->fetchAll(PDO::FETCH_ASSOC), 'scheduled_date');

            $allDates = array_unique(array_merge($entryDates, $schedDates));

            if (empty($allDates)) {
                echo json_encode(['success' => true, 'pings' => [], 'prop_lat' => $propLat, 'prop_lng' => $propLng]);
                break;
            }

            $dateConditions = [];
            $dateParams = [];
            foreach ($allDates as $vDate) {
                $start = (new DateTime($vDate . ' 00:00:00', $pacificTz))->getTimestamp();
                $end   = (new DateTime($vDate . ' 23:59:59', $pacificTz))->getTimestamp();
                $dateConditions[] = "(UNIX_TIMESTAMP(timestamp) BETWEEN ? AND ?)";
                $dateParams[] = $start;
                $dateParams[] = $end;
            }

            $placeholders = implode(',', array_fill(0, count($crewIds), '?'));
            $dateSql = implode(' OR ', $dateConditions);

            $pingStmt = $db->prepare("
                SELECT crew_id, UNIX_TIMESTAMP(timestamp) AS epoch, latitude, longitude
                FROM crew_location_history
                WHERE crew_id IN ($placeholders)
                  AND latitude  BETWEEN ? AND ?
                  AND longitude BETWEEN ? AND ?
                  AND ($dateSql)
                ORDER BY timestamp ASC
            ");

            $params = array_merge(
                $crewIds,
                [$propLat - $latDelta, $propLat + $latDelta, $propLng - $lngDelta, $propLng + $lngDelta],
                $dateParams
            );
            $pingStmt->execute($params);
            $rawPings = $pingStmt->fetchAll(PDO::FETCH_ASSOC);

            $pings = [];
            foreach ($rawPings as $p) {
                $ts = (new DateTime('@' . $p['epoch']))->setTimezone($pacificTz)->format('Y-m-d H:i:s');
                $pings[] = [
                    'crew_id'    => (int)$p['crew_id'],
                    'crew_name'  => $crewNames[$p['crew_id']] ?? 'Unknown',
                    'lat'        => (float)$p['latitude'],
                    'lng'        => (float)$p['longitude'],
                    'time'       => $ts,
                ];
            }

            echo json_encode([
                'success'  => true,
                'prop_lat' => $propLat,
                'prop_lng' => $propLng,
                'pings'    => $pings,
            ]);
            break;

        default:
            http_response_code(400);
            echo json_encode(['error' => 'Invalid action. Use: active, start, stop, pause, plan_time_log, gps_pings']);
    }

} catch (Throwable $e) {
    // Was `catch (Exception $e)` — missed Error-class throwables (TypeError,
    // etc.), which PHP then fails with an empty 500 response instead of the
    // JSON error body below.
    http_response_code(500);
    echo json_encode(['error' => ApiResponse::message($e, 'job-timer')]);
}

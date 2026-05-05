<?php
/**
 * Time Clock & Job Timer Helper Functions
 *
 * Migrated from: public/crm/includes/timeclock-functions.php
 */

/**
 * Idempotency guard for timer and clock mutation endpoints.
 *
 * Writes the (key, endpoint) pair via INSERT IGNORE. If rowCount() === 0 the
 * key was already processed — the caller should return a 200 success replay
 * response without re-executing the mutation.
 *
 * Pass an empty/null key to skip the guard (old clients without the key field
 * continue to work normally; their protection comes from the existing state checks).
 *
 * Usage (call BEFORE your existing state-check logic):
 *   guardIdempotency('timer/start');
 *
 * Exits with 200 + {success:true, idempotent_replay:true} if the key was
 * already seen. Prunes keys older than 24 hours on each call.
 */
function guardIdempotency(string $endpoint): void {
    $key = trim((string)($_POST['idempotency_key'] ?? ''));

    // Also check JSON body — job-timer.php uses php://input
    if ($key === '') {
        $rawInput = file_get_contents('php://input');
        if ($rawInput) {
            $decoded = json_decode($rawInput, true);
            $key = trim((string)($decoded['idempotency_key'] ?? ''));
        }
    }

    if ($key === '') {
        return; // No key supplied — old clients, skip guard
    }

    $db = getDB();

    // Prune expired keys (non-blocking; failure is acceptable)
    try {
        $db->exec("DELETE FROM idempotency_keys WHERE created_at < NOW() - INTERVAL 1 DAY");
    } catch (Exception $e) {
        // Table may not exist yet on first deploy — safe to ignore
        return;
    }

    $stmt = $db->prepare('INSERT IGNORE INTO idempotency_keys (idem_key, endpoint) VALUES (?, ?)');
    $stmt->execute([$key, $endpoint]);

    if ($stmt->rowCount() === 0) {
        // Key already processed — return success without re-executing
        header('Content-Type: application/json');
        http_response_code(200);
        echo json_encode(['success' => true, 'idempotent_replay' => true]);
        exit;
    }
}

/**
 * Return current Pacific time as a datetime string.
 * config.php sets PHP to America/Vancouver so date() is Pacific.
 * NOTE: DB writes use MySQL NOW() (EST) for consistency with all stored timestamps.
 * This helper is for non-DB Pacific time needs (e.g. API responses, logging).
 */
function nowPacific(): string {
    return date('Y-m-d H:i:s'); // Pacific — config.php sets timezone to America/Vancouver
}

/**
 * Get a time clock setting value
 */
function getTimeClockSetting($key, $default = null) {
    $db = getDB();
    $stmt = $db->prepare("SELECT setting_value FROM time_clock_settings WHERE setting_key = ?");
    $stmt->execute([$key]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    return $row ? $row['setting_value'] : $default;
}

/**
 * Check if time clock is enabled for a given role
 */
function isTimeClockEnabledForRole($role) {
    // Empty/null role defaults to 'user' so new employees can always clock in
    if (empty($role)) $role = 'user';
    $enabledRoles = getTimeClockSetting('enabled_roles', 'admin,manager,user');
    $roles = array_map('trim', explode(',', $enabledRoles));
    return in_array($role, $roles);
}

/**
 * Get the active (not clocked out) clock entry for a user
 * Returns the entry row or false
 */
function getActiveClockEntry($userId) {
    $db = getDB();
    $stmt = $db->prepare("
        SELECT id, user_id, clock_in, clock_in_lat, clock_in_lng, created_at,
               TIMESTAMPDIFF(SECOND, clock_in, NOW()) AS elapsed_seconds
        FROM time_clock_entries
        WHERE user_id = ? AND status = 'active' AND clock_out IS NULL
        ORDER BY clock_in DESC
        LIMIT 1
    ");
    $stmt->execute([$userId]);
    return $stmt->fetch(PDO::FETCH_ASSOC);
}

/**
 * Clock in a user. Returns the new entry ID or throws on error.
 */
function clockIn($userId, $lat = null, $lng = null) {
    // Check for existing active entry
    $existing = getActiveClockEntry($userId);
    if ($existing) {
        throw new Exception('Already clocked in since ' . $existing['clock_in']);
    }

    $db = getDB();
    // Use MySQL NOW() so all stored timestamps are consistently in the MySQL server timezone (EST).
    // The plan_time_log API uses toPacific() to convert EST→Pacific for display.
    $stmt = $db->prepare("
        INSERT INTO time_clock_entries (user_id, clock_in, clock_in_lat, clock_in_lng)
        VALUES (?, NOW(), ?, ?)
    ");
    $stmt->execute([$userId, $lat, $lng]);
    $entryId = (int)$db->lastInsertId();

    // Insert a GPS ping so the crew member appears on the map immediately.
    // Only if tracking is enabled and coordinates were provided.
    if ($lat && $lng) {
        $trackStmt = $db->prepare("SELECT location_tracking_enabled FROM users WHERE id = ?");
        $trackStmt->execute([$userId]);
        $trackRow = $trackStmt->fetch(PDO::FETCH_ASSOC);
        if ($trackRow && $trackRow['location_tracking_enabled']) {
            $db->prepare("
                INSERT INTO crew_location_history (crew_id, latitude, longitude, accuracy_meters, visit_id, timestamp)
                VALUES (?, ?, ?, NULL, NULL, NOW())
            ")->execute([$userId, $lat, $lng]);
        }
    }

    return $entryId;
}

/**
 * Resolve a stale active clock entry from a prior calendar day.
 *
 * If the user has an active entry whose clock_in date is before today,
 * it is auto-completed at clock_in + auto_clock_out_hours (default 10h).
 * Any matching open vehicle_trip_reports for that date are also closed.
 *
 * Call this at login-time before getActiveClockEntry() so the user starts
 * fresh rather than being trapped by a days-old record.
 *
 * Returns true if a stale entry was resolved, false otherwise.
 */
function resolveStaleClockEntry(int $userId): bool {
    $entry = getActiveClockEntry($userId);
    if (!$entry) return false;

    $clockInDate = date('Y-m-d', strtotime($entry['clock_in']));
    if ($clockInDate === date('Y-m-d')) return false;

    $autoHours = max(1, (int) getTimeClockSetting('auto_clock_out_hours', 10));
    $db = getDB();

    $db->prepare("
        UPDATE time_clock_entries
        SET clock_out     = DATE_ADD(clock_in, INTERVAL ? HOUR),
            total_minutes = ? * 60,
            status        = 'completed',
            notes         = CONCAT(COALESCE(notes, ''), ' [auto-completed: stale entry from prior day]'),
            edited_at     = NOW()
        WHERE id = ?
    ")->execute([$autoHours, $autoHours, $entry['id']]);

    ensureTimesheetExists($userId, $clockInDate);

    // Close any open trip reports for that date so the driver is not gated
    // out by a half-finished trip from a prior day.
    try {
        $db->prepare("
            UPDATE vehicle_trip_reports
            SET post_trip_at = DATE_ADD(pre_trip_at, INTERVAL 8 HOUR)
            WHERE driver_id    = ?
              AND report_date  = ?
              AND pre_trip_at  IS NOT NULL
              AND post_trip_at IS NULL
        ")->execute([$userId, $clockInDate]);
    } catch (Throwable $e) {
        // Cleanup is non-fatal — table may not exist yet on older schemas.
        error_log('[resolveStaleClockEntry] trip report cleanup failed: ' . $e->getMessage());
    }

    return true;
}

/**
 * Clock out a user. Returns total_minutes or throws on error.
 */
function clockOut($userId, $lat = null, $lng = null, $notes = null) {
    $entry = getActiveClockEntry($userId);
    if (!$entry) {
        throw new Exception('Not currently clocked in');
    }

    $db = getDB();
    // Use MySQL NOW() so all stored timestamps are consistently in the MySQL server timezone (EST).
    $stmt = $db->prepare("
        UPDATE time_clock_entries
        SET clock_out = NOW(),
            clock_out_lat = ?,
            clock_out_lng = ?,
            notes = ?,
            total_minutes = TIMESTAMPDIFF(MINUTE, clock_in, NOW()),
            status = 'completed'
        WHERE id = ?
    ");
    $stmt->execute([$lat, $lng, $notes, $entry['id']]);

    // Insert a final GPS ping on clock-out so the map shows the last known position.
    if ($lat && $lng) {
        $trackStmt = $db->prepare("SELECT location_tracking_enabled FROM users WHERE id = ?");
        $trackStmt->execute([$userId]);
        $trackRow = $trackStmt->fetch(PDO::FETCH_ASSOC);
        if ($trackRow && $trackRow['location_tracking_enabled']) {
            $db->prepare("
                INSERT INTO crew_location_history (crew_id, latitude, longitude, accuracy_meters, visit_id, timestamp)
                VALUES (?, ?, ?, NULL, NULL, NOW())
            ")->execute([$userId, $lat, $lng]);
        }
    }

    // Get the calculated total
    $result = $db->prepare("SELECT total_minutes FROM time_clock_entries WHERE id = ?");
    $result->execute([$entry['id']]);
    $row = $result->fetch(PDO::FETCH_ASSOC);

    // Ensure/create timesheet for this week (use Pacific date)
    ensureTimesheetExists($userId, date('Y-m-d'));

    return (int)$row['total_minutes'];
}

/**
 * Get the active job time entry for a user (currently running timer)
 */
function getActiveJobTimer($userId) {
    $db = getDB();
    $stmt = $db->prepare("
        SELECT jte.*, jp.title as job_title, jv.visit_number as job_number,
               p.address as property_address, c.company_name,
               TIMESTAMPDIFF(SECOND, jte.start_time, NOW()) AS elapsed_seconds
        FROM job_time_entries jte
        JOIN job_visits jv ON jte.visit_id = jv.id
        JOIN job_plans jp ON jv.plan_id = jp.id
        LEFT JOIN properties p ON jp.property_id = p.id
        LEFT JOIN company_properties cprop ON jp.property_id = cprop.property_id AND cprop.is_primary = 1
        LEFT JOIN companies c ON cprop.company_id = c.id
        WHERE jte.user_id = ? AND jte.status = 'active' AND jte.end_time IS NULL
        ORDER BY jte.start_time DESC
        LIMIT 1
    ");
    $stmt->execute([$userId]);
    return $stmt->fetch(PDO::FETCH_ASSOC);
}

/**
 * Start a job timer. Returns the new job_time_entries ID.
 */
function startJobTimer($jobId, $userId, $lat = null, $lng = null, $autoStarted = false) {
    $db = getDB();

    // Check visit exists and is assigned to this user (or user is admin/manager)
    $stmt = $db->prepare("SELECT jv.id, jv.assigned_crew_id, jv.status FROM job_visits jv WHERE jv.id = ?");
    $stmt->execute([$jobId]);
    $job = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$job) {
        throw new Exception('Job not found');
    }

    // Check no active timer already running for this visit by this user
    $existingStmt = $db->prepare("
        SELECT id FROM job_time_entries
        WHERE visit_id = ? AND user_id = ? AND status = 'active' AND end_time IS NULL
    ");
    $existingStmt->execute([$jobId, $userId]);
    if ($existingStmt->fetch()) {
        throw new Exception('Timer already running for this job');
    }

    // Link to active clock entry if user is clocked in
    $clockEntry = getActiveClockEntry($userId);
    $clockEntryId = $clockEntry ? $clockEntry['id'] : null;

    // Create time entry — use MySQL NOW() so all stored timestamps are consistently in MySQL TZ (EST).
    // The plan_time_log API converts EST→Pacific for display using toPacific().
    $stmt = $db->prepare("
        INSERT INTO job_time_entries (visit_id, user_id, clock_entry_id, start_time, start_lat, start_lng, auto_started)
        VALUES (?, ?, ?, NOW(), ?, ?, ?)
    ");
    $stmt->execute([$jobId, $userId, $clockEntryId, $lat, $lng, $autoStarted ? 1 : 0]);
    $entryId = (int)$db->lastInsertId();

    // Update visit status to in_progress if currently scheduled
    if ($job['status'] === 'scheduled') {
        if (function_exists('updateVisitStatus')) {
            updateVisitStatus($jobId, 'in_progress', $userId, $autoStarted ? 'Auto-started via GPS proximity' : 'Timer started manually');
        }
    }

    // Log crew location if GPS available
    if ($lat && $lng) {
        $GLOBALS['user'] = ['id' => $userId];
        if (function_exists('logCrewLocation')) {
            logCrewLocation($lat, $lng, $jobId);
        }
    }

    return $entryId;
}

/**
 * Stop a job timer. Returns duration_minutes.
 */
function stopJobTimer($jobId, $userId, $lat = null, $lng = null, $notes = null, $completeJob = true) {
    $db = getDB();

    // Find active timer
    $stmt = $db->prepare("
        SELECT id FROM job_time_entries
        WHERE visit_id = ? AND user_id = ? AND status = 'active' AND end_time IS NULL
        ORDER BY start_time DESC LIMIT 1
    ");
    $stmt->execute([$jobId, $userId]);
    $entry = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$entry) {
        throw new Exception('No active timer for this job');
    }

    // Stop the timer — use MySQL NOW() for consistency with start_time storage (both EST).
    $stmt = $db->prepare("
        UPDATE job_time_entries
        SET end_time = NOW(),
            duration_minutes = TIMESTAMPDIFF(MINUTE, start_time, NOW()),
            end_lat = ?,
            end_lng = ?,
            notes = ?,
            status = 'completed'
        WHERE id = ?
    ");
    $stmt->execute([$lat, $lng, $notes, $entry['id']]);

    // Get duration
    $result = $db->prepare("SELECT duration_minutes FROM job_time_entries WHERE id = ?");
    $result->execute([$entry['id']]);
    $row = $result->fetch(PDO::FETCH_ASSOC);
    $duration = (int)$row['duration_minutes'];

    // Complete the visit if requested
    if ($completeJob) {
        if (function_exists('updateVisitStatus')) {
            updateVisitStatus($jobId, 'completed', $userId, $notes);
        }

        // Capture labor cost + margin snapshot at completion time
        $vcService = APP_ROOT . '/Modules/Jobs/Services/VisitCompletionService.php';
        if (file_exists($vcService)) {
            require_once $vcService;
            VisitCompletionService::capture((int)$jobId, (int)$userId);
        }
    }

    // Update property visit pattern
    $jobStmt = $db->prepare("SELECT jp.property_id FROM job_visits jv JOIN job_plans jp ON jv.plan_id = jp.id WHERE jv.id = ?");
    $jobStmt->execute([$jobId]);
    $jobRow = $jobStmt->fetch(PDO::FETCH_ASSOC);
    if ($jobRow && $jobRow['property_id'] && function_exists('updatePropertyVisitPattern')) {
        updatePropertyVisitPattern($jobRow['property_id'], $userId, $duration);
    }

    // Log GPS departure
    if ($lat && $lng) {
        $GLOBALS['user'] = ['id' => $userId];
        if (function_exists('logCrewLocation')) {
            logCrewLocation($lat, $lng, $jobId);
        }
    }

    // Ensure timesheet exists
    ensureTimesheetExists($userId, date('Y-m-d'));

    return $duration;
}

/**
 * Pause a job timer (stop timer but keep job in_progress)
 */
function pauseJobTimer($jobId, $userId, $lat = null, $lng = null) {
    return stopJobTimer($jobId, $userId, $lat, $lng, 'Paused', false);
}

// --- Visit-based aliases (job_plans/job_visits migration) ---

/** @see getActiveJobTimer() — alias using visit terminology */
function getActiveVisitTimer($userId) {
    return getActiveJobTimer($userId);
}

/** @see startJobTimer() — alias using visit terminology */
function startVisitTimer($visitId, $userId, $lat = null, $lng = null, $autoStarted = false) {
    return startJobTimer($visitId, $userId, $lat, $lng, $autoStarted);
}

/** @see stopJobTimer() — alias using visit terminology */
function stopVisitTimer($visitId, $userId, $lat = null, $lng = null, $notes = null, $completeVisit = true) {
    return stopJobTimer($visitId, $userId, $lat, $lng, $notes, $completeVisit);
}

/** @see pauseJobTimer() — alias using visit terminology */
function pauseVisitTimer($visitId, $userId, $lat = null, $lng = null) {
    return pauseJobTimer($visitId, $userId, $lat, $lng);
}

/**
 * Ensure a timesheet record exists for the given user and date's week
 */
function ensureTimesheetExists($userId, $date) {
    $db = getDB();

    // Calculate Monday of the week
    $weekStart = date('Y-m-d', strtotime('monday this week', strtotime($date)));
    $weekEnd = date('Y-m-d', strtotime($weekStart . ' +6 days'));

    // Check if exists
    $stmt = $db->prepare("SELECT id FROM timesheets WHERE user_id = ? AND week_start = ?");
    $stmt->execute([$userId, $weekStart]);
    if ($stmt->fetch()) {
        // Already exists — recalculate totals
        recalculateTimesheetTotals($userId, $weekStart);
        return;
    }

    // Create new timesheet
    $stmt = $db->prepare("
        INSERT INTO timesheets (user_id, week_start, week_end, status)
        VALUES (?, ?, ?, 'pending')
    ");
    $stmt->execute([$userId, $weekStart, $weekEnd]);

    recalculateTimesheetTotals($userId, $weekStart);
}

/**
 * Recalculate timesheet totals from raw clock + job entries
 */
function recalculateTimesheetTotals($userId, $weekStart) {
    $db = getDB();
    $weekEnd = date('Y-m-d', strtotime($weekStart . ' +6 days'));

    // Total shift minutes from clock entries
    $stmt = $db->prepare("
        SELECT COALESCE(SUM(total_minutes), 0) as total
        FROM time_clock_entries
        WHERE user_id = ?
          AND DATE(clock_in) BETWEEN ? AND ?
          AND status IN ('completed', 'edited')
    ");
    $stmt->execute([$userId, $weekStart, $weekEnd]);
    $shiftMinutes = (int)$stmt->fetch(PDO::FETCH_ASSOC)['total'];

    // Total job minutes from job time entries
    $stmt = $db->prepare("
        SELECT COALESCE(SUM(duration_minutes), 0) as total
        FROM job_time_entries
        WHERE user_id = ?
          AND DATE(start_time) BETWEEN ? AND ?
          AND status IN ('completed', 'edited')
    ");
    $stmt->execute([$userId, $weekStart, $weekEnd]);
    $jobMinutes = (int)$stmt->fetch(PDO::FETCH_ASSOC)['total'];

    // Travel = shift time minus job time (rough approximation)
    $travelMinutes = max(0, $shiftMinutes - $jobMinutes);

    $stmt = $db->prepare("
        UPDATE timesheets
        SET total_shift_minutes = ?,
            total_job_minutes = ?,
            total_travel_minutes = ?,
            updated_at = NOW()
        WHERE user_id = ? AND week_start = ?
    ");
    $stmt->execute([$shiftMinutes, $jobMinutes, $travelMinutes, $userId, $weekStart]);
}

/**
 * Get ALL jobs for a given date (all crews), for GPS proximity detection.
 * Any crew member near any property should be able to clock in, not just assigned crew.
 */
function getAllJobsForDate($date) {
    $db = getDB();
    $stmt = $db->prepare("
        SELECT jv.id, jv.visit_number as job_number, jv.status, jv.scheduled_date,
               jv.scheduled_time_start, jv.scheduled_time_end, jv.assigned_crew_id,
               jp.title, jp.service_type, jp.estimated_duration_minutes,
               p.address as property_address, p.city as property_city,
               p.latitude as property_lat, p.longitude as property_lng,
               c.company_name
        FROM job_visits jv
        JOIN job_plans jp ON jv.plan_id = jp.id
        LEFT JOIN properties p ON jp.property_id = p.id
        LEFT JOIN company_properties cprop ON jp.property_id = cprop.property_id AND cprop.is_primary = 1
        LEFT JOIN companies c ON cprop.company_id = c.id
        WHERE jv.scheduled_date = ?
          AND jv.status IN ('scheduled', 'in_progress')
        ORDER BY jv.scheduled_time_start ASC
    ");
    $stmt->execute([$date]);
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

/**
 * Get today's jobs for a specific user.
 * Includes assigned jobs + any non-assigned jobs where the user has an active timer
 * (e.g., they clocked in via GPS proximity at another crew's property).
 */
function getUserJobsForDate($userId, $date) {
    $db = getDB();
    $stmt = $db->prepare("
        SELECT jv.id, jv.visit_number as job_number, jv.status, jv.scheduled_date,
               jv.scheduled_time_start, jv.scheduled_time_end,
               jp.title, jp.service_type, jp.estimated_duration_minutes,
               p.address as property_address, p.city as property_city,
               p.latitude as property_lat, p.longitude as property_lng,
               c.company_name,
               jte.id as active_timer_id, jte.start_time as timer_start_time,
               TIMESTAMPDIFF(SECOND, jte.start_time, NOW()) AS timer_elapsed_seconds
        FROM job_visits jv
        JOIN job_plans jp ON jv.plan_id = jp.id
        LEFT JOIN properties p ON jp.property_id = p.id
        LEFT JOIN company_properties cprop ON jp.property_id = cprop.property_id AND cprop.is_primary = 1
        LEFT JOIN companies c ON cprop.company_id = c.id
        LEFT JOIN job_time_entries jte ON jte.visit_id = jv.id
            AND jte.user_id = ? AND jte.status = 'active' AND jte.end_time IS NULL
        WHERE jv.scheduled_date = ?
          AND jv.status IN ('scheduled', 'in_progress', 'completed')
          AND (
              jv.assigned_crew_id = ?
              OR jte.id IS NOT NULL
          )
        ORDER BY jv.scheduled_time_start ASC
    ");
    $stmt->execute([$userId, $date, $userId]);
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

/**
 * Get clock entries for a user within a date range
 */
function getClockEntriesForRange($userId, $startDate, $endDate) {
    $db = getDB();
    $stmt = $db->prepare("
        SELECT *
        FROM time_clock_entries
        WHERE user_id = ?
          AND DATE(clock_in) BETWEEN ? AND ?
          AND status IN ('active', 'completed', 'edited')
        ORDER BY clock_in ASC
    ");
    $stmt->execute([$userId, $startDate, $endDate]);
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

/**
 * Get job time entries for a user within a date range
 */
function getJobTimeEntriesForRange($userId, $startDate, $endDate) {
    $db = getDB();
    $stmt = $db->prepare("
        SELECT jte.*, jp.title as job_title, jv.visit_number as job_number,
               p.address as property_address, c.company_name
        FROM job_time_entries jte
        JOIN job_visits jv ON jte.visit_id = jv.id
        JOIN job_plans jp ON jv.plan_id = jp.id
        LEFT JOIN properties p ON jp.property_id = p.id
        LEFT JOIN company_properties cprop ON jp.property_id = cprop.property_id AND cprop.is_primary = 1
        LEFT JOIN companies c ON cprop.company_id = c.id
        WHERE jte.user_id = ?
          AND DATE(jte.start_time) BETWEEN ? AND ?
          AND jte.status IN ('completed', 'edited')
        ORDER BY jte.start_time ASC
    ");
    $stmt->execute([$userId, $startDate, $endDate]);
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

/**
 * Haversine distance between two lat/lng points, in meters.
 */
function haversineDistance(float $lat1, float $lng1, float $lat2, float $lng2): float {
    $R = 6371000; // Earth radius in meters
    $dLat = deg2rad($lat2 - $lat1);
    $dLng = deg2rad($lng2 - $lng1);
    $a = sin($dLat / 2) * sin($dLat / 2)
       + cos(deg2rad($lat1)) * cos(deg2rad($lat2))
       * sin($dLng / 2) * sin($dLng / 2);
    return $R * 2 * atan2(sqrt($a), sqrt(1 - $a));
}

/**
 * Check if user is within proximity of a scheduled visit and auto-start the timer.
 * Called by crew-location.php on GPS pings and by the one-shot proximity check.
 *
 * @return array|null  Null if no auto-start; otherwise array with visit details
 */
function checkProximityAutoStart(int $userId, float $lat, float $lng, float $accuracy = 50.0): ?array {
    // Guard 1: master toggle
    $autoArrivalEnabled = getTimeClockSetting('auto_arrival_enabled', '1');
    if ($autoArrivalEnabled !== '1') {
        return null;
    }

    $proximityMeters = (int)getTimeClockSetting('gps_proximity_meters', '150');

    // Guard 2: GPS accuracy — skip if too inaccurate
    if ($accuracy > $proximityMeters * 1.5) {
        return null;
    }

    // Guard 3: no active job timer
    $activeTimer = getActiveJobTimer($userId);
    if ($activeTimer) {
        return null;
    }

    // Guard 4: cooldown — skip if auto-started in last 5 minutes
    $db = getDB();
    $cooldownStmt = $db->prepare("
        SELECT id FROM job_time_entries
        WHERE user_id = ? AND auto_started = 1
          AND start_time > DATE_SUB(NOW(), INTERVAL 5 MINUTE)
        LIMIT 1
    ");
    $cooldownStmt->execute([$userId]);
    if ($cooldownStmt->fetch()) {
        return null;
    }

    // Guard 5: get today's visits (use session cache, 60s TTL)
    $cacheKey = 'proximity_visits_cache';
    $cacheTsKey = 'proximity_visits_cache_ts';
    $today = date('Y-m-d');

    if (isset($_SESSION[$cacheKey], $_SESSION[$cacheTsKey])
        && $_SESSION[$cacheTsKey] > time() - 60
        && ($_SESSION['proximity_visits_date'] ?? '') === $today) {
        $allVisits = $_SESSION[$cacheKey];
    } else {
        $allVisits = getAllJobsForDate($today);
        $_SESSION[$cacheKey] = $allVisits;
        $_SESSION[$cacheTsKey] = time();
        $_SESSION['proximity_visits_date'] = $today;
    }

    // Guard 6+7: find nearest scheduled visit within proximity
    // Enhanced: if the property has an arrival border polygon, use point-in-polygon
    // instead of the 150m haversine circle (more accurate for large multi-address sites).
    $allowedTypesStr = getTimeClockSetting('auto_arrival_service_types', '');
    $allowedTypes = array_filter(array_map('trim', explode(',', $allowedTypesStr)));

    $nearest     = null;
    $nearestDist = PHP_FLOAT_MAX;

    // Pre-load arrival borders per property to avoid per-visit queries in the loop
    $arrivalBorderCache = [];
    $geofenceModelPath  = null;
    foreach ([APP_ROOT . '/Modules/Geofence/Models/GeofenceModel.php',
              dirname(dirname(dirname(__DIR__))) . '/app/Modules/Geofence/Models/GeofenceModel.php'] as $p) {
        if (is_file($p)) { $geofenceModelPath = $p; break; }
    }
    if ($geofenceModelPath) {
        require_once $geofenceModelPath;
    }

    foreach ($allVisits as $visit) {
        if ($visit['status'] !== 'scheduled') continue;

        $vLat      = $visit['property_lat'] ?? null;
        $vLng      = $visit['property_lng'] ?? null;
        $propertyId = isset($visit['property_id']) ? (int)$visit['property_id'] : 0;

        if (!$vLat || !$vLng) continue;

        // Check arrival border polygon if one exists for this property
        $insideBorder = false;
        if ($propertyId && $geofenceModelPath && function_exists('geofenceGetArrivalBorder')) {
            if (!array_key_exists($propertyId, $arrivalBorderCache)) {
                $arrivalBorderCache[$propertyId] = geofenceGetArrivalBorder($propertyId);
            }
            $border = $arrivalBorderCache[$propertyId];
            if ($border && !empty($border['polygon'])) {
                $insideBorder = geofencePointInPolygon($lat, $lng, $border['polygon'], $border['bbox']);
            }
        }

        if ($insideBorder) {
            // Polygon match is definitive — treat as distance 0 so it always wins
            $nearestDist = 0;
            $nearest     = $visit;
            break; // first polygon match wins (already inside the site)
        }

        // Fallback: haversine distance check (no arrival border drawn yet)
        $dist = haversineDistance($lat, $lng, (float)$vLat, (float)$vLng);
        if ($dist < $nearestDist) {
            $nearestDist = $dist;
            $nearest     = $visit;
        }
    }

    if (!$nearest || $nearestDist > $proximityMeters) {
        return null;
    }

    // Guard 8: service type check — global allowlist OR per-product auto_clock_in flag
    $serviceType = $nearest['service_type'] ?? '';
    $inGlobalList = !empty($allowedTypes) && in_array($serviceType, $allowedTypes);

    $hasPerVisitFlag = false;
    // Check per-product/plan auto_clock_in via resolveTrackingRequirements
    $visitId = (int)$nearest['id'];
    if (function_exists('resolveTrackingRequirements')) {
        $trackReqs = resolveTrackingRequirements($visitId);
        $hasPerVisitFlag = !empty($trackReqs['auto_clock_in']);
    }

    if (!$inGlobalList && !$hasPerVisitFlag) {
        return null;
    }

    // Guard 9: visit not already auto-started today
    $alreadyStmt = $db->prepare("
        SELECT id FROM job_time_entries
        WHERE visit_id = ? AND auto_started = 1 AND DATE(start_time) = CURDATE()
        LIMIT 1
    ");
    $alreadyStmt->execute([$visitId]);
    if ($alreadyStmt->fetch()) {
        return null;
    }

    // Check clock-in state — timer starts regardless, but flag if not clocked in
    // so the client can prompt the crew to clock in globally.
    $needsClockIn = !getActiveClockEntry($userId);

    // Start the visit timer
    try {
        $entryId = startJobTimer($visitId, $userId, $lat, $lng, true);
    } catch (Exception $e) {
        // Timer already running (race condition) — bail
        return null;
    }

    // Invalidate session cache so next check sees the updated visit status
    unset($_SESSION[$cacheKey]);

    return [
        'visit_id'         => $visitId,
        'job_title'        => $nearest['title'] ?? '',
        'job_number'       => $nearest['job_number'] ?? '',
        'property_address' => $nearest['property_address'] ?? '',
        'service_type'     => $serviceType,
        'distance_meters'  => (int)round($nearestDist),
        'entry_id'         => $entryId,
        'needs_clock_in'   => $needsClockIn,
    ];
}

/**
 * Format minutes as hours:minutes string (e.g., "7h 30m")
 */
function formatMinutesAsHours($minutes) {
    if ($minutes === null || $minutes < 0) return '0h 0m';
    $h = floor($minutes / 60);
    $m = $minutes % 60;
    return $h . 'h ' . $m . 'm';
}

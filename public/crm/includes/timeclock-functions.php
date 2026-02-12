<?php
/**
 * Time Clock & Job Timer Helper Functions
 * /crm/includes/timeclock-functions.php
 */

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
    $stmt = $db->prepare("
        INSERT INTO time_clock_entries (user_id, clock_in, clock_in_lat, clock_in_lng)
        VALUES (?, NOW(), ?, ?)
    ");
    $stmt->execute([$userId, $lat, $lng]);
    return (int)$db->lastInsertId();
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

    // Get the calculated total
    $result = $db->prepare("SELECT total_minutes FROM time_clock_entries WHERE id = ?");
    $result->execute([$entry['id']]);
    $row = $result->fetch(PDO::FETCH_ASSOC);

    // Ensure/create timesheet for this week
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

    // Create time entry
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

    // Stop the timer
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
 * Get today's jobs for a specific user
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
               jte.id as active_timer_id, jte.start_time as timer_start_time
        FROM job_visits jv
        JOIN job_plans jp ON jv.plan_id = jp.id
        LEFT JOIN properties p ON jp.property_id = p.id
        LEFT JOIN company_properties cprop ON jp.property_id = cprop.property_id AND cprop.is_primary = 1
        LEFT JOIN companies c ON cprop.company_id = c.id
        LEFT JOIN job_time_entries jte ON jte.visit_id = jv.id
            AND jte.user_id = ? AND jte.status = 'active' AND jte.end_time IS NULL
        WHERE jv.assigned_crew_id = ?
          AND jv.scheduled_date = ?
          AND jv.status IN ('scheduled', 'in_progress', 'completed')
        ORDER BY jv.scheduled_time_start ASC
    ");
    $stmt->execute([$userId, $userId, $date]);
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
 * Format minutes as hours:minutes string (e.g., "7h 30m")
 */
function formatMinutesAsHours($minutes) {
    if ($minutes === null || $minutes < 0) return '0h 0m';
    $h = floor($minutes / 60);
    $m = $minutes % 60;
    return $h . 'h ' . $m . 'm';
}

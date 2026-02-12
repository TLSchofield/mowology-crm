<?php
/**
 * Rescheduler Module
 * /crm/modules/scheduling/rescheduler.php
 *
 * Finds alternative time slots for visits flagged by weather evaluation.
 * Executes reschedules via the existing moveVisit() function.
 * Logs all actions to weather_action_log for dedup.
 *
 * Dependencies:
 *   - /crm/includes/plan-functions.php (moveVisit, ensureCalendarStop)
 *   - /crm/includes/weather-service.php (getHourlyForecastWindow)
 *   - /crm/modules/weather/weather-evaluator.php (evaluateVisitWeather)
 *   - /crm/modules/weather/weather-rules.php (aggregateVisitRules)
 */

declare(strict_types=1);

/**
 * Find an alternate time slot for a visit within the allowed move window.
 * Searches forward from the original date, checking weather and conflicts.
 *
 * @param int   $visitId    Visit to reschedule
 * @param array $visitRule  Aggregated weather rules
 * @param array $visitData  Visit record (scheduled_date, scheduled_time_start, etc.)
 * @return array|null Suggested slot or null if none found:
 *   ['date' => 'YYYY-MM-DD', 'time_start' => 'HH:MM', 'time_end' => 'HH:MM', 'weather_status' => 'OK']
 */
function findAlternateSlot(int $visitId, array $visitRule, array $visitData): ?array
{
    $moveWindowHours = (int)($visitRule['move_window_hours'] ?? 48);
    $timebandStart   = $visitRule['move_timeband_start'] ?? '07:00';
    $timebandEnd     = $visitRule['move_timeband_end'] ?? '18:00';

    $originalDate = $visitData['scheduled_date'];
    $timeStart    = $visitData['scheduled_time_start'] ?? '08:00';
    $timeEnd      = $visitData['scheduled_time_end'] ?? '17:00';
    $crewId       = $visitData['assigned_crew_id'] ?? null;

    // Normalize times to HH:MM
    if (strlen($timeStart) > 5) $timeStart = substr($timeStart, 0, 5);
    if (strlen($timeEnd) > 5) $timeEnd = substr($timeEnd, 0, 5);

    // Calculate visit duration in minutes for slot sizing
    $startMinutes = timeToMinutes($timeStart);
    $endMinutes   = timeToMinutes($timeEnd);
    $durationMin  = max(60, $endMinutes - $startMinutes);

    // Search window: from tomorrow up to move_window_hours forward
    $moveWindowDays = max(1, (int)ceil($moveWindowHours / 24));
    $searchStart = new DateTime($originalDate);
    $searchStart->modify('+1 day');
    $searchEnd = new DateTime($originalDate);
    $searchEnd->modify("+{$moveWindowDays} days");

    // Get property coordinates from visit data
    $lat = (float)($visitData['latitude'] ?? 0);
    $lon = (float)($visitData['longitude'] ?? 0);

    if ($lat === 0.0 && $lon === 0.0) {
        return null;
    }

    $current = clone $searchStart;
    while ($current <= $searchEnd) {
        $candidateDate = $current->format('Y-m-d');

        // Check if crew has schedule capacity on this date
        if ($crewId && hasScheduleConflict((int)$crewId, $candidateDate, $timebandStart, $timebandEnd, $durationMin)) {
            $current->modify('+1 day');
            continue;
        }

        // Evaluate weather for candidate slot
        $candidateStart = $timebandStart;
        $candidateEndTime = minutesToTime(timeToMinutes($timebandStart) + $durationMin);
        if ($candidateEndTime > $timebandEnd) {
            $candidateEndTime = $timebandEnd;
        }

        require_once dirname(dirname(__DIR__)) . '/includes/weather-service.php';
        $hourlyWindow = getHourlyForecastWindow($lat, $lon, $candidateDate, $candidateStart, $candidateEndTime);

        if (!empty($hourlyWindow)) {
            $eval = evaluateVisitWeather($visitRule, $hourlyWindow, $candidateStart, $candidateEndTime);

            if ($eval['status'] === 'OK') {
                return [
                    'date'           => $candidateDate,
                    'time_start'     => $candidateStart,
                    'time_end'       => $candidateEndTime,
                    'weather_status' => 'OK',
                    'weather_eval'   => $eval,
                ];
            }
        }

        $current->modify('+1 day');
    }

    return null;
}

/**
 * Execute a reschedule: move the visit and log the action.
 *
 * @param int    $visitId
 * @param string $newDate
 * @param string $newTimeStart
 * @param int    $userId       User performing the action (0 for cron)
 * @param string $reason       Reason for reschedule
 * @return array ['success' => bool, 'error' => string|null]
 */
function executeReschedule(int $visitId, string $newDate, string $newTimeStart, int $userId, string $reason = 'Weather'): array
{
    require_once dirname(dirname(__DIR__)) . '/includes/plan-functions.php';

    try {
        $success = moveVisit($visitId, $newDate, $newTimeStart, $userId);

        if ($success) {
            // Log to weather_action_log (dedup via unique key)
            logWeatherAction('VISIT_RESCHEDULE', 'visit', $visitId, json_encode([
                'new_date'       => $newDate,
                'new_time_start' => $newTimeStart,
                'reason'         => $reason,
                'moved_by'       => $userId > 0 ? 'user' : 'cron',
            ]), $userId > 0 ? $userId : null);

            return ['success' => true, 'error' => null];
        }

        return ['success' => false, 'error' => 'moveVisit() returned false — visit may not be in scheduled status'];
    } catch (Throwable $e) {
        error_log("executeReschedule error: " . $e->getMessage());
        return ['success' => false, 'error' => $e->getMessage()];
    }
}

/**
 * Check if a crew has a schedule conflict on a given date/time.
 *
 * @param int    $crewId
 * @param string $date
 * @param string $timeStart
 * @param string $timeEnd
 * @param int    $durationMin
 * @return bool True if conflict exists
 */
function hasScheduleConflict(int $crewId, string $date, string $timeStart, string $timeEnd, int $durationMin): bool
{
    try {
        $db = getDB();

        // Count existing scheduled visits for this crew on this date
        $stmt = $db->prepare("
            SELECT COUNT(*) AS cnt
            FROM job_visits v
            JOIN job_plans p ON v.plan_id = p.id
            WHERE v.assigned_crew_id = ?
              AND v.scheduled_date = ?
              AND v.status = 'scheduled'
        ");
        $stmt->execute([$crewId, $date]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        // Simple heuristic: if crew already has 8+ visits, day is full
        return ((int)($row['cnt'] ?? 0)) >= 8;
    } catch (Throwable $e) {
        error_log("hasScheduleConflict error: " . $e->getMessage());
        return false;
    }
}

// ============================================================================
// WEATHER ACTION LOG
// ============================================================================

/**
 * Log a weather action (dedup via unique key on date+type+entity).
 *
 * @param string   $actionType  e.g., VISIT_RESCHEDULE, ALERT_SENT, WEATHER_EVAL
 * @param string   $entityType  e.g., visit, event
 * @param int      $entityId
 * @param string   $details     JSON details
 * @param int|null $createdBy   User ID or null for system
 * @return bool
 */
function logWeatherAction(string $actionType, string $entityType, int $entityId, string $details = '', ?int $createdBy = null): bool
{
    try {
        $db = getDB();
        $stmt = $db->prepare("
            INSERT IGNORE INTO weather_action_log
                (action_date, action_type, entity_type, entity_id, details, created_by)
            VALUES
                (CURDATE(), ?, ?, ?, ?, ?)
        ");
        return $stmt->execute([$actionType, $entityType, $entityId, $details, $createdBy]);
    } catch (Throwable $e) {
        error_log("logWeatherAction error: " . $e->getMessage());
        return false;
    }
}

/**
 * Check if a weather action has already been logged today (dedup check).
 *
 * @param string $actionType
 * @param string $entityType
 * @param int    $entityId
 * @return bool
 */
function hasWeatherActionToday(string $actionType, string $entityType, int $entityId): bool
{
    try {
        $db = getDB();
        $stmt = $db->prepare("
            SELECT COUNT(*) AS cnt
            FROM weather_action_log
            WHERE action_date = CURDATE()
              AND action_type = ?
              AND entity_type = ?
              AND entity_id = ?
        ");
        $stmt->execute([$actionType, $entityType, $entityId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return ((int)($row['cnt'] ?? 0)) > 0;
    } catch (Throwable $e) {
        return false;
    }
}

// ============================================================================
// TIME HELPERS
// ============================================================================

function timeToMinutes(string $time): int
{
    $parts = explode(':', $time);
    return ((int)$parts[0]) * 60 + ((int)($parts[1] ?? 0));
}

function minutesToTime(int $minutes): string
{
    $h = (int)floor($minutes / 60);
    $m = $minutes % 60;
    return sprintf('%02d:%02d', min($h, 23), $m);
}

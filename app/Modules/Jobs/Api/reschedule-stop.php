<?php
/**
 * Reschedule Calendar Stop API
 * Moves a stop (and all its linked scheduled visits) to a new date/time.
 *
 * POST JSON: { stop_id, new_date, new_route_order?, new_time? }
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
    require_once CRM_INCLUDES . '/plan-functions.php';
    require_once CRM_INCLUDES . '/weather-service.php';
    require_once dirname(CRM_INCLUDES) . '/modules/weather/weather-rules.php';

    requireLogin();
    $user = getCurrentUser();

    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        throw new Exception('Method not allowed');
    }

    // Get input
    $input = json_decode(file_get_contents('php://input'), true);

    // Must run before session_write_close() below — verifyCSRFToken() reads
    // $_SESSION, which session_write_close() clears from memory.
    $csrfToken = $input['csrf_token'] ?? $_POST['csrf_token'] ?? $_SERVER['HTTP_X_CSRF_TOKEN'] ?? '';
    if (!verifyCSRFToken($csrfToken)) {
        throw new Exception('Invalid CSRF token');
    }

    session_write_close(); // release session lock — no session writes needed beyond this point

    if (!$input || !isset($input['stop_id']) || !isset($input['new_date'])) {
        throw new Exception('Missing required fields: stop_id, new_date');
    }

    $stopId = (int)$input['stop_id'];
    $newDate = $input['new_date'];
    $newRouteOrder = isset($input['new_route_order']) ? (int)$input['new_route_order'] : null;
    $newTime = $input['new_time'] ?? null;
    $force = !empty($input['force']);

    // Validate date format
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $newDate)) {
        throw new Exception('Invalid date format');
    }

    if ($newTime && !preg_match('/^\d{2}:\d{2}(:\d{2})?$/', $newTime)) {
        throw new Exception('Invalid time format');
    }

    $db = getDB();

    // Get current stop
    $stmt = $db->prepare("SELECT * FROM calendar_stops WHERE id = ?");
    $stmt->execute([$stopId]);
    $stop = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$stop) {
        throw new Exception('Stop not found');
    }

    $oldDate = $stop['stop_date'];

    // ── Capacity warning (before transaction) ────────────────────────────────
    // If moving to a different date AND the crew is set, check if the day
    // would exceed 540 min (9-hour day) after adding this stop's visits.
    // Return a soft warning so the client can ask for confirmation (force=1).
    if (!$force && $newDate !== $oldDate && !empty($stop['crew_id'])) {
        $crewId = (int)$stop['crew_id'];

        // Minutes already scheduled for this crew on the target date (excluding this stop)
        $capStmt = $db->prepare("
            SELECT COALESCE(SUM(jp.estimated_duration_minutes), 0) AS existing_min
            FROM calendar_stops cs
            JOIN job_visits jv ON jv.stop_id = cs.id
                AND jv.status NOT IN ('cancelled', 'skipped', 'completed')
            JOIN job_plans jp ON jv.plan_id = jp.id
            WHERE cs.stop_date = ?
              AND cs.crew_id = ?
              AND cs.id != ?
        ");
        $capStmt->execute([$newDate, $crewId, $stopId]);
        $existingMin = (int)$capStmt->fetchColumn();

        // Minutes this stop will contribute
        $stopStmt = $db->prepare("
            SELECT COALESCE(SUM(jp.estimated_duration_minutes), 0) AS stop_min
            FROM job_visits jv
            JOIN job_plans jp ON jv.plan_id = jp.id
            WHERE jv.stop_id = ?
              AND jv.status NOT IN ('cancelled', 'skipped', 'completed')
        ");
        $stopStmt->execute([$stopId]);
        $stopMin = (int)$stopStmt->fetchColumn();

        $minutesAfter = $existingMin + $stopMin;
        if ($minutesAfter > 540) {
            $hoursAfter = round($minutesAfter / 60, 1);
            http_response_code(200);
            echo json_encode([
                'warning'      => true,
                'message'      => "Crew day is over capacity ({$hoursAfter}h) — continue?",
                'minutes_after'=> $minutesAfter,
            ]);
            exit;
        }
    }

    $db->beginTransaction();

    // Check if a stop already exists at the new date for this property+crew
    // If so, merge visits into existing stop
    $existingStmt = $db->prepare("
        SELECT id FROM calendar_stops
        WHERE property_id = ? AND stop_date = ? AND crew_id <=> ? AND id != ?
    ");
    $existingStmt->execute([$stop['property_id'], $newDate, $stop['crew_id'], $stopId]);
    $existingStop = $existingStmt->fetch(PDO::FETCH_ASSOC);

    if ($existingStop) {
        // Merge: move visits to existing stop, delete old stop
        $targetStopId = (int)$existingStop['id'];

        $stmt = $db->prepare("
            UPDATE job_visits
            SET stop_id = ?, scheduled_date = ?, updated_at = NOW()
            WHERE stop_id = ? AND status = 'scheduled'
        ");
        $stmt->execute([$targetStopId, $newDate, $stopId]);

        // Delete the now-empty old stop
        $stmt = $db->prepare("DELETE FROM calendar_stops WHERE id = ?");
        $stmt->execute([$stopId]);

        $resultStopId = $targetStopId;
    } else {
        // Move the stop itself
        $setClauses = ["stop_date = ?", "updated_at = NOW()"];
        $params = [$newDate];

        if ($newRouteOrder !== null) {
            $setClauses[] = "route_order = ?";
            $params[] = $newRouteOrder;
        }

        if ($newTime) {
            $setClauses[] = "estimated_arrival = ?";
            $params[] = $newTime;
        }

        $params[] = $stopId;

        $stmt = $db->prepare("
            UPDATE calendar_stops SET " . implode(', ', $setClauses) . " WHERE id = ?
        ");
        $stmt->execute($params);

        // Move all linked scheduled visits to new date
        $visitUpdate = "UPDATE job_visits SET scheduled_date = ?";
        $visitParams = [$newDate];

        if ($newTime) {
            $visitUpdate .= ", scheduled_time_start = ?";
            $visitParams[] = $newTime;
        }

        $visitUpdate .= ", updated_at = NOW() WHERE stop_id = ? AND status = 'scheduled'";
        $visitParams[] = $stopId;

        $stmt = $db->prepare($visitUpdate);
        $stmt->execute($visitParams);

        $resultStopId = $stopId;
    }

    $db->commit();

    // Log activity
    logActivityExtended(
        $user['id'],
        'Stop rescheduled',
        "Stop moved from {$oldDate} to {$newDate}",
        null, null, null, null, null, null
    );

    // Fresh Day Summary Card HTML for every affected day column, so the
    // client can patch the schedule in place instead of reloading the page.
    $dayCards = [];
    foreach (array_unique([$newDate, $oldDate]) as $renderDate) {
        $isToday = ($renderDate === date('Y-m-d'));
        $bcData  = computeDayBattleCard($renderDate);
        $dateStr = $renderDate; // expected by the partial
        ob_start();
        include PUBLIC_ROOT . '/crm/partials/day-summary-card.php';
        $dayCards[$renderDate] = ob_get_clean();
    }

    http_response_code(200);
    echo json_encode([
        'success' => true,
        'message' => 'Stop rescheduled successfully',
        'stop_id' => $resultStopId,
        'new_date' => $newDate,
        'new_route_order' => $newRouteOrder,
        'day_cards' => $dayCards
    ]);

} catch (Throwable $e) {
    if (isset($db) && $db->inTransaction()) {
        $db->rollBack();
    }
    error_log('reschedule-stop.php: ' . $e->getMessage() . ' in ' . $e->getFile() . ':' . $e->getLine());
    http_response_code(500);
    echo json_encode(['error' => $e->getMessage()]);
}

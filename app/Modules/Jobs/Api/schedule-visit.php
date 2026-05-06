<?php
/**
 * Schedule Unscheduled Visit API
 * Places a job_visit (currently without a calendar_stop) onto a specific date.
 * Optionally auto-assigns the current user as crew (for mobile crew members).
 *
 * POST JSON:
 *   visit_id        int   — required: job_visit.id
 *   date            string — required: YYYY-MM-DD
 *   route_order     int   — optional: position within day (default appends to end)
 *   auto_assign_self bool — optional: if true AND current user role = 'user', assign self as crew
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

    requireLogin();
    $user = getCurrentUser();
    session_write_close(); // writes to DB only — release session lock after auth

    // ── Parse input ──────────────────────────────────────────────────────────
    $input = json_decode(file_get_contents('php://input'), true);

    if (!$input || !isset($input['visit_id']) || !isset($input['date'])) {
        throw new Exception('Missing required fields: visit_id, date');
    }

    $visitId       = (int)$input['visit_id'];
    $targetDate    = trim($input['date']);
    $routeOrder    = isset($input['route_order']) ? (int)$input['route_order'] : null;
    $autoAssignSelf = !empty($input['auto_assign_self']) && ($user['role'] === 'user');

    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $targetDate)) {
        throw new Exception('Invalid date format — expected YYYY-MM-DD');
    }

    $db = getDB();

    // ── Load the visit ───────────────────────────────────────────────────────
    $stmt = $db->prepare("
        SELECT jv.id, jv.plan_id, jv.stop_id, jv.status, jv.scheduled_date,
               jp.property_id, jp.default_crew_id
        FROM job_visits jv
        JOIN job_plans jp ON jv.plan_id = jp.id
        WHERE jv.id = ? AND jp.status = 'active'
    ");
    $stmt->execute([$visitId]);
    $visit = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$visit) {
        throw new Exception('Visit not found or plan is not active');
    }
    if ($visit['stop_id'] !== null) {
        throw new Exception('Visit is already scheduled to a stop');
    }

    $propertyId = (int)$visit['property_id'];

    $db->beginTransaction();

    // ── Determine route_order if not supplied ────────────────────────────────
    if ($routeOrder === null) {
        $roStmt = $db->prepare("
            SELECT COALESCE(MAX(route_order), -1) + 1
            FROM calendar_stops
            WHERE stop_date = ?
        ");
        $roStmt->execute([$targetDate]);
        $routeOrder = (int)$roStmt->fetchColumn();
    }

    // ── Find or create a calendar_stop for this property + date ─────────────
    // Prefer an existing stop for the same property on the same date
    $existStmt = $db->prepare("
        SELECT id FROM calendar_stops
        WHERE property_id = ? AND stop_date = ?
        LIMIT 1
    ");
    $existStmt->execute([$propertyId, $targetDate]);
    $existingStop = $existStmt->fetch(PDO::FETCH_ASSOC);

    if ($existingStop) {
        $stopId = (int)$existingStop['id'];
    } else {
        // Determine crew_id: auto-assign self (mobile) or use plan default
        $crewId = null;
        if ($autoAssignSelf) {
            $crewId = (int)$user['id'];
        }

        $insStmt = $db->prepare("
            INSERT INTO calendar_stops (property_id, stop_date, route_order, crew_id, status, created_at, updated_at)
            VALUES (?, ?, ?, ?, 'scheduled', NOW(), NOW())
        ");
        $insStmt->execute([$propertyId, $targetDate, $routeOrder, $crewId]);
        $stopId = (int)$db->lastInsertId();

        // If auto-assigning, also populate the junction table
        if ($autoAssignSelf && $stopId > 0) {
            $jStmt = $db->prepare("
                INSERT IGNORE INTO calendar_stop_crew (stop_id, user_id)
                VALUES (?, ?)
            ");
            $jStmt->execute([$stopId, (int)$user['id']]);
        }
    }

    // ── Link the visit to this stop ──────────────────────────────────────────
    $updStmt = $db->prepare("
        UPDATE job_visits
        SET stop_id = ?, scheduled_date = ?, updated_at = NOW()
        WHERE id = ? AND stop_id IS NULL
    ");
    $updStmt->execute([$stopId, $targetDate, $visitId]);

    if ($updStmt->rowCount() === 0) {
        // Another request already scheduled this visit — roll back
        $db->rollBack();
        throw new Exception('Visit was already scheduled by a concurrent request');
    }

    // ── If stop existed (reused) and auto_assign_self, ensure crew is set ────
    if ($existingStop && $autoAssignSelf) {
        $checkCrew = $db->prepare("SELECT crew_id FROM calendar_stops WHERE id = ?");
        $checkCrew->execute([$stopId]);
        $existCrewId = $checkCrew->fetchColumn();
        if (!$existCrewId) {
            $db->prepare("UPDATE calendar_stops SET crew_id = ? WHERE id = ?")->execute([(int)$user['id'], $stopId]);
        }
        // Add to junction regardless (IGNORE = safe if already there)
        $db->prepare("INSERT IGNORE INTO calendar_stop_crew (stop_id, user_id) VALUES (?, ?)")
           ->execute([$stopId, (int)$user['id']]);
    }

    $db->commit();

    // ── Activity log ─────────────────────────────────────────────────────────
    try {
        logActivityExtended(
            $user['id'],
            'Visit scheduled',
            "Visit #{$visitId} assigned to stop #{$stopId} on {$targetDate}",
            null, null, null, null, null, null
        );
    } catch (Throwable $le) {
        // logging failure must not abort the response
    }

    http_response_code(200);
    echo json_encode([
        'success'       => true,
        'stop_id'       => $stopId,
        'visit_id'      => $visitId,
        'date'          => $targetDate,
        'crew_assigned' => $autoAssignSelf,
        'message'       => 'Visit scheduled' . ($autoAssignSelf ? ' and crew assigned' : ''),
    ]);

} catch (Exception $e) {
    if (isset($db) && $db->inTransaction()) {
        $db->rollBack();
    }
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
}

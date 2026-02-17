<?php
/**
 * Assign Crew to Calendar Stop API
 * Supports multi-crew assignment via the calendar_stop_crew junction table.
 * The first crew_id in the array becomes the "lead" crew on calendar_stops.crew_id.
 *
 * POST JSON: { stop_id, crew_ids: [1, 2, 3] }
 * Also accepts legacy format: { stop_id, crew_id } (single crew)
 * crew_ids can be empty array or [null] to unassign all.
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

    requireLogin();
    $user = getCurrentUser();

    $input = json_decode(file_get_contents('php://input'), true);

    if (!$input || !isset($input['stop_id'])) {
        throw new Exception('Missing required field: stop_id');
    }

    $stopId = (int)$input['stop_id'];

    // Accept crew_ids array (new) or crew_id (legacy)
    $crewIds = [];
    if (isset($input['crew_ids']) && is_array($input['crew_ids'])) {
        foreach ($input['crew_ids'] as $id) {
            if ($id !== null && $id !== '' && $id !== 0 && $id !== '0') {
                $crewIds[] = (int)$id;
            }
        }
        $crewIds = array_values(array_unique($crewIds));
    } elseif (isset($input['crew_id']) && $input['crew_id'] !== '' && $input['crew_id'] !== null) {
        $crewIds = [(int)$input['crew_id']];
    }

    // Lead crew = first in list (used for calendar_stops.crew_id, filtering, ordering)
    $leadCrewId = !empty($crewIds) ? $crewIds[0] : null;

    $db = getDB();

    // Get current stop
    $stmt = $db->prepare("SELECT id, crew_id, property_id, stop_date FROM calendar_stops WHERE id = ?");
    $stmt->execute([$stopId]);
    $stop = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$stop) {
        throw new Exception('Stop not found');
    }

    // Validate all crew members exist and are active
    $crewNames = [];
    if (!empty($crewIds)) {
        $placeholders = implode(',', array_fill(0, count($crewIds), '?'));
        $cStmt = $db->prepare("SELECT id, full_name FROM users WHERE id IN ({$placeholders}) AND is_active = 1");
        $cStmt->execute($crewIds);
        $foundUsers = $cStmt->fetchAll(PDO::FETCH_ASSOC);

        $foundIds = array_column($foundUsers, 'id');
        foreach ($crewIds as $cid) {
            if (!in_array($cid, $foundIds)) {
                throw new Exception("Crew member #{$cid} not found or inactive");
            }
        }

        // Build names in the same order as crewIds
        $userMap = [];
        foreach ($foundUsers as $u) {
            $userMap[(int)$u['id']] = $u['full_name'];
        }
        foreach ($crewIds as $cid) {
            $crewNames[] = $userMap[$cid];
        }
    }

    $crewDisplay = !empty($crewNames) ? implode(', ', $crewNames) : 'Unassigned';

    // Check for duplicate stop (same property, date, and new lead crew)
    $dupStmt = $db->prepare("
        SELECT id FROM calendar_stops
        WHERE property_id = ? AND stop_date = ? AND crew_id <=> ? AND id != ?
    ");
    $dupStmt->execute([$stop['property_id'], $stop['stop_date'], $leadCrewId, $stopId]);
    if ($dupStmt->fetch()) {
        throw new Exception('A stop already exists for this property, date, and crew');
    }

    $db->beginTransaction();

    // Update the stop's lead crew
    $stmt = $db->prepare("UPDATE calendar_stops SET crew_id = ?, updated_at = NOW() WHERE id = ?");
    $stmt->execute([$leadCrewId, $stopId]);

    // Update linked scheduled visits (lead crew for backwards compat)
    $stmt = $db->prepare("
        UPDATE job_visits
        SET assigned_crew_id = ?, updated_at = NOW()
        WHERE stop_id = ? AND status IN ('scheduled', 'in_progress')
    ");
    $stmt->execute([$leadCrewId, $stopId]);

    // Sync junction table: delete old, insert new
    $delStmt = $db->prepare("DELETE FROM calendar_stop_crew WHERE stop_id = ?");
    $delStmt->execute([$stopId]);

    if (!empty($crewIds)) {
        $insStmt = $db->prepare("INSERT INTO calendar_stop_crew (stop_id, user_id) VALUES (?, ?)");
        foreach ($crewIds as $cid) {
            $insStmt->execute([$stopId, $cid]);
        }
    }

    $db->commit();

    // Log activity
    logActivityExtended(
        $user['id'],
        'Crew reassigned',
        "Stop #{$stopId} crew changed to {$crewDisplay}",
        null, null, null, null, null, null
    );

    http_response_code(200);
    echo json_encode([
        'success'    => true,
        'message'    => 'Crew updated successfully',
        'stop_id'    => $stopId,
        'crew_ids'   => $crewIds,
        'crew_names' => $crewNames,
        'crew_id'    => $leadCrewId,
        'crew_name'  => $crewDisplay
    ]);

} catch (Exception $e) {
    if (isset($db) && $db->inTransaction()) {
        $db->rollBack();
    }
    http_response_code(500);
    echo json_encode(['error' => $e->getMessage()]);
}

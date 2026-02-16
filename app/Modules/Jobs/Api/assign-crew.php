<?php
/**
 * Assign Crew to Calendar Stop API
 * Updates the crew_id on a calendar stop and its linked scheduled visits.
 *
 * POST JSON: { stop_id, crew_id }
 * crew_id can be null to unassign.
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
    $crewId = isset($input['crew_id']) && $input['crew_id'] !== '' && $input['crew_id'] !== null
        ? (int)$input['crew_id']
        : null;

    $db = getDB();

    // Get current stop
    $stmt = $db->prepare("SELECT id, crew_id, property_id, stop_date FROM calendar_stops WHERE id = ?");
    $stmt->execute([$stopId]);
    $stop = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$stop) {
        throw new Exception('Stop not found');
    }

    // Validate crew exists if provided
    $crewName = 'Unassigned';
    if ($crewId !== null) {
        $cStmt = $db->prepare("SELECT id, full_name FROM users WHERE id = ? AND is_active = 1");
        $cStmt->execute([$crewId]);
        $crewUser = $cStmt->fetch(PDO::FETCH_ASSOC);
        if (!$crewUser) {
            throw new Exception('Crew member not found or inactive');
        }
        $crewName = $crewUser['full_name'];
    }

    // Check for duplicate stop (same property, date, and new crew)
    $dupStmt = $db->prepare("
        SELECT id FROM calendar_stops
        WHERE property_id = ? AND stop_date = ? AND crew_id <=> ? AND id != ?
    ");
    $dupStmt->execute([$stop['property_id'], $stop['stop_date'], $crewId, $stopId]);
    if ($dupStmt->fetch()) {
        throw new Exception('A stop already exists for this property, date, and crew');
    }

    $db->beginTransaction();

    // Update the stop's crew
    $stmt = $db->prepare("UPDATE calendar_stops SET crew_id = ?, updated_at = NOW() WHERE id = ?");
    $stmt->execute([$crewId, $stopId]);

    // Update linked scheduled visits
    $stmt = $db->prepare("
        UPDATE job_visits
        SET assigned_crew_id = ?, updated_at = NOW()
        WHERE stop_id = ? AND status IN ('scheduled', 'in_progress')
    ");
    $stmt->execute([$crewId, $stopId]);

    $db->commit();

    // Log activity
    $oldCrewId = $stop['crew_id'];
    logActivityExtended(
        $user['id'],
        'Crew reassigned',
        "Stop #{$stopId} crew changed to {$crewName}",
        null, null, null, null, null, null
    );

    http_response_code(200);
    echo json_encode([
        'success'   => true,
        'message'   => 'Crew updated successfully',
        'stop_id'   => $stopId,
        'crew_id'   => $crewId,
        'crew_name' => $crewName
    ]);

} catch (Exception $e) {
    if (isset($db) && $db->inTransaction()) {
        $db->rollBack();
    }
    http_response_code(500);
    echo json_encode(['error' => $e->getMessage()]);
}

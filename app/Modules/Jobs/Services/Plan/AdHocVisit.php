<?php
/**
 * Part of the Job Plan / Visit / Calendar Stop function library.
 * addAdHocVisit — add a single manual visit to an existing plan (field "add a
 * job/visit on the spot" flow, crew standing at a property with no visit
 * scheduled today).
 *
 * Loaded via app/Modules/Jobs/Services/PlanFunctions.php (aggregator).
 */

/**
 * @return array ['success' => bool, 'visit_id' => int|null, 'visit_number' => string|null, 'errors' => []]
 */
function addAdHocVisit(int $planId, string $date, int $crewId, int $userId): array {
    $db = getDB();

    $planStmt = $db->prepare("SELECT * FROM job_plans WHERE id = ?");
    $planStmt->execute([$planId]);
    $plan = $planStmt->fetch(PDO::FETCH_ASSOC);

    if (!$plan) {
        return ['success' => false, 'visit_id' => null, 'visit_number' => null, 'errors' => ['Plan not found.']];
    }
    if ($plan['status'] !== 'active') {
        return ['success' => false, 'visit_id' => null, 'visit_number' => null, 'errors' => ['This job is not active.']];
    }

    // A non-cancelled visit already exists for this date — return it instead of duplicating.
    $dupStmt = $db->prepare("
        SELECT id, visit_number FROM job_visits
        WHERE plan_id = ? AND scheduled_date = ? AND status != 'cancelled'
    ");
    $dupStmt->execute([$planId, $date]);
    $dup = $dupStmt->fetch(PDO::FETCH_ASSOC);
    if ($dup) {
        return [
            'success'      => true,
            'visit_id'     => (int)$dup['id'],
            'visit_number' => $dup['visit_number'],
            'errors'       => [],
        ];
    }

    try {
        $stopId = ensureCalendarStop((int)$plan['property_id'], $date, $crewId);

        $seqStmt = $db->prepare("SELECT MAX(sequence_index) FROM job_visits WHERE plan_id = ?");
        $seqStmt->execute([$planId]);
        $nextSeq = ((int)$seqStmt->fetchColumn()) + 1;

        $visitNumber = generateVisitNumber($plan['plan_number'], $nextSeq);

        $insStmt = $db->prepare("
            INSERT IGNORE INTO job_visits (
                visit_number, plan_id, stop_id,
                scheduled_date, scheduled_time_start, scheduled_time_end,
                sequence_index, assigned_crew_id, status
            ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, 'scheduled')
        ");
        $insStmt->execute([
            $visitNumber, $planId, $stopId,
            $date, $plan['default_time_start'], $plan['default_time_end'],
            $nextSeq, $crewId,
        ]);

        if ($insStmt->rowCount() === 0) {
            return ['success' => false, 'visit_id' => null, 'visit_number' => null, 'errors' => ['A visit already exists for this date.']];
        }

        $visitId = (int)$db->lastInsertId();

        // Make sure the crew member is on the stop's crew list (INSERT IGNORE — non-critical).
        $db->prepare("INSERT IGNORE INTO calendar_stop_crew (stop_id, user_id) VALUES (?, ?)")
           ->execute([$stopId, $crewId]);

        return ['success' => true, 'visit_id' => $visitId, 'visit_number' => $visitNumber, 'errors' => []];

    } catch (Exception $e) {
        error_log("addAdHocVisit error: " . $e->getMessage());
        return ['success' => false, 'visit_id' => null, 'visit_number' => null, 'errors' => ['Error adding visit: ' . $e->getMessage()]];
    }
}

<?php
/**
 * Part of the Job Plan / Visit / Calendar Stop function library.
 * Plan & visit crew assignments, unscheduled visits
 *
 * Loaded via app/Modules/Jobs/Services/PlanFunctions.php (aggregator).
 * Global functions — names/signatures unchanged from the original monolith.
 */

// ============================================================================
// PLAN CREW ASSIGNMENTS
// ============================================================================

/**
 * Get crew members assigned to a plan.
 */
function getPlanCrewAssignments(int $planId): array {
    $db = getDB();
    $stmt = $db->prepare("
        SELECT pca.user_id, pca.role, u.full_name, u.email, u.role AS user_role
        FROM plan_crew_assignments pca
        JOIN users u ON pca.user_id = u.id
        WHERE pca.plan_id = ?
        ORDER BY FIELD(pca.role, 'lead', 'crew'), u.full_name
    ");
    $stmt->execute([$planId]);
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

/**
 * Set crew assignments for a plan (replaces all existing).
 * First crew member becomes the lead if no leadId specified.
 * Also updates job_plans.default_crew_id to the lead.
 */
function setPlanCrewAssignments(int $planId, array $crewIds, ?int $leadId = null): void {
    $db = getDB();

    // Filter to valid integers
    $crewIds = array_filter(array_map('intval', $crewIds), function($id) { return $id > 0; });
    $crewIds = array_unique($crewIds);

    // Delete existing assignments
    $stmt = $db->prepare("DELETE FROM plan_crew_assignments WHERE plan_id = ?");
    $stmt->execute([$planId]);

    if (empty($crewIds)) {
        // Clear default_crew_id too
        $stmt = $db->prepare("UPDATE job_plans SET default_crew_id = NULL WHERE id = ?");
        $stmt->execute([$planId]);
        return;
    }

    // If no lead specified, use first crew member
    if (!$leadId || !in_array($leadId, $crewIds, true)) {
        $leadId = $crewIds[0];
    }

    $stmt = $db->prepare("
        INSERT INTO plan_crew_assignments (plan_id, user_id, role)
        VALUES (?, ?, ?)
    ");

    foreach ($crewIds as $userId) {
        $role = ($userId === $leadId) ? 'lead' : 'crew';
        $stmt->execute([$planId, $userId, $role]);
    }

    // Update default_crew_id to the lead
    $upStmt = $db->prepare("UPDATE job_plans SET default_crew_id = ? WHERE id = ?");
    $upStmt->execute([$leadId, $planId]);
}

function getVisitCrewAssignments(int $visitId): array {
    $db = getDB();
    $stmt = $db->prepare("
        SELECT vca.user_id, vca.role, u.full_name, u.email, u.role AS user_role
        FROM visit_crew_assignments vca
        JOIN users u ON vca.user_id = u.id
        WHERE vca.visit_id = ?
        ORDER BY FIELD(vca.role, 'lead', 'crew'), u.full_name
    ");
    $stmt->execute([$visitId]);
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

function setVisitCrewAssignments(int $visitId, array $crewIds, ?int $leadId = null): void {
    $db = getDB();

    $crewIds = array_values(array_unique(array_filter(array_map('intval', $crewIds), function($id) { return $id > 0; })));

    $stmt = $db->prepare("DELETE FROM visit_crew_assignments WHERE visit_id = ?");
    $stmt->execute([$visitId]);

    if (empty($crewIds)) {
        $stmt = $db->prepare("UPDATE job_visits SET assigned_crew_id = NULL WHERE id = ?");
        $stmt->execute([$visitId]);
        return;
    }

    if (!$leadId || !in_array($leadId, $crewIds, true)) {
        $leadId = $crewIds[0];
    }

    $stmt = $db->prepare("INSERT INTO visit_crew_assignments (visit_id, user_id, role) VALUES (?, ?, ?)");
    foreach ($crewIds as $userId) {
        $stmt->execute([$visitId, $userId, ($userId === $leadId) ? 'lead' : 'crew']);
    }

    $upStmt = $db->prepare("UPDATE job_visits SET assigned_crew_id = ? WHERE id = ?");
    $upStmt->execute([$leadId, $visitId]);
}

// ─── Unscheduled Jobs Tray ────────────────────────────────────────────────────

/**
 * Get all job_visits that are scheduled but have no calendar_stop assigned yet.
 * Used to populate the Unscheduled Jobs Tray on the schedule page.
 *
 * Returns visits from active plans, ordered by their target scheduled_date.
 *
 * @return array  Flat list of visit rows, each containing:
 *   visit_id, plan_id, scheduled_date, plan_title, service_type,
 *   price_per_visit, estimated_duration, property_id,
 *   property_address, property_city, contact_name
 */
function getUnscheduledVisits(): array
{
    try {
        $db = getDB();
        $stmt = $db->prepare("
            SELECT
                jv.id              AS visit_id,
                jv.plan_id,
                jv.scheduled_date,
                jp.title           AS plan_title,
                jp.service_type,
                jp.price_per_visit,
                jp.estimated_duration_minutes AS estimated_duration,
                jp.default_crew_id,
                jp.property_id,
                jp.recurrence_pattern,
                jp.recurrence_interval,
                jp.recurrence_interval_unit,
                jp.recurrence_day_of_week,
                jp.start_date      AS plan_start_date,
                (SELECT MAX(jv2.completed_at)
                 FROM job_visits jv2
                 WHERE jv2.plan_id = jp.id
                   AND jv2.status = 'completed') AS last_completed_at,
                p.address          AS property_address,
                p.city             AS property_city,
                p.latitude         AS property_lat,
                p.longitude        AS property_lng,
                CONCAT(ct.first_name, ' ', ct.last_name) AS contact_name
            FROM job_visits jv
            JOIN job_plans jp ON jv.plan_id = jp.id
            JOIN properties p  ON jp.property_id = p.id
            LEFT JOIN contacts ct ON p.site_contact_id = ct.id
            WHERE jp.status = 'active'
              AND jv.status  = 'scheduled'
              AND jv.stop_id IS NULL
            ORDER BY jv.scheduled_date ASC, jp.service_type ASC
        ");
        $stmt->execute();
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    } catch (Throwable $e) {
        error_log('getUnscheduledVisits error: ' . $e->getMessage());
        return [];
    }
}

// ─────────────────────────────────────────────────────────────────

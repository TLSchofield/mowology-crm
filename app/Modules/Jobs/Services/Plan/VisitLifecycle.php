<?php
/**
 * Part of the Job Plan / Visit / Calendar Stop function library.
 * Visit status & lifecycle, plan pause/resume, exceptions, invoicing
 *
 * Loaded via app/Modules/Jobs/Services/PlanFunctions.php (aggregator).
 * Global functions — names/signatures unchanged from the original monolith.
 */

// ============================================================================
// VISIT STATUS & LIFECYCLE
// ============================================================================

/**
 * Update a visit's status with proper timestamping and logging.
 */
function updateVisitStatus(int $visitId, string $newStatus, int $userId, ?string $notes = null): bool {
    $db = getDB();

    $validStatuses = ['scheduled', 'in_progress', 'completed', 'skipped', 'weather', 'cancelled'];
    if (!in_array($newStatus, $validStatuses)) {
        return false;
    }

    try {
        $setClauses = ["status = ?", "status_changed_at = NOW()"];
        $params = [$newStatus];

        if ($newStatus === 'in_progress') {
            $setClauses[] = "started_at = NOW()";
        } elseif ($newStatus === 'completed') {
            $setClauses[] = "completed_at = NOW()";
            if ($notes) {
                $setClauses[] = "completion_notes = ?";
                $params[] = $notes;
            }
        }

        $params[] = $visitId;

        $stmt = $db->prepare("
            UPDATE job_visits SET " . implode(', ', $setClauses) . " WHERE id = ?
        ");
        $stmt->execute($params);

        // Log activity
        if (function_exists('logActivityExtended')) {
            $visit = getVisitWithPlan($visitId);
            if ($visit) {
                logActivityExtended(
                    $userId,
                    "Visit {$newStatus}",
                    "Visit {$visit['visit_number']} marked as {$newStatus}" . ($notes ? ": {$notes}" : ''),
                    $visit['company_id'] ?? null,
                    null, null, null,
                    $visit['plan_id'],
                    $visitId
                );
            }
        }

        // Completion hooks: profitability snapshot + fertilizer notification
        if ($newStatus === 'completed') {
            // Snapshot labor/material/drive costs (VisitCompletionService)
            if (class_exists('VisitCompletionService')) {
                try {
                    VisitCompletionService::capture($visitId, $userId);
                } catch (Throwable $e) {
                    error_log("VisitCompletionService::capture failed for visit {$visitId}: " . $e->getMessage());
                }
            }

            // Completion notifications: fertilizer (prepaid bundles) or general job complete
            $completedVisit = getVisitWithPlan($visitId);
            if ($completedVisit && !empty($completedVisit['is_prepaid_bundle'])) {
                // Fertilizer / prepaid-bundle — rich custom email with photos, materials, progress
                try {
                    if (function_exists('sendFertilizerCompletionNotification')) {
                        sendFertilizerCompletionNotification($visitId);
                    }
                    // Increment application counter
                    $db->prepare(
                        "UPDATE job_plans SET bundle_applications_used = bundle_applications_used + 1 WHERE id = ?"
                    )->execute([$completedVisit['plan_id']]);
                } catch (Throwable $e) {
                    error_log("Fertilizer notification failed for visit {$visitId}: " . $e->getMessage());
                }
            } else {
                // General service completion — branded template email
                try {
                    if (function_exists('sendJobCompleteNotification')) {
                        sendJobCompleteNotification($visitId);
                    }
                } catch (Throwable $e) {
                    error_log("Job complete notification failed for visit {$visitId}: " . $e->getMessage());
                }
            }

            // Propagate to calendar_stops so the stop reflects completion without
            // requiring a page refresh. This mirrors the logic in pow-actions.php
            // end_visit, which only runs via the direct-complete path — the clock-out
            // path (stopJobTimer → updateVisitStatus) previously left stops stuck in
            // 'scheduled'/'in_progress' after a page reload, causing "cannot be ended"
            // errors when Complete Job was tapped again.
            try {
                $stopRow = $db->prepare("SELECT stop_id FROM job_visits WHERE id = ?");
                $stopRow->execute([$visitId]);
                $stopId = $stopRow->fetchColumn();
                if ($stopId) {
                    $pendingStmt = $db->prepare("
                        SELECT COUNT(*) FROM job_visits
                        WHERE stop_id = ? AND status NOT IN ('completed', 'skipped', 'cancelled')
                    ");
                    $pendingStmt->execute([$stopId]);
                    $pending = (int)$pendingStmt->fetchColumn();
                    if ($pending === 0) {
                        $db->prepare("
                            UPDATE calendar_stops SET status = 'completed', updated_at = NOW()
                            WHERE id = ? AND status != 'completed'
                        ")->execute([$stopId]);
                    } else {
                        $db->prepare("
                            UPDATE calendar_stops SET status = 'in_progress', updated_at = NOW()
                            WHERE id = ? AND status = 'scheduled'
                        ")->execute([$stopId]);
                    }
                }
            } catch (Throwable $e) {
                error_log("updateVisitStatus stop propagation error for visit {$visitId}: " . $e->getMessage());
            }
        }

        return true;
    } catch (Exception $e) {
        error_log("updateVisitStatus error: " . $e->getMessage());
        return false;
    }
}

/**
 * Get a single visit with its plan details.
 */
function getVisitWithPlan(int $visitId): ?array {
    $db = getDB();
    $stmt = $db->prepare("
        SELECT jv.*, jp.plan_number, jp.title AS plan_title, jp.service_type,
               jp.property_id, jp.company_id, jp.price_per_visit,
               jp.quote_id AS plan_quote_id,
               jp.is_prepaid_bundle, jp.source_bundle_id, jp.bundle_applications_used,
               jp.checklist_template, jp.photo_types_required,
               jp.gps_enforcement, jp.checklist_blocks_completion, jp.photos_block_completion,
               p.address AS property_address, p.city AS property_city,
               co.company_name,
               u.full_name AS crew_name
        FROM job_visits jv
        JOIN job_plans jp ON jv.plan_id = jp.id
        LEFT JOIN properties p ON jp.property_id = p.id
        LEFT JOIN companies co ON jp.company_id = co.id
        LEFT JOIN users u ON jv.assigned_crew_id = u.id
        WHERE jv.id = ?
    ");
    $stmt->execute([$visitId]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    return $row ?: null;
}

/**
 * Get plan details with computed stats.
 */
function getPlanDetails(int $planId): ?array {
    $db = getDB();

    $stmt = $db->prepare("
        SELECT jp.*,
               p.address AS property_address, p.city AS property_city,
               p.latitude, p.longitude,
               NULLIF(p.billing_entity_name, '') AS billing_entity_name,
               co.company_name,
               mgr.company_name AS pm_firm_name,
               COALESCE(ct.id, pc.id) AS contact_id,
               COALESCE(ct.first_name, pc.first_name) AS first_name,
               COALESCE(ct.last_name, pc.last_name) AS last_name,
               COALESCE(ct.email, pc.email) AS contact_email,
               COALESCE(ct.phone, pc.phone) AS contact_phone,
               u.full_name AS default_crew_name,
               creator.full_name AS created_by_name,
               q.quote_number,
               q.total_amount AS quote_total_amount,
               ctr.contract_number,
               ctr.status AS contract_status,
               ctr.billing_amount AS contract_billing_amount,
               ctr.billing_cycle  AS contract_billing_cycle,
               ctr.invoice_timing AS contract_invoice_timing
        FROM job_plans jp
        LEFT JOIN properties p ON jp.property_id = p.id
        LEFT JOIN companies co ON jp.company_id = co.id
        LEFT JOIN companies mgr ON p.property_manager_id = mgr.id
        LEFT JOIN contacts ct ON co.primary_contact_id = ct.id
        LEFT JOIN contacts pc ON p.site_contact_id = pc.id
        LEFT JOIN users u ON jp.default_crew_id = u.id
        LEFT JOIN users creator ON jp.created_by = creator.id
        LEFT JOIN quotes q ON jp.quote_id = q.id
        LEFT JOIN contracts ctr ON jp.contract_id = ctr.id
        WHERE jp.id = ?
    ");
    $stmt->execute([$planId]);
    $plan = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$plan) return null;

    // Resolve the display name of the client via the canonical BillToResolver.
    require_once APP_ROOT . '/Modules/Clients/Services/BillToResolver.php';
    $plan['client_display_name'] = (new BillToResolver(getDB()))->composeBillToName([
        'property_billing_entity' => $plan['billing_entity_name'] ?? null,
        'pm_firm_name'            => $plan['pm_firm_name'] ?? null,
        'company_name'            => $plan['company_name'] ?? null,
        'contact_first'           => $plan['first_name'] ?? null,
        'contact_last'            => $plan['last_name'] ?? null,
    ]) ?? '';

    // Compute stats
    $statsStmt = $db->prepare("
        SELECT
            COUNT(*) AS total_visits,
            SUM(CASE WHEN status = 'completed' THEN 1 ELSE 0 END) AS visits_completed,
            SUM(CASE WHEN status = 'scheduled' THEN 1 ELSE 0 END) AS visits_scheduled,
            SUM(CASE WHEN status = 'skipped' THEN 1 ELSE 0 END) AS visits_skipped,
            SUM(CASE WHEN status = 'weather' THEN 1 ELSE 0 END) AS visits_weather,
            SUM(CASE WHEN status = 'completed' THEN COALESCE(actual_amount, 0) ELSE 0 END) AS total_revenue,
            MIN(CASE WHEN status = 'scheduled' AND scheduled_date >= CURDATE() THEN scheduled_date ELSE NULL END) AS next_visit_date
        FROM job_visits
        WHERE plan_id = ? AND status != 'cancelled'
    ");
    $statsStmt->execute([$planId]);
    $stats = $statsStmt->fetch(PDO::FETCH_ASSOC);

    $plan['total_visits'] = (int)($stats['total_visits'] ?? 0);
    $plan['visits_completed'] = (int)($stats['visits_completed'] ?? 0);
    $plan['visits_scheduled'] = (int)($stats['visits_scheduled'] ?? 0);
    $plan['visits_skipped'] = (int)($stats['visits_skipped'] ?? 0);
    $plan['visits_weather'] = (int)($stats['visits_weather'] ?? 0);
    $plan['total_revenue'] = (float)($stats['total_revenue'] ?? 0);
    $plan['next_visit_date'] = $stats['next_visit_date'];

    return $plan;
}

/**
 * Get visits for a plan (paginated, ordered).
 */
function getPlanVisits(int $planId, ?string $status = null, int $limit = 50, int $offset = 0): array {
    $db = getDB();

    $sql = "
        SELECT jv.*,
               u.full_name AS crew_name,
               cs.route_order,
               (SELECT COUNT(*) FROM visit_photos vp WHERE vp.visit_id = jv.id) AS photo_count,
               (SELECT COUNT(*) FROM visit_notes vn WHERE vn.visit_id = jv.id) AS note_count
        FROM job_visits jv
        LEFT JOIN users u ON jv.assigned_crew_id = u.id
        LEFT JOIN calendar_stops cs ON jv.stop_id = cs.id
        WHERE jv.plan_id = ?
          AND jv.status != 'cancelled'
    ";
    $params = [$planId];

    if ($status) {
        $sql .= " AND jv.status = ?";
        $params[] = $status;
    }

    $sql .= " ORDER BY jv.scheduled_date DESC, jv.scheduled_time_start ASC LIMIT ? OFFSET ?";
    $params[] = $limit;
    $params[] = $offset;

    $stmt = $db->prepare($sql);
    $stmt->execute($params);
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}


// ============================================================================
// PLAN MANAGEMENT (pause, resume, propagate changes)
// ============================================================================

/**
 * Pause a plan. Cancels all future scheduled visits.
 */
function pausePlan(int $planId, int $userId, string $reason = ''): bool {
    $db = getDB();
    try {
        $db->beginTransaction();

        $stmt = $db->prepare("
            UPDATE job_plans
            SET status = 'paused', status_changed_at = NOW(), paused_at = NOW(), paused_reason = ?
            WHERE id = ? AND status = 'active'
        ");
        $stmt->execute([$reason, $planId]);

        if ($stmt->rowCount() === 0) {
            $db->rollBack();
            return false;
        }

        // Cancel future scheduled visits
        $stmt = $db->prepare("
            UPDATE job_visits
            SET status = 'cancelled', status_changed_at = NOW()
            WHERE plan_id = ? AND status = 'scheduled' AND scheduled_date >= CURDATE()
        ");
        $stmt->execute([$planId]);

        $db->commit();
        return true;
    } catch (Exception $e) {
        if ($db->inTransaction()) $db->rollBack();
        error_log("pausePlan error: " . $e->getMessage());
        return false;
    }
}

/**
 * Resume a paused plan. Regenerates visits from today forward.
 */
function resumePlan(int $planId, int $userId): bool {
    $db = getDB();
    $db->beginTransaction();
    try {
        $stmt = $db->prepare("
            UPDATE job_plans
            SET status = 'active', status_changed_at = NOW(),
                paused_at = NULL, paused_reason = NULL,
                visits_generated_through = NULL
            WHERE id = ? AND status = 'paused'
        ");
        $stmt->execute([$planId]);

        if ($stmt->rowCount() === 0) {
            $db->rollBack();
            return false;
        }

        $db->commit();

        // Regenerate visits (outside transaction — generateVisits manages its own DB operations)
        generateVisits($planId);
        return true;
    } catch (Throwable $e) {
        if ($db->inTransaction()) $db->rollBack();
        error_log("resumePlan error: " . $e->getMessage());
        return false;
    }
}

/**
 * Propagate plan changes to future unstarted visits.
 * Only updates visits that are still 'scheduled'.
 */
function propagatePlanChanges(int $planId, array $changes, int $userId): void {
    $db = getDB();

    $allowedFields = [
        'assigned_crew_id' => 'default_crew_id',
        'scheduled_time_start' => 'default_time_start',
        'scheduled_time_end' => 'default_time_end',
    ];

    $setClauses = [];
    $params = [];

    foreach ($allowedFields as $visitCol => $planCol) {
        if (array_key_exists($planCol, $changes)) {
            $setClauses[] = "{$visitCol} = ?";
            $params[] = $changes[$planCol];
        }
    }

    if (empty($setClauses)) return;

    $params[] = $planId;

    $stmt = $db->prepare("
        UPDATE job_visits
        SET " . implode(', ', $setClauses) . "
        WHERE plan_id = ? AND status = 'scheduled' AND scheduled_date >= CURDATE()
    ");
    $stmt->execute($params);

    // If crew changed, update calendar_stops.crew_id for future stops tied only to this plan
    if (array_key_exists('default_crew_id', $changes)) {
        $newLeadCrewId = $changes['default_crew_id'] ? (int)$changes['default_crew_id'] : null;
        // Only update stops where all visits belong to this plan (safe to remap crew)
        $db->prepare("
            UPDATE calendar_stops cs
            INNER JOIN (
                SELECT stop_id
                FROM job_visits
                WHERE status = 'scheduled' AND scheduled_date >= CURDATE() AND stop_id IS NOT NULL
                GROUP BY stop_id
                HAVING SUM(plan_id != ?) = 0
            ) AS solo ON solo.stop_id = cs.id
            SET cs.crew_id = ?, cs.updated_at = NOW()
        ")->execute([$planId, $newLeadCrewId]);
    }
}


// ============================================================================
// VISIT EXCEPTIONS
// ============================================================================

/**
 * Skip a specific visit date (weather, holiday, etc.)
 */
function skipVisitDate(int $planId, string $date, int $userId, string $reason = ''): bool {
    $db = getDB();
    try {
        // Try to find and skip existing visit
        $stmt = $db->prepare("
            UPDATE job_visits
            SET status = 'skipped', status_changed_at = NOW(),
                completion_notes = ?
            WHERE plan_id = ? AND scheduled_date = ? AND status = 'scheduled'
        ");
        $stmt->execute([$reason, $planId, $date]);

        return true;
    } catch (Exception $e) {
        error_log("skipVisitDate error: " . $e->getMessage());
        return false;
    }
}

/**
 * Move a single visit to a different date.
 * Updates the visit date and its calendar stop linkage.
 */
function moveVisit(int $visitId, string $newDate, ?string $newTimeStart, int $userId): bool {
    $db = getDB();
    try {
        // Get current visit
        $stmt = $db->prepare("SELECT * FROM job_visits WHERE id = ? AND status IN ('scheduled', 'skipped')");
        $stmt->execute([$visitId]);
        $visit = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$visit) return false;

        // Get plan for property/crew info
        $planStmt = $db->prepare("SELECT property_id, default_crew_id FROM job_plans WHERE id = ?");
        $planStmt->execute([$visit['plan_id']]);
        $plan = $planStmt->fetch(PDO::FETCH_ASSOC);
        if (!$plan) return false;

        // Ensure stop exists for new date
        $crewId = $visit['assigned_crew_id'] ?? $plan['default_crew_id'];
        $newStopId = ensureCalendarStop((int)$plan['property_id'], $newDate, $crewId ? (int)$crewId : null);

        // Update visit — always update scheduled_date; only update stop_id if a valid stop was found
        $setClauses = ["scheduled_date = ?"];
        $params = [$newDate];
        if ($newStopId > 0) {
            $setClauses[] = "stop_id = ?";
            $params[] = $newStopId;
        }

        if ($newTimeStart) {
            $setClauses[] = "scheduled_time_start = ?";
            $params[] = $newTimeStart;
        }

        $params[] = $visitId;

        $stmt = $db->prepare("
            UPDATE job_visits SET " . implode(', ', $setClauses) . " WHERE id = ?
        ");
        $stmt->execute($params);

        return true;
    } catch (Exception $e) {
        error_log("moveVisit error: " . $e->getMessage());
        return false;
    }
}


// ============================================================================
// INVOICING
// ============================================================================

/**
 * Check if a visit is eligible for invoicing.
 * Replaces canInvoiceJob().
 */
function canInvoiceVisit(int $visitId): array {
    $db = getDB();

    $visit = getVisitWithPlan($visitId);
    if (!$visit) {
        return ['can_invoice' => false, 'missing_requirements' => ['Visit not found'], 'photos_count' => 0];
    }

    $missing = [];

    // Check checklist completion if required
    if ($visit['checklist_blocks_completion'] && !empty($visit['checklist_template'])) {
        $template = json_decode($visit['checklist_template'], true) ?: [];
        $completed = json_decode($visit['checklist_completed'], true) ?: [];

        foreach ($template as $item) {
            if (empty($completed[$item])) {
                $missing[] = "Checklist: {$item}";
            }
        }
    }

    // Check photo requirements if required
    $photoCount = 0;
    if ($visit['photos_block_completion'] && !empty($visit['photo_types_required'])) {
        $requiredTypes = json_decode($visit['photo_types_required'], true) ?: [];

        $pStmt = $db->prepare("SELECT photo_type, COUNT(*) as cnt FROM visit_photos WHERE visit_id = ? GROUP BY photo_type");
        $pStmt->execute([$visitId]);
        $uploadedTypes = [];
        while ($row = $pStmt->fetch(PDO::FETCH_ASSOC)) {
            $uploadedTypes[$row['photo_type']] = (int)$row['cnt'];
            $photoCount += (int)$row['cnt'];
        }

        foreach ($requiredTypes as $type) {
            if (empty($uploadedTypes[$type])) {
                $missing[] = "Photo: {$type}";
            }
        }
    } else {
        $pStmt = $db->prepare("SELECT COUNT(*) FROM visit_photos WHERE visit_id = ?");
        $pStmt->execute([$visitId]);
        $photoCount = (int)$pStmt->fetchColumn();
    }

    return [
        'can_invoice' => empty($missing),
        'missing_requirements' => $missing,
        'photos_count' => $photoCount,
    ];
}

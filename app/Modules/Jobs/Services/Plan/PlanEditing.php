<?php
/**
 * Part of the Job Plan / Visit / Calendar Stop function library.
 * cleanupOrphanedVisits / updateJobPlan / replacePlanLineItems
 *
 * Loaded via app/Modules/Jobs/Services/PlanFunctions.php (aggregator).
 * Global functions — names/signatures unchanged from the original monolith.
 */

// ============================================================================
// ============================================================================
// PLAN EDITING
// ============================================================================

/**
 * Cancel any skipped/scheduled visits that predate the plan's start date.
 * Orphaned visits accumulate when a plan is edited to start later or change
 * its recurrence day — the old visits never get cleaned up by the normal
 * future-visit cancellation (which only touches scheduled visits >= today).
 */
function cleanupOrphanedVisits(int $planId): void {
    $db = getDB();
    $stmt = $db->prepare("SELECT plan_start_date FROM job_plans WHERE id = ?");
    $stmt->execute([$planId]);
    $planStartDate = $stmt->fetchColumn();
    if (!$planStartDate) return;

    $db->prepare("
        UPDATE job_visits
        SET status = 'cancelled', status_changed_at = NOW()
        WHERE plan_id = ?
          AND status IN ('scheduled', 'skipped')
          AND scheduled_date < ?
    ")->execute([$planId, $planStartDate]);
}

/**
 * Update an existing job plan's details.
 *
 * Handles:
 * - Basic field updates (title, description, pricing, duration, etc.)
 * - Schedule/recurrence changes → cancels future visits + regenerates
 * - Crew/time changes → propagates to future scheduled visits
 *
 * @return array ['success' => bool, 'errors' => [], 'visits_regenerated' => bool]
 */
function updateJobPlan(int $planId, array $data, int $userId): array {
    $db = getDB();
    $errors = [];

    if (isset($data['title']) && empty(trim($data['title']))) {
        $errors[] = 'Title is required.';
    }

    if (!empty($errors)) {
        return ['success' => false, 'errors' => $errors, 'visits_regenerated' => false];
    }

    try {
        // Load current plan to detect what changed
        $stmt = $db->prepare("SELECT * FROM job_plans WHERE id = ?");
        $stmt->execute([$planId]);
        $current = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$current) {
            return ['success' => false, 'errors' => ['Plan not found.'], 'visits_regenerated' => false];
        }

        // Fields allowed to be updated
        $editableFields = [
            'title', 'description', 'service_type',
            'pricing_model', 'price_per_visit', 'estimated_amount',
            'invoice_timing',
            'estimated_duration_minutes',
            'default_crew_id', 'default_time_start', 'default_time_end',
            'plan_start_date', 'plan_end_date',
            'is_recurring', 'recurrence_pattern', 'recurrence_interval',
            'recurrence_interval_unit', 'recurrence_day_of_week',
            'horizon_days',
            'company_id',
        ];

        $setClauses = [];
        $params = [];
        $changes = [];

        foreach ($editableFields as $field) {
            if (!array_key_exists($field, $data)) continue;

            $newVal = $data[$field];
            // Normalize empty strings to null for nullable fields
            if ($newVal === '' && in_array($field, [
                'description', 'plan_end_date', 'default_crew_id',
                'default_time_start', 'default_time_end',
                'recurrence_pattern', 'recurrence_day_of_week',
                'company_id',
            ])) {
                $newVal = null;
            }

            $setClauses[] = "{$field} = ?";
            $params[] = $newVal;
            $changes[$field] = $newVal;
        }

        if (empty($setClauses)) {
            return ['success' => true, 'errors' => [], 'visits_regenerated' => false];
        }

        $setClauses[] = "updated_at = NOW()";
        $params[] = $planId;

        $db->beginTransaction();

        $stmt = $db->prepare("
            UPDATE job_plans SET " . implode(', ', $setClauses) . " WHERE id = ?
        ");
        $stmt->execute($params);

        $db->commit();

        // Update crew assignments if provided
        if (array_key_exists('crew_ids', $data) && is_array($data['crew_ids'])) {
            $leadId = !empty($data['default_crew_id']) ? (int)$data['default_crew_id'] : null;
            setPlanCrewAssignments($planId, $data['crew_ids'], $leadId);

            // Sync calendar_stop_crew for all future scheduled stops of this plan so
            // the assign-crew modal pre-checks the correct crew members on the schedule page.
            $filteredCrewIds = array_values(array_unique(
                array_filter(array_map('intval', $data['crew_ids']), function($id) { return $id > 0; })
            ));

            $stopStmt = $db->prepare("
                SELECT DISTINCT jv.stop_id
                FROM job_visits jv
                WHERE jv.plan_id = ?
                  AND jv.status = 'scheduled'
                  AND jv.scheduled_date >= CURDATE()
                  AND jv.stop_id IS NOT NULL
            ");
            $stopStmt->execute([$planId]);
            $futureStopIds = $stopStmt->fetchAll(PDO::FETCH_COLUMN);

            if (!empty($futureStopIds)) {
                $delCrewStmt = $db->prepare("DELETE FROM calendar_stop_crew WHERE stop_id = ?");
                $insCrewStmt = $db->prepare("INSERT INTO calendar_stop_crew (stop_id, user_id) VALUES (?, ?)");
                $updStopStmt = $db->prepare("UPDATE calendar_stops SET crew_id = ?, updated_at = NOW() WHERE id = ?");
                $clrStopStmt = $db->prepare("UPDATE calendar_stops SET crew_id = NULL, updated_at = NOW() WHERE id = ?");

                foreach ($futureStopIds as $sid) {
                    $delCrewStmt->execute([$sid]);
                    if (!empty($filteredCrewIds)) {
                        foreach ($filteredCrewIds as $cid) {
                            $insCrewStmt->execute([$sid, $cid]);
                        }
                        // Keep calendar_stops.crew_id in sync with the lead (first) crew
                        $updStopStmt->execute([$filteredCrewIds[0], $sid]);
                    } else {
                        $clrStopStmt->execute([$sid]);
                    }
                }
            }
        }

        // Determine if we need to regenerate visits or just propagate
        $recurrenceFields = [
            'is_recurring', 'recurrence_pattern', 'recurrence_interval',
            'recurrence_interval_unit', 'recurrence_day_of_week',
            'plan_start_date', 'plan_end_date', 'horizon_days',
        ];

        $recurrenceChanged = false;
        foreach ($recurrenceFields as $rf) {
            if (array_key_exists($rf, $changes) && (string)$changes[$rf] !== (string)($current[$rf] ?? '')) {
                $recurrenceChanged = true;
                break;
            }
        }

        if ($recurrenceChanged) {
            // Cancel future unstarted visits and regenerate
            $stmt = $db->prepare("
                UPDATE job_visits
                SET status = 'cancelled', status_changed_at = NOW()
                WHERE plan_id = ? AND status = 'scheduled' AND scheduled_date >= CURDATE()
            ");
            $stmt->execute([$planId]);

            // Cancel skipped/scheduled visits that now predate the new start date.
            // These are orphaned from the previous schedule and would show up with
            // the wrong day-of-week or before the plan's effective start.
            cleanupOrphanedVisits($planId);

            // Move the generation watermark to yesterday (NOT NULL) so the
            // regeneration pass runs in incremental mode and starts from TODAY.
            // Resetting to NULL would put generateVisits() into fresh mode, which
            // backfills up to 90 days of past-dated visits at the new cadence —
            // creating phantom "overdue" history alongside the real completed
            // visits from the previous schedule. A recurrence edit must only
            // ever change FUTURE visits; established history stays untouched.
            $stmt = $db->prepare("
                UPDATE job_plans
                SET visits_generated_through = DATE_SUB(CURDATE(), INTERVAL 1 DAY)
                WHERE id = ?
            ");
            $stmt->execute([$planId]);

            generateVisits($planId);

            return ['success' => true, 'errors' => [], 'visits_regenerated' => true];
        }

        // Propagate crew/time changes to future visits
        $propagateFields = ['default_crew_id', 'default_time_start', 'default_time_end'];
        $needsPropagate = false;
        foreach ($propagateFields as $pf) {
            if (array_key_exists($pf, $changes)) {
                $needsPropagate = true;
                break;
            }
        }
        if ($needsPropagate) {
            propagatePlanChanges($planId, $changes, $userId);
        }

        return ['success' => true, 'errors' => [], 'visits_regenerated' => false];

    } catch (Exception $e) {
        if ($db->inTransaction()) $db->rollBack();
        error_log("updateJobPlan error: " . $e->getMessage());
        return ['success' => false, 'errors' => ['Error updating plan: ' . $e->getMessage()], 'visits_regenerated' => false];
    }
}

/**
 * Replace all line items for a plan (delete existing + insert new).
 * Does NOT touch quote_line_items conversion tracking.
 */
function replacePlanLineItems(int $planId, array $items): bool {
    $db = getDB();

    try {
        $db->beginTransaction();

        // Delete existing items (but don't un-convert quote items)
        $stmt = $db->prepare("DELETE FROM plan_line_items WHERE plan_id = ?");
        $stmt->execute([$planId]);

        // Insert new items
        if (!empty($items)) {
            $insertStmt = $db->prepare("
                INSERT INTO plan_line_items
                    (plan_id, service_type, description, quantity, unit_type, unit_price, line_total, sort_order)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?)
            ");

            $sortOrder = 0;
            foreach ($items as $item) {
                if (empty($item['service_type'])) continue;
                $qty = floatval($item['quantity'] ?? 1);
                $unitPrice = floatval($item['unit_price'] ?? 0);
                $lineTotal = floatval($item['line_total'] ?? ($qty * $unitPrice));

                $insertStmt->execute([
                    $planId,
                    $item['service_type'],
                    $item['description'] ?? '',
                    $qty,
                    $item['unit_type'] ?? 'visit',
                    $unitPrice,
                    $lineTotal,
                    $sortOrder++,
                ]);
            }
        }

        // Recalculate plan total
        updatePlanTotalFromItems($planId);

        $db->commit();
        return true;

    } catch (Exception $e) {
        if ($db->inTransaction()) $db->rollBack();
        error_log("replacePlanLineItems error: " . $e->getMessage());
        return false;
    }
}

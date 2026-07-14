<?php
/**
 * VisitLifecycleService — visit status/lifecycle, plan pause/resume, exceptions,
 * invoicing eligibility.
 *
 * Extracted (2026-06-18, refactor Phase 2) from the procedural Plan function
 * library. The global functions in Plan/VisitLifecycle.php delegate here, so all
 * ~28 callers and the legacy shim are unchanged.
 *
 * updateVisitStatus() is the revenue-critical completion path (clock-out →
 * updateVisitStatus marks the visit complete, fires notifications, and propagates
 * to calendar_stops). Its DB/side-effect flow is preserved byte-for-byte; only the
 * deterministic decision logic is pulled into PURE, unit-tested methods:
 *   isValidVisitStatus / buildStatusSetClauses  (updateVisitStatus)
 *   buildPropagationSet                          (propagatePlanChanges)
 *   buildMoveVisitSet                            (moveVisit)
 *   computeMissingChecklist / computeMissingPhotos (canInvoiceVisit)
 */
class VisitLifecycleService
{
    public const VALID_VISIT_STATUSES = ['scheduled', 'in_progress', 'completed', 'skipped', 'weather', 'cancelled'];

    /** Allowed plan→visit field propagation map (planCol => visitCol). */
    private const PROPAGATION_FIELDS = [
        'assigned_crew_id' => 'default_crew_id',
        'scheduled_time_start' => 'default_time_start',
        'scheduled_time_end' => 'default_time_end',
    ];

    // =========================================================================
    // DB-backed methods (facade targets)
    // =========================================================================

    /**
     * Update a visit's status with proper timestamping and logging.
     */
    public static function updateVisitStatus(int $visitId, string $newStatus, int $userId, ?string $notes = null): bool {
        $db = getDB();

        if (!self::isValidVisitStatus($newStatus)) {
            return false;
        }

        try {
            $built = self::buildStatusSetClauses($newStatus, $notes);
            $params = $built['params'];
            $params[] = $visitId;

            $stmt = $db->prepare("
                UPDATE job_visits SET " . implode(', ', $built['set']) . " WHERE id = ?
            ");
            $stmt->execute($params);

            // Log activity
            if (function_exists('logActivityExtended')) {
                $visit = self::getVisitWithPlan($visitId);
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
                $completedVisit = self::getVisitWithPlan($visitId);
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

            }

            // Propagate terminal-state visits to calendar_stops so the Schedule
            // page reflects the change without a manual refresh. Runs for BOTH
            // 'completed' and 'skipped'.
            //
            // Previously only 'completed' propagated. A 'skipped' visit (from the
            // crew app's Skip button via pow-actions.php, or the desktop view.php
            // Skip form) left calendar_stops.status stuck on 'scheduled' — and the
            // desktop Schedule page renders every stop card from stop_status, so a
            // job the crew skipped still showed as un-done on the desktop. This
            // mirrors the duplicate logic in pow-actions.php end_visit, which only
            // covers the direct-complete path.
            if ($newStatus === 'completed' || $newStatus === 'skipped') {
                try {
                    $stopRow = $db->prepare("SELECT stop_id FROM job_visits WHERE id = ?");
                    $stopRow->execute([$visitId]);
                    $stopId = $stopRow->fetchColumn();
                    if ($stopId) {
                        self::propagateStopStatus((int)$stopId);
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
     * Recompute a calendar_stop's status from the current state of its visits
     * and persist it (only when it actually changes). Drives the stop-card
     * styling on the Schedule page — desktop AND crew — which reads
     * calendar_stops.status, not the individual visit statuses.
     *
     * The decision logic is the PURE method computeStopStatusFromCounts();
     * this method only does the DB read/write. The UPDATEs are guarded so a
     * stop that is already 'completed' is never downgraded to 'skipped'.
     */
    public static function propagateStopStatus(int $stopId): void {
        $db = getDB();

        // pending = visits not yet resolved; done = visits that were serviced.
        // SUM over boolean expressions is MySQL 5.7+ safe.
        $stmt = $db->prepare("
            SELECT
                SUM(status NOT IN ('completed', 'skipped', 'cancelled')) AS pending,
                SUM(status = 'completed') AS completed
            FROM job_visits
            WHERE stop_id = ?
        ");
        $stmt->execute([$stopId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC) ?: [];

        $new = self::computeStopStatusFromCounts(
            (int)($row['pending'] ?? 0),
            (int)($row['completed'] ?? 0)
        );
        if ($new === null) {
            return; // nothing resolved yet — leave the stop as 'scheduled'
        }

        if ($new === 'in_progress') {
            // Only promote a still-scheduled stop; never clobber a terminal one.
            $db->prepare("
                UPDATE calendar_stops SET status = 'in_progress', updated_at = NOW()
                WHERE id = ? AND status = 'scheduled'
            ")->execute([$stopId]);
        } elseif ($new === 'completed') {
            $db->prepare("
                UPDATE calendar_stops SET status = 'completed', updated_at = NOW()
                WHERE id = ? AND status <> 'completed'
            ")->execute([$stopId]);
        } else { // 'skipped' — all visits skipped/cancelled, none completed
            $db->prepare("
                UPDATE calendar_stops SET status = 'skipped', updated_at = NOW()
                WHERE id = ? AND status NOT IN ('completed', 'skipped')
            ")->execute([$stopId]);
        }
    }

    /**
     * Get a single visit with its plan details.
     */
    public static function getVisitWithPlan(int $visitId): ?array {
        $db = getDB();
        $stmt = $db->prepare("
            SELECT jv.*, jp.plan_number, jp.title AS plan_title, jp.service_type,
                   jp.property_id, jp.company_id, jp.price_per_visit,
                   jp.contract_id,
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
    public static function getPlanDetails(int $planId): ?array {
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
    public static function getPlanVisits(int $planId, ?string $status = null, int $limit = 50, int $offset = 0): array {
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

    /**
     * Pause a plan. Cancels all future scheduled visits.
     */
    public static function pausePlan(int $planId, int $userId, string $reason = ''): bool {
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
    public static function resumePlan(int $planId, int $userId): bool {
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
    public static function propagatePlanChanges(int $planId, array $changes, int $userId): void {
        $db = getDB();

        $built = self::buildPropagationSet($changes);
        if (empty($built['set'])) return;

        $params = $built['params'];
        $params[] = $planId;

        $stmt = $db->prepare("
            UPDATE job_visits
            SET " . implode(', ', $built['set']) . "
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

    /**
     * Skip a specific visit date (weather, holiday, etc.)
     */
    public static function skipVisitDate(int $planId, string $date, int $userId, string $reason = ''): bool {
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
    public static function moveVisit(int $visitId, string $newDate, ?string $newTimeStart, int $userId): bool {
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
            $built = self::buildMoveVisitSet($newDate, (int)$newStopId, $newTimeStart);
            $params = $built['params'];
            $params[] = $visitId;

            $stmt = $db->prepare("
                UPDATE job_visits SET " . implode(', ', $built['set']) . " WHERE id = ?
            ");
            $stmt->execute($params);

            return true;
        } catch (Exception $e) {
            error_log("moveVisit error: " . $e->getMessage());
            return false;
        }
    }

    /**
     * Check if a visit is eligible for invoicing.
     * Replaces canInvoiceJob().
     */
    public static function canInvoiceVisit(int $visitId): array {
        $db = getDB();

        $visit = self::getVisitWithPlan($visitId);
        if (!$visit) {
            return ['can_invoice' => false, 'missing_requirements' => ['Visit not found'], 'photos_count' => 0];
        }

        $missing = [];

        // Check checklist completion if required
        if ($visit['checklist_blocks_completion'] && !empty($visit['checklist_template'])) {
            $template = json_decode($visit['checklist_template'], true) ?: [];
            $completed = json_decode($visit['checklist_completed'], true) ?: [];
            $missing = array_merge($missing, self::computeMissingChecklist($template, $completed));
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

            $missing = array_merge($missing, self::computeMissingPhotos($requiredTypes, $uploadedTypes));
        } else {
            $pStmt = $db->prepare("SELECT COUNT(*) FROM visit_photos WHERE visit_id = ?");
            $pStmt->execute([$visitId]);
            $photoCount = (int)$pStmt->fetchColumn();
        }

        // Contract-billed plans are invoiced automatically through the contract —
        // never via a per-visit invoice.
        if (!empty($visit['contract_id'])) {
            require_once APP_ROOT . '/Modules/Contracts/Services/ContractService.php';
            $contractSvc = new ContractService($db);
            if ($contractSvc->isPlanContractBilled((int)$visit['plan_id'])) {
                $missing[] = 'This visit is billed under an active contract — invoice via the contract, not per-visit.';
            }
        }

        return [
            'can_invoice' => empty($missing),
            'missing_requirements' => $missing,
            'photos_count' => $photoCount,
        ];
    }

    // =========================================================================
    // PURE decision methods (unit-tested — no DB)
    // =========================================================================

    /** True if $status is one of the six valid visit statuses. */
    public static function isValidVisitStatus(string $status): bool {
        return in_array($status, self::VALID_VISIT_STATUSES, true);
    }

    /**
     * Build the SET clauses + params for a visit status update (excluding the
     * trailing WHERE id placeholder, which the caller appends).
     *
     * @return array ['set' => string[], 'params' => mixed[]]
     */
    public static function buildStatusSetClauses(string $newStatus, ?string $notes): array {
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

        return ['set' => $setClauses, 'params' => $params];
    }

    /**
     * Decide the calendar_stop status implied by its visits' resolution counts.
     * PURE — no DB; the persistence guards live in propagateStopStatus().
     *
     *   pending > 0, completed = 0  → null         (still scheduled, untouched)
     *   pending > 0, completed > 0  → 'in_progress' (some done, some left)
     *   pending = 0, completed > 0  → 'completed'   (all serviced)
     *   pending = 0, completed = 0  → 'skipped'     (all skipped/cancelled)
     *
     * @return string|null One of 'in_progress'|'completed'|'skipped', or null
     *                     when the stop should be left as-is.
     */
    public static function computeStopStatusFromCounts(int $pending, int $completed): ?string {
        if ($pending === 0) {
            return $completed > 0 ? 'completed' : 'skipped';
        }
        return $completed > 0 ? 'in_progress' : null;
    }

    /**
     * Build the SET clauses + params for propagating plan defaults onto future
     * scheduled visits. Only the allowed plan fields present in $changes are mapped.
     *
     * @return array ['set' => string[], 'params' => mixed[]]
     */
    public static function buildPropagationSet(array $changes): array {
        $setClauses = [];
        $params = [];

        foreach (self::PROPAGATION_FIELDS as $visitCol => $planCol) {
            if (array_key_exists($planCol, $changes)) {
                $setClauses[] = "{$visitCol} = ?";
                $params[] = $changes[$planCol];
            }
        }

        return ['set' => $setClauses, 'params' => $params];
    }

    /**
     * Build the SET clauses + params for moving a visit. scheduled_date is always
     * updated; stop_id only when a valid (>0) stop was resolved; time only if given.
     *
     * @return array ['set' => string[], 'params' => mixed[]]
     */
    public static function buildMoveVisitSet(string $newDate, int $newStopId, ?string $newTimeStart): array {
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
        return ['set' => $setClauses, 'params' => $params];
    }

    /**
     * Given a checklist template (list of item names) and a completed map,
     * return the "Checklist: X" labels for items not yet completed.
     */
    public static function computeMissingChecklist(array $template, array $completed): array {
        $missing = [];
        foreach ($template as $item) {
            if (empty($completed[$item])) {
                $missing[] = "Checklist: {$item}";
            }
        }
        return $missing;
    }

    /**
     * Given required photo types and a map of uploaded counts by type, return the
     * "Photo: X" labels for types with no uploads.
     */
    public static function computeMissingPhotos(array $requiredTypes, array $uploadedCounts): array {
        $missing = [];
        foreach ($requiredTypes as $type) {
            if (empty($uploadedCounts[$type])) {
                $missing[] = "Photo: {$type}";
            }
        }
        return $missing;
    }
}

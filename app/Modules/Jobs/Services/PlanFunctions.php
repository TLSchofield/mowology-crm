<?php
/**
 * Job Plan / Visit / Calendar Stop Functions
 * /crm/includes/plan-functions.php
 *
 * Core functions for the job model:
 *   job_plans       → the service agreement / contract
 *   plan_line_items → what services are included in each visit
 *   calendar_stops  → one card per property per day per crew
 *   job_visits      → one occurrence of work
 *
 * Migrated from: public/crm/includes/plan-functions.php
 */

// ============================================================================
// TIME HELPERS
// ============================================================================

/**
 * Convert a HH:MM or HH:MM:SS time string to total minutes.
 * Returns 0 for empty/invalid input.
 */
function planTimeStringToMinutes(string $t): int {
    $t = trim($t);
    if ($t === '') return 0;
    $parts = explode(':', $t);
    return ((int)($parts[0] ?? 0)) * 60 + ((int)($parts[1] ?? 0));
}

/**
 * Convert total minutes back to a HH:MM time string.
 * Clamps to 23:59 to avoid overflowing midnight.
 */
function planMinutesToTimeString(int $minutes): string {
    $h = (int)floor($minutes / 60);
    $m = $minutes % 60;
    return sprintf('%02d:%02d', min($h, 23), max(0, $m));
}

// ============================================================================
// HORIZON CHECK
// ============================================================================

/**
 * Quick read-only check: are all active recurring plans generated far enough ahead?
 *
 * Returns true  → horizon is current (cron ran recently enough).
 * Returns false → at least one plan needs visit generation.
 *
 * "Current" means every active recurring plan has visits_generated_through
 * at least 14 days into the future. This is a conservative threshold:
 * the cron runs every 6 hours and generates 42 days ahead, so 14 days
 * gives plenty of run-time buffer before the schedule page would ever
 * show missing stops.
 *
 * This function does a single COUNT(*) — no writes, no plan iteration.
 */
function isVisitHorizonCurrent(): bool {
    try {
        $db = getDB();
        $minHorizon = date('Y-m-d', strtotime('+14 days'));
        $stmt = $db->prepare("
            SELECT COUNT(*) FROM job_plans
            WHERE status = 'active'
              AND is_recurring = 1
              AND (
                visits_generated_through IS NULL
                OR visits_generated_through < ?
              )
        ");
        $stmt->execute([$minHorizon]);
        return ((int)$stmt->fetchColumn()) === 0;
    } catch (Exception $e) {
        // On any DB error, report stale so the cron knows to run
        error_log('[isVisitHorizonCurrent] ' . $e->getMessage());
        return false;
    }
}

// ============================================================================
// NUMBER GENERATORS
// ============================================================================

/**
 * Generate a unique plan number: PLN-YYYY-NNNN
 */
function generatePlanNumber(): string {
    $db = getDB();
    $year = date('Y');
    $prefix = "PLN-{$year}-";

    $stmt = $db->prepare("
        SELECT MAX(CAST(SUBSTRING(plan_number, ?) AS UNSIGNED)) as max_num
        FROM job_plans
        WHERE plan_number LIKE ?
    ");
    $stmt->execute([strlen($prefix) + 1, $prefix . '%']);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    $next = ($row['max_num'] ?? 0) + 1;

    return $prefix . str_pad((string)$next, 4, '0', STR_PAD_LEFT);
}

/**
 * Generate a visit number: PLN-2026-0001-V001
 */
function generateVisitNumber(string $planNumber, int $sequenceIndex): string {
    return $planNumber . '-V' . str_pad((string)$sequenceIndex, 3, '0', STR_PAD_LEFT);
}

// ============================================================================
// PLAN CRUD
// ============================================================================

/**
 * Create a job plan (service agreement).
 *
 * @param array $planData Keys: property_id, company_id, title, service_type,
 *   description, service_package_id, quote_id, is_recurring, recurrence_pattern,
 *   recurrence_interval, recurrence_interval_unit, recurrence_day_of_week,
 *   plan_start_date, plan_end_date, pricing_model, price_per_visit,
 *   estimated_amount, default_crew_id, estimated_duration_minutes,
 *   default_time_start, default_time_end, horizon_days,
 *   checklist_template, photo_types_required, gps_enforcement,
 *   checklist_blocks_completion, photos_block_completion
 * @param int $userId
 * @return array ['success' => bool, 'plan_id' => int|null, 'plan_number' => string|null, 'errors' => []]
 */
function createJobPlan(array $planData, int $userId): array {
    $db = getDB();
    $errors = [];

    // Validate required fields
    if (empty($planData['property_id'])) $errors[] = 'Property is required.';
    if (empty($planData['title'])) $errors[] = 'Title is required.';
    if (empty($planData['service_type'])) $errors[] = 'Service type is required.';

    if (!empty($errors)) {
        return ['success' => false, 'plan_id' => null, 'plan_number' => null, 'errors' => $errors];
    }

    try {
        $db->beginTransaction();

        $planNumber = generatePlanNumber();

        // Get company_id from property if not provided
        $companyId = !empty($planData['company_id']) ? (int)$planData['company_id'] : null;
        if (!$companyId) {
            $stmt = $db->prepare("
                SELECT cp.company_id FROM company_properties cp WHERE cp.property_id = ? LIMIT 1
            ");
            $stmt->execute([$planData['property_id']]);
            $found = $stmt->fetchColumn();
            $companyId = $found ? (int)$found : null;
        }

        // If service_package_id provided, inherit proof-of-work template
        $checklistTemplate = $planData['checklist_template'] ?? null;
        $photoTypesRequired = $planData['photo_types_required'] ?? null;
        $gpsEnforcement = $planData['gps_enforcement'] ?? 'optional';
        $checklistBlocks = $planData['checklist_blocks_completion'] ?? 0;
        $photosBlock = $planData['photos_block_completion'] ?? 0;

        if (!empty($planData['service_package_id']) && !$checklistTemplate) {
            $spStmt = $db->prepare("
                SELECT checklist_items, photo_types_required, gps_enforcement,
                       checklist_blocks_completion, photos_block_completion
                FROM service_packages WHERE id = ?
            ");
            $spStmt->execute([$planData['service_package_id']]);
            $sp = $spStmt->fetch(PDO::FETCH_ASSOC);
            if ($sp) {
                $checklistTemplate = $sp['checklist_items'];
                $photoTypesRequired = $sp['photo_types_required'];
                $gpsEnforcement = $sp['gps_enforcement'] ?: 'optional';
                $checklistBlocks = $sp['checklist_blocks_completion'] ?? 0;
                $photosBlock = $sp['photos_block_completion'] ?? 0;
            }
        }

        $isRecurring = !empty($planData['is_recurring']) ? 1 : 0;

        $stmt = $db->prepare("
            INSERT INTO job_plans (
                plan_number, quote_id, contract_id, property_id, company_id,
                title, description, service_type, service_package_id, billing_template_id,
                pricing_model, price_per_visit, monthly_flat_price, seasonal_price, estimated_amount,
                invoice_timing,
                checklist_template, photo_types_required, gps_enforcement,
                checklist_blocks_completion, photos_block_completion,
                is_recurring, recurrence_pattern, recurrence_interval,
                recurrence_interval_unit, recurrence_day_of_week,
                plan_start_date, plan_end_date, blackout_dates,
                default_crew_id, default_crew_size, estimated_duration_minutes,
                default_time_start, default_time_end,
                horizon_days, is_prepaid_bundle, source_bundle_id, status, created_by
            ) VALUES (
                ?, ?, ?, ?, ?,
                ?, ?, ?, ?, ?,
                ?, ?, ?, ?, ?,
                ?,
                ?, ?, ?,
                ?, ?,
                ?, ?, ?,
                ?, ?,
                ?, ?, ?,
                ?, ?, ?,
                ?, ?,
                ?, ?, ?, 'active', ?
            )
        ");

        $stmt->execute([
            $planNumber,
            $planData['quote_id'] ?? null,
            !empty($planData['contract_id']) ? (int)$planData['contract_id'] : null,
            $planData['property_id'],
            $companyId,
            trim($planData['title']),
            trim($planData['description'] ?? ''),
            $planData['service_type'],
            $planData['service_package_id'] ?? null,
            $planData['billing_template_id'] ?? null,
            $planData['pricing_model'] ?? 'per_visit',
            $planData['price_per_visit'] ?? null,
            $planData['monthly_flat_price'] ?? null,
            $planData['seasonal_price'] ?? null,
            $planData['estimated_amount'] ?? null,
            in_array($planData['invoice_timing'] ?? '', ['after_visit','end_of_month','upfront'], true) ? $planData['invoice_timing'] : 'after_visit',
            $checklistTemplate,
            $photoTypesRequired,
            $gpsEnforcement,
            $checklistBlocks,
            $photosBlock,
            $isRecurring,
            $isRecurring ? ($planData['recurrence_pattern'] ?? 'weekly') : null,
            $planData['recurrence_interval'] ?? 1,
            $planData['recurrence_interval_unit'] ?? 'weeks',
            $planData['recurrence_day_of_week'] ?? null,
            $planData['plan_start_date'] ?? date('Y-m-d'),
            $planData['plan_end_date'] ?? null,
            $planData['blackout_dates'] ?? null,
            $planData['default_crew_id'] ?? null,
            $planData['default_crew_size'] ?? 1,
            $planData['estimated_duration_minutes'] ?? 60,
            $planData['default_time_start'] ?? null,
            $planData['default_time_end'] ?? null,
            $planData['horizon_days'] ?? 28,
            !empty($planData['is_prepaid_bundle']) ? 1 : 0,
            $planData['source_bundle_id'] ?? null,
            $userId
        ]);

        $planId = (int)$db->lastInsertId();

        // Update property status to active
        $stmt = $db->prepare("UPDATE properties SET status = 'active' WHERE id = ?");
        $stmt->execute([$planData['property_id']]);

        // Insert line items if provided
        if (!empty($planData['line_items'])) {
            addPlanLineItems($planId, $planData['line_items']);
            updatePlanTotalFromItems($planId);
        }

        $db->commit();

        // Insert crew assignments if provided (outside transaction — non-critical)
        if (!empty($planData['crew_ids']) && is_array($planData['crew_ids'])) {
            setPlanCrewAssignments($planId, $planData['crew_ids'], $planData['default_crew_id'] ?? null);
        }

        // Generate initial visits (outside transaction for clarity)
        if (!empty($planData['is_prepaid_bundle']) && !empty($planData['fertilizer_dates'])) {
            generateFertilizerVisits($planId, $planData['fertilizer_dates']);
        } else {
            generateVisits($planId);
        }

        return ['success' => true, 'plan_id' => $planId, 'plan_number' => $planNumber, 'errors' => []];

    } catch (Exception $e) {
        if ($db->inTransaction()) $db->rollBack();
        error_log("createJobPlan error: " . $e->getMessage());
        return ['success' => false, 'plan_id' => null, 'plan_number' => null, 'errors' => ['Error creating plan: ' . $e->getMessage()]];
    }
}

/**
 * Create a job plan from an accepted quote.
 * Replaces createJobFromQuote().
 */
function createPlanFromQuote(int $quoteId, int $userId): array {
    $db = getDB();

    // Get accepted quote
    $stmt = $db->prepare("
        SELECT q.*, p.address, c.company_name, c.id as comp_id
        FROM quotes q
        JOIN properties p ON q.property_id = p.id
        LEFT JOIN companies c ON q.company_id = c.id
        WHERE q.id = ? AND q.status = 'accepted'
    ");
    $stmt->execute([$quoteId]);
    $quote = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$quote) {
        return ['success' => false, 'plan_id' => null, 'plan_number' => null, 'errors' => ['Quote not found or not accepted.']];
    }

    // Allocation-aware conversion. A quote's line items are *assigned* to plans, and
    // each item is converted at most once. Pull only the items NOT yet assigned to a
    // still-existing plan — the LEFT JOIN treats items whose plan was later deleted as
    // available again, so they are never orphaned. This makes duplicate plans
    // impossible by construction: once every item is assigned there is nothing left to
    // convert. The per-line-item multi-plan workflow (create-from-quote.php) shares the
    // same quote_line_items.plan_id model.
    $uaStmt = $db->prepare("
        SELECT qli.id, qli.service_type, qli.description, qli.quantity,
               qli.unit_type, qli.unit_price, qli.line_total, qli.sort_order
        FROM quote_line_items qli
        LEFT JOIN job_plans jp ON jp.id = qli.plan_id
        WHERE qli.quote_id = ? AND (qli.plan_id IS NULL OR jp.id IS NULL)
        ORDER BY qli.sort_order, qli.id
    ");
    $uaStmt->execute([$quoteId]);
    $unallocated = $uaStmt->fetchAll(PDO::FETCH_ASSOC);

    if (empty($unallocated)) {
        // Every line item is already assigned — nothing to convert. Hand the caller
        // the existing plans so the UI can route the user to them instead of duplicating.
        return [
            'success'         => false,
            'fully_allocated' => true,
            'plan_id'         => null,
            'plan_number'     => null,
            'plans'           => getPlansForQuote($quoteId),
            'errors'          => ['Every service on this quote is already assigned to a plan.'],
        ];
    }

    // Build the plan's line items from the unallocated quote items. createJobPlan()
    // inserts them, marks each source quote item as assigned (quote_line_items.plan_id),
    // and recomputes price_per_visit/estimated_amount from the item totals — so the plan
    // price reflects exactly the services it covers, not the whole quote.
    $planItems = array_map(function ($qi) {
        return [
            'quote_line_item_id' => (int)$qi['id'],
            'service_type'       => $qi['service_type'] ?? 'Service',
            'description'        => $qi['description'] ?? '',
            'quantity'           => $qi['quantity'],
            'unit_type'          => $qi['unit_type'] ?? 'visit',
            'unit_price'         => $qi['unit_price'],
            'line_total'         => $qi['line_total'],
            'sort_order'         => (int)($qi['sort_order'] ?? 0),
        ];
    }, $unallocated);

    // Net total of just these items (pre-GST line_totals) — used for ROI attribution.
    $planNet = array_sum(array_map(fn($qi) => (float)($qi['line_total'] ?? 0), $unallocated));

    $planData = [
        'quote_id'         => $quoteId,
        'property_id'      => $quote['property_id'],
        'company_id'       => $quote['company_id'],
        'title'            => $quote['title'] ?: 'Plan from ' . $quote['quote_number'],
        'description'      => $quote['description'],
        'service_type'     => $unallocated[0]['service_type'] ?: ($quote['service_type'] ?? 'landscaping'),
        'plan_start_date'  => date('Y-m-d'),
        'is_recurring'     => 0,
        'line_items'       => $planItems,
    ];

    $result = createJobPlan($planData, $userId);

    if ($result['success']) {
        // ROI attribution
        if (function_exists('createROIAttribution')) {
            $leadEventId = !empty($quote['lead_event_id']) ? (int)$quote['lead_event_id'] : null;

            $quoteSource = 'website';
            try {
                $quoteStmt = $db->prepare("
                    SELECT source FROM quote_requests
                    WHERE id IN (SELECT quote_request_id FROM quotes WHERE id = ?)
                    LIMIT 1
                ");
                $quoteStmt->execute([$quoteId]);
                $quoteSource = $quoteStmt->fetchColumn() ?: 'website';
            } catch (PDOException $e) {
                // quote_request_id column may not exist on production
            }

            createROIAttribution($result['plan_id'], $leadEventId, $quoteSource, $planNet);
        }

        // Log conversion
        if (function_exists('logConversionEvent')) {
            $conversionLeadId = !empty($quote['lead_event_id']) ? (int)$quote['lead_event_id'] : 0;
            logConversionEvent($conversionLeadId, 'job_created', $result['plan_id']);
        }

        // Activity log
        if (function_exists('logActivityExtended')) {
            logActivityExtended(
                $userId,
                'Plan created from quote',
                "Plan {$result['plan_number']} created from quote {$quote['quote_number']}",
                $quote['company_id'],
                null, // job_id (legacy)
                $quoteId,
                null, // invoice_id
                $result['plan_id']
            );
        }
    }

    return $result;
}


// ============================================================================
// PLAN LINE ITEMS
// ============================================================================

/**
 * Add line items to a plan.
 *
 * @param int   $planId
 * @param array $items Each item: ['service_type', 'description', 'quantity',
 *                      'unit_type', 'unit_price', 'line_total', 'sort_order',
 *                      'quote_line_item_id']
 * @return bool
 */
function addPlanLineItems(int $planId, array $items): bool {
    $db = getDB();

    // Check if product_id column exists
    $hasProductId = false;
    try {
        $chk = $db->query("SHOW COLUMNS FROM plan_line_items LIKE 'product_id'");
        $hasProductId = ($chk->rowCount() > 0);
    } catch (Exception $e) { /* ignore */ }

    if ($hasProductId) {
        $stmt = $db->prepare("
            INSERT INTO plan_line_items
                (plan_id, quote_line_item_id, product_id, service_type, description, quantity,
                 unit_type, unit_price, line_total, sort_order)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
        ");
    } else {
        $stmt = $db->prepare("
            INSERT INTO plan_line_items
                (plan_id, quote_line_item_id, service_type, description, quantity,
                 unit_type, unit_price, line_total, sort_order)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)
        ");
    }

    $sortOrder = 0;
    foreach ($items as $item) {
        $qty = floatval($item['quantity'] ?? 1);
        $unitPrice = floatval($item['unit_price'] ?? 0);
        $lineTotal = floatval($item['line_total'] ?? ($qty * $unitPrice));

        $productId = $item['product_id'] ?? null;

        // If no product_id provided, try to resolve from service_type name
        if (!$productId && !empty($item['service_type'])) {
            $lookup = $db->prepare("SELECT id FROM products WHERE LOWER(TRIM(name)) = LOWER(TRIM(? COLLATE utf8mb4_general_ci)) LIMIT 1");
            $lookup->execute([$item['service_type']]);
            $found = $lookup->fetchColumn();
            if ($found) $productId = (int)$found;
        }

        if ($hasProductId) {
            $stmt->execute([
                $planId,
                $item['quote_line_item_id'] ?? null,
                $productId,
                $item['service_type'] ?? 'Service',
                $item['description'] ?? '',
                $qty,
                $item['unit_type'] ?? 'visit',
                $unitPrice,
                $lineTotal,
                $item['sort_order'] ?? $sortOrder,
            ]);
        } else {
            $stmt->execute([
                $planId,
                $item['quote_line_item_id'] ?? null,
                $item['service_type'] ?? 'Service',
                $item['description'] ?? '',
                $qty,
                $item['unit_type'] ?? 'visit',
                $unitPrice,
                $lineTotal,
                $item['sort_order'] ?? $sortOrder,
            ]);
        }

        // Mark the source quote line item as converted
        if (!empty($item['quote_line_item_id'])) {
            $upStmt = $db->prepare("UPDATE quote_line_items SET plan_id = ? WHERE id = ?");
            $upStmt->execute([$planId, $item['quote_line_item_id']]);
        }

        $sortOrder++;
    }

    return true;
}

/**
 * Get line items for a plan.
 */
function getPlanLineItems(int $planId): array {
    $db = getDB();
    $stmt = $db->prepare("
        SELECT pli.*, qli.quote_id
        FROM plan_line_items pli
        LEFT JOIN quote_line_items qli ON pli.quote_line_item_id = qli.id
        WHERE pli.plan_id = ?
        ORDER BY pli.sort_order, pli.id
    ");
    $stmt->execute([$planId]);
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

/**
 * Recalculate plan price_per_visit from its line items.
 */
function updatePlanTotalFromItems(int $planId): void {
    $db = getDB();
    $stmt = $db->prepare("
        SELECT COALESCE(SUM(line_total), 0) AS total
        FROM plan_line_items
        WHERE plan_id = ?
    ");
    $stmt->execute([$planId]);
    $total = floatval($stmt->fetchColumn());

    $upStmt = $db->prepare("
        UPDATE job_plans SET price_per_visit = ?, estimated_amount = ? WHERE id = ?
    ");
    $upStmt->execute([$total, $total, $planId]);
}

/**
 * Get the next scheduled visit date at a property (for "align with existing visit").
 */
function getNextScheduledVisitDate(int $propertyId): ?string {
    $db = getDB();
    $stmt = $db->prepare("
        SELECT jv.scheduled_date
        FROM job_visits jv
        JOIN job_plans jp ON jv.plan_id = jp.id
        WHERE jp.property_id = ?
          AND jv.status = 'scheduled'
          AND jv.scheduled_date >= CURDATE()
        ORDER BY jv.scheduled_date ASC
        LIMIT 1
    ");
    $stmt->execute([$propertyId]);
    $date = $stmt->fetchColumn();
    return $date ?: null;
}

/**
 * Get quote line items with their conversion status.
 * Returns all items for a quote, marking which have been converted to plans.
 */
function getQuoteLineItemsWithStatus(int $quoteId): array {
    $db = getDB();
    $stmt = $db->prepare("
        SELECT qli.*,
               jp.plan_number,
               jp.id AS converted_plan_id
        FROM quote_line_items qli
        LEFT JOIN job_plans jp ON qli.plan_id = jp.id
        WHERE qli.quote_id = ?
        ORDER BY qli.sort_order, qli.id
    ");
    $stmt->execute([$quoteId]);
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

/**
 * Get the plans created from a quote (existing plans only — deleted plans are
 * naturally excluded since this reads job_plans directly). Used to render the
 * quote's plan-allocation coverage view.
 *
 * @return array of ['id','plan_number','title','service_type','status','price_per_visit']
 */
function getPlansForQuote(int $quoteId): array {
    $db = getDB();
    $stmt = $db->prepare("
        SELECT id, plan_number, title, service_type, status, price_per_visit
        FROM job_plans
        WHERE quote_id = ?
        ORDER BY id
    ");
    $stmt->execute([$quoteId]);
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}


// ============================================================================
// VISIT GENERATOR
// ============================================================================

/**
 * Generate visits for active plans within their rolling horizon.
 *
 * For each active recurring plan: ensures visits exist from today through
 * today + horizon_days. For one-time plans: creates a single visit if none exists.
 *
 * Dedup via UNIQUE(plan_id, scheduled_date, sequence_index).
 *
 * @param int|null $planId Specific plan, or NULL for all active plans
 * @param int|null $horizonDays Override the plan's horizon_days
 * @return array ['plans_processed' => int, 'visits_created' => int, 'errors' => []]
 */
function generateVisits(?int $planId = null, ?int $horizonDays = null): array {
    $db = getDB();
    $plansProcessed = 0;
    $visitsCreated = 0;
    $errors = [];

    try {
        // Get plans to process
        $sql = "SELECT * FROM job_plans WHERE status = 'active'";
        $params = [];
        if ($planId !== null) {
            $sql .= " AND id = ?";
            $params[] = $planId;
        }
        $stmt = $db->prepare($sql);
        $stmt->execute($params);
        $plans = $stmt->fetchAll(PDO::FETCH_ASSOC);

        // Batch-fetch crew assignments for all plans so we can sync calendar_stop_crew
        $planCrewMap = []; // plan_id => [user_id, ...]
        if (!empty($plans)) {
            $planIds = array_column($plans, 'id');
            $ph = implode(',', array_fill(0, count($planIds), '?'));
            $pcStmt = $db->prepare("
                SELECT plan_id, user_id FROM plan_crew_assignments
                WHERE plan_id IN ({$ph})
                ORDER BY FIELD(role, 'lead', 'crew'), user_id
            ");
            $pcStmt->execute($planIds);
            foreach ($pcStmt->fetchAll(PDO::FETCH_ASSOC) as $pcr) {
                $planCrewMap[(int)$pcr['plan_id']][] = (int)$pcr['user_id'];
            }
        }

        // Fetch global holidays once for the full horizon window
        $maxHorizon = $horizonDays ?? 42;
        $holidayFrom = date('Y-m-d');
        $holidayTo = date('Y-m-d', strtotime("+{$maxHorizon} days"));
        // Extend range 7 days back to support bump lookups near window start
        $holidayFromExtended = date('Y-m-d', strtotime("-7 days"));
        $holidays = [];
        try {
            $holidays = getActiveHolidays($holidayFromExtended, $holidayTo);
        } catch (Exception $e) {
            // Table may not exist yet — continue without holidays
            error_log("getActiveHolidays: " . $e->getMessage());
        }

        foreach ($plans as $plan) {
            try {
                // Remove visits that predate the plan's start date — orphaned when
                // a plan is edited to start later or change its recurrence day.
                cleanupOrphanedVisits((int)$plan['id']);

                $horizon = $horizonDays ?? (int)$plan['horizon_days'];
                $today = new DateTime('today');
                $toDate = (clone $today)->modify("+{$horizon} days");

                // Don't generate past the plan end date
                if ($plan['plan_end_date']) {
                    $endDate = new DateTime($plan['plan_end_date']);
                    if ($toDate > $endDate) {
                        $toDate = $endDate;
                    }
                }

                if ($plan['is_recurring']) {
                    // Determine the from-date for this generation pass.
                    //
                    // Incremental mode (visits_generated_through is set): start from
                    // where we last left off, never before today.
                    //
                    // Fresh/reset mode (visits_generated_through is NULL, e.g. after a
                    // recurrence edit): start from plan_start_date so that visits between
                    // the new start date and today are not silently skipped. Cap the
                    // lookback at 90 days to avoid rebuilding years of history.
                    if ($plan['visits_generated_through']) {
                        $fromDate = clone $today;
                        $genThrough = new DateTime($plan['visits_generated_through']);
                        $genThrough->modify('+1 day');
                        if ($genThrough > $fromDate) $fromDate = $genThrough;
                    } else {
                        $ninetyDaysAgo = (clone $today)->modify('-90 days');
                        if ($plan['plan_start_date']) {
                            $planStart = new DateTime($plan['plan_start_date']);
                            $fromDate  = $planStart < $ninetyDaysAgo ? $ninetyDaysAgo : $planStart;
                        } else {
                            $fromDate = clone $today;
                        }
                    }

                    if ($fromDate > $toDate) {
                        $plansProcessed++;
                        continue; // Already generated up to horizon
                    }

                    $dates = calculateRecurrenceDates($plan, $fromDate->format('Y-m-d'), $toDate->format('Y-m-d'), $holidays);
                } else {
                    // One-time plan: single visit on plan_start_date
                    $visitDate = $plan['plan_start_date'] ?: date('Y-m-d');
                    // Check if a non-cancelled visit already exists (cancelled visits
                    // don't count — the plan still needs its active visit)
                    $checkStmt = $db->prepare("SELECT COUNT(*) FROM job_visits WHERE plan_id = ? AND status != 'cancelled'");
                    $checkStmt->execute([$plan['id']]);
                    if ((int)$checkStmt->fetchColumn() > 0) {
                        $plansProcessed++;
                        continue; // One-time already has its active visit
                    }
                    $dates = [$visitDate];
                }

                // Get next sequence index
                $seqStmt = $db->prepare("SELECT MAX(sequence_index) FROM job_visits WHERE plan_id = ?");
                $seqStmt->execute([$plan['id']]);
                $nextSeq = ((int)$seqStmt->fetchColumn()) + 1;

                foreach ($dates as $date) {
                    // Derive estimated arrival and departure from plan defaults
                    $estArrival   = $plan['default_time_start'] ?: null;
                    $estDeparture = null;
                    if ($estArrival && !empty($plan['estimated_duration_minutes'])) {
                        $arrivalMins   = planTimeStringToMinutes($estArrival);
                        $departureMins = $arrivalMins + (int)$plan['estimated_duration_minutes'];
                        $estDeparture  = planMinutesToTimeString($departureMins);
                    }

                    // Ensure calendar stop exists (populates times on insert, preserves manual edits on dup)
                    $stopId = ensureCalendarStop(
                        (int)$plan['property_id'],
                        $date,
                        $plan['default_crew_id'] ? (int)$plan['default_crew_id'] : null,
                        $estArrival,
                        $estDeparture
                    );

                    // Sync junction table: insert all plan crew into calendar_stop_crew (INSERT IGNORE
                    // so we don't overwrite crew manually set via the assign-crew modal)
                    if ($stopId > 0) {
                        $planCrew = $planCrewMap[(int)$plan['id']] ?? ($plan['default_crew_id'] ? [(int)$plan['default_crew_id']] : []);
                        if (!empty($planCrew)) {
                            $jInsStmt = $db->prepare("INSERT IGNORE INTO calendar_stop_crew (stop_id, user_id) VALUES (?, ?)");
                            foreach ($planCrew as $crewUserId) {
                                $jInsStmt->execute([$stopId, $crewUserId]);
                            }
                        }
                    }

                    $visitNumber = generateVisitNumber($plan['plan_number'], $nextSeq);

                    // INSERT IGNORE for dedup (UNIQUE constraint on plan_id + date + seq)
                    $insStmt = $db->prepare("
                        INSERT IGNORE INTO job_visits (
                            visit_number, plan_id, stop_id,
                            scheduled_date, scheduled_time_start, scheduled_time_end,
                            sequence_index, assigned_crew_id, status
                        ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, 'scheduled')
                    ");
                    $insStmt->execute([
                        $visitNumber,
                        $plan['id'],
                        $stopId,
                        $date,
                        $plan['default_time_start'],
                        $plan['default_time_end'],
                        $nextSeq,
                        $plan['default_crew_id']
                    ]);

                    if ($insStmt->rowCount() > 0) {
                        $visitsCreated++;
                    }
                    $nextSeq++;
                }

                // Update generation watermark
                $upStmt = $db->prepare("
                    UPDATE job_plans SET visits_generated_through = ? WHERE id = ?
                ");
                $upStmt->execute([$toDate->format('Y-m-d'), $plan['id']]);

                $plansProcessed++;

            } catch (Exception $e) {
                $errors[] = "Plan {$plan['plan_number']}: " . $e->getMessage();
                error_log("generateVisits error for plan {$plan['id']}: " . $e->getMessage());
            }
        }

    } catch (Exception $e) {
        $errors[] = $e->getMessage();
        error_log("generateVisits error: " . $e->getMessage());
    }

    return ['plans_processed' => $plansProcessed, 'visits_created' => $visitsCreated, 'errors' => $errors];
}

/**
 * Fetch active company holidays for a date range.
 * Returns lookup array: ['2026-07-01' => 'Canada Day', ...]
 * Handles is_annual holidays by matching month+day across years.
 */
function getActiveHolidays(string $fromDate, string $toDate): array {
    $db = getDB();
    $holidays = [];

    // Exact date matches (non-annual or annual with matching year)
    $stmt = $db->prepare("
        SELECT holiday_date, name
        FROM company_holidays
        WHERE is_active = 1
          AND holiday_date BETWEEN ? AND ?
    ");
    $stmt->execute([$fromDate, $toDate]);
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $holidays[$row['holiday_date']] = $row['name'];
    }

    // Annual holidays: generate dates for each year in range
    $annualStmt = $db->prepare("
        SELECT holiday_date, name
        FROM company_holidays
        WHERE is_active = 1 AND is_annual = 1
    ");
    $annualStmt->execute();
    $annuals = $annualStmt->fetchAll(PDO::FETCH_ASSOC);

    if ($annuals) {
        $startYear = (int)substr($fromDate, 0, 4);
        $endYear = (int)substr($toDate, 0, 4);
        foreach ($annuals as $row) {
            $md = substr($row['holiday_date'], 5); // MM-DD
            for ($y = $startYear; $y <= $endYear; $y++) {
                $genDate = $y . '-' . $md;
                if ($genDate >= $fromDate && $genDate <= $toDate) {
                    $holidays[$genDate] = $row['name'];
                }
            }
        }
    }

    return $holidays;
}

/**
 * Find the nearest working day before a holiday for visit bumping.
 * Searches up to 7 days back, skipping weekends, holidays, and blackouts.
 * Returns YYYY-MM-DD string or null if no valid day found.
 */
function findBumpDate(string $holidayDate, array $holidays, array $blackouts): ?string {
    $dt = new DateTime($holidayDate);
    for ($i = 0; $i < 7; $i++) {
        $dt->modify('-1 day');
        $candidate = $dt->format('Y-m-d');
        $dow = (int)$dt->format('w'); // 0=Sun, 6=Sat

        // Skip weekends
        if ($dow === 0 || $dow === 6) continue;
        // Skip other holidays
        if (isset($holidays[$candidate])) continue;
        // Skip per-plan blackouts
        if (isset($blackouts[$candidate])) continue;

        return $candidate;
    }
    return null; // No valid day found (extremely unlikely)
}

/**
 * Parse a recurrence_day_of_week value into an array of day integers (0=Sun..6=Sat).
 * Handles both the old single-integer format ("3") and the new multi-day format ("1,3,5").
 * Falls back to the plan's start-date day-of-week when the value is null or empty.
 */
function parseDowList($rawDow, DateTime $fallback): array {
    if ($rawDow === null || $rawDow === '') {
        return [(int)$fallback->format('w')];
    }
    $days = array_values(array_filter(
        array_unique(array_map('intval', explode(',', (string)$rawDow))),
        function (int $d): bool { return $d >= 0 && $d <= 6; }
    ));
    return !empty($days) ? $days : [(int)$fallback->format('w')];
}

/**
 * Calculate occurrence dates for a recurring plan.
 * Returns array of YYYY-MM-DD strings. Respects blackout_dates and holidays.
 * Holiday visits are bumped to the last available working day before the holiday.
 */
function calculateRecurrenceDates(array $plan, string $fromDate, string $toDate, array $holidays = []): array {
    $dates = [];
    $current = new DateTime($fromDate);
    $end = new DateTime($toDate);
    $maxDates = 200; // safety limit

    $pattern = $plan['recurrence_pattern'] ?? 'weekly';
    $interval = max(1, (int)($plan['recurrence_interval'] ?? 1));
    $intervalUnit = $plan['recurrence_interval_unit'] ?? 'weeks';
    $targetDow = $plan['recurrence_day_of_week']; // 0=Sun..6=Sat, or null

    // Parse blackout dates
    $blackouts = [];
    if (!empty($plan['blackout_dates'])) {
        $decoded = json_decode($plan['blackout_dates'], true);
        if (is_array($decoded)) {
            $blackouts = array_flip($decoded);
        }
    }

    // For weekly/biweekly, we need a reference point for interval counting
    $planStart = new DateTime($plan['plan_start_date'] ?: $fromDate);

    while ($current <= $end && count($dates) < $maxDates) {
        $shouldInclude = false;
        $currentDow = (int)$current->format('w'); // 0=Sun..6=Sat
        $dateStr = $current->format('Y-m-d');

        switch ($pattern) {
            case 'weekly':
            case 'biweekly':
                // Parse comma-separated days (supports "3" legacy or "1,3,5" multi-day)
                $targetDays = parseDowList($targetDow, $planStart);
                // Use interval from the plan (biweekly forces 2, weekly defaults to 1)
                $weekInterval = ($pattern === 'biweekly') ? max(2, $interval) : $interval;
                if (in_array($currentDow, $targetDays, true)) {
                    if ($weekInterval <= 1) {
                        $shouldInclude = true;
                    } else {
                        $diffDays = (int)$current->diff($planStart)->days;
                        $diffWeeks = (int)floor($diffDays / 7);
                        $shouldInclude = ($diffWeeks % $weekInterval === 0);
                    }
                }
                break;

            case 'monthly':
                $targetDay = (int)$planStart->format('j'); // day of month
                $currentDay = (int)$current->format('j');
                if ($currentDay === $targetDay) {
                    if ($interval <= 1) {
                        $shouldInclude = true;
                    } else {
                        $diffMonths = ((int)$current->format('Y') - (int)$planStart->format('Y')) * 12
                                    + ((int)$current->format('n') - (int)$planStart->format('n'));
                        $shouldInclude = ($diffMonths >= 0 && $diffMonths % $interval === 0);
                    }
                }
                break;

            case 'yearly':
                $targetMonth = (int)$planStart->format('n');
                $targetDayOfMonth = (int)$planStart->format('j');
                $currentMonth = (int)$current->format('n');
                $currentDayOfMonth = (int)$current->format('j');
                if ($currentMonth === $targetMonth && $currentDayOfMonth === $targetDayOfMonth) {
                    $yearDiff = (int)$current->format('Y') - (int)$planStart->format('Y');
                    $shouldInclude = ($yearDiff >= 0 && $yearDiff % $interval === 0);
                }
                break;

            case 'custom':
                // Custom interval using interval + intervalUnit
                $diff = $planStart->diff($current);
                $unitValue = 0;
                if ($intervalUnit === 'days') {
                    $unitValue = $diff->days;
                } elseif ($intervalUnit === 'weeks') {
                    $unitValue = (int)floor($diff->days / 7);
                } elseif ($intervalUnit === 'months') {
                    $unitValue = $diff->m + ($diff->y * 12);
                } elseif ($intervalUnit === 'years') {
                    $unitValue = $diff->y;
                }
                $shouldInclude = ($unitValue >= 0 && $unitValue % $interval === 0);
                // For weeks/months custom, also check day-of-week match
                if ($shouldInclude && $intervalUnit === 'weeks') {
                    $targetDays = parseDowList($targetDow, $planStart);
                    $shouldInclude = in_array($currentDow, $targetDays, true);
                }
                // For months/years custom, check day-of-month match
                if ($shouldInclude && in_array($intervalUnit, ['months', 'years'], true)) {
                    $shouldInclude = ((int)$current->format('j') === (int)$planStart->format('j'));
                }
                break;
        }

        // Check per-plan blackout (drops visit entirely — client vacation, etc.)
        if ($shouldInclude && isset($blackouts[$dateStr])) {
            $shouldInclude = false;
        }

        // Check global holidays (bump visit to last working day before)
        if ($shouldInclude && isset($holidays[$dateStr])) {
            $bumped = findBumpDate($dateStr, $holidays, $blackouts);
            if ($bumped && !in_array($bumped, $dates, true)) {
                $dates[] = $bumped;
            }
            $shouldInclude = false; // Don't add the holiday date itself
        }

        if ($shouldInclude) {
            $dates[] = $dateStr;
        }

        $current->modify('+1 day');
    }

    return $dates;
}


// ============================================================================
// CALENDAR STOPS
// ============================================================================

/**
 * Ensure a calendar_stop exists for property+date+crew.
 * Uses INSERT ... ON DUPLICATE KEY UPDATE for idempotency.
 * Returns the stop_id.
 */
/**
 * Ensure a calendar_stops row exists for the given property+date+crew.
 *
 * On INSERT: writes estimated_arrival and estimated_departure if provided.
 * On DUPLICATE KEY: preserves any existing times (COALESCE), only updates
 * them when the column is currently NULL — so manual dispatcher edits are
 * never overwritten by cron regeneration.
 *
 * @param int         $propertyId
 * @param string      $date             YYYY-MM-DD
 * @param int|null    $crewId
 * @param string|null $estimatedArrival  HH:MM derived from plan default_time_start
 * @param string|null $estimatedDeparture HH:MM derived from arrival + duration
 * @return int  calendar_stops.id
 */
function ensureCalendarStop(int $propertyId, string $date, ?int $crewId,
                            ?string $estimatedArrival = null,
                            ?string $estimatedDeparture = null): int {
    $db = getDB();

    // INSERT with time fields; on duplicate preserve existing times (COALESCE),
    // only back-fill when the column is currently NULL.
    $stmt = $db->prepare("
        INSERT INTO calendar_stops
            (property_id, stop_date, crew_id, estimated_arrival, estimated_departure, status)
        VALUES (?, ?, ?, ?, ?, 'scheduled')
        ON DUPLICATE KEY UPDATE
            estimated_arrival   = COALESCE(estimated_arrival,   VALUES(estimated_arrival)),
            estimated_departure = COALESCE(estimated_departure, VALUES(estimated_departure)),
            updated_at          = NOW()
    ");
    $stmt->execute([$propertyId, $date, $crewId, $estimatedArrival, $estimatedDeparture]);

    // Fetch the ID (works for both insert and duplicate)
    $fetchStmt = $db->prepare("
        SELECT id FROM calendar_stops
        WHERE property_id = ? AND stop_date = ? AND crew_id <=> ?
    ");
    $fetchStmt->execute([$propertyId, $date, $crewId]);
    return (int)$fetchStmt->fetchColumn();
}

/**
 * Get calendar stops with their visits for a date range.
 * Returns nested array: [date][stop_id] => {stop data + visits[]}
 *
 * @param string $startDate YYYY-MM-DD
 * @param string $endDate   YYYY-MM-DD
 * @param int|null $crewId  Filter by crew, or null for all
 * @return array
 */
function getCalendarStops(string $startDate, string $endDate, ?int $crewId = null, ?string $serviceType = null): array {
    $db = getDB();

    // Check if Obsidian Root enrollment column exists (migration 920)
    $hasOrStatus = false;
    try {
        $hasOrStatus = $db->query("SHOW COLUMNS FROM properties LIKE 'or_status'")->rowCount() > 0;
    } catch (Exception $e) { /* ignore */ }
    $orStatusSelect = $hasOrStatus ? ', p.or_status' : '';

    // Persisted route pin ('first' | 'last' | NULL) — migration 1065.
    // Guarded so the schedule still loads on environments missing the column.
    $hasRoutePin = false;
    try {
        $hasRoutePin = $db->query("SHOW COLUMNS FROM calendar_stops LIKE 'route_pin'")->rowCount() > 0;
    } catch (Exception $e) { /* ignore */ }
    $routePinSelect = $hasRoutePin ? ', cs.route_pin' : '';

    // Single query: stops with their visits.
    // Note: lawn_sqft + last_completed_date used to be computed via two
    // correlated subqueries in the SELECT list, producing O(N) extra
    // subqueries per row (~250–500 ms on a 50-stop week view on shared
    // hosting). They are now computed in two batched follow-up queries
    // after the main fetch (see below).
    $sql = "
        SELECT
            cs.id AS stop_id,
            cs.stop_date,
            cs.route_order,
            cs.crew_id,
            cs.estimated_arrival,
            cs.estimated_departure,
            cs.status AS stop_status,
            p.id AS property_id,
            p.address AS property_address,
            p.city AS property_city,
            p.latitude,
            p.longitude,
            p.property_name,
            p.total_lawn_sqft,
            p.lawn_size_sqft,
            p.notes AS property_notes,
            cs.notes AS stop_notes
            {$orStatusSelect}{$routePinSelect},
            co.company_name,
            ct.id AS contact_id,
            CONCAT(ct.first_name, ' ', ct.last_name) AS contact_name,
            ct.mobile AS contact_mobile,
            ct.phone AS contact_phone,
            u.full_name AS crew_name,
            jv.id AS visit_id,
            jv.visit_number,
            jv.status AS visit_status,
            jv.is_invoiced,
            jv.invoice_id,
            jv.scheduled_time_start,
            jv.scheduled_time_end,
            jv.assigned_crew_id,
            jv.sequence_index,
            jp.id AS plan_id,
            jp.title AS plan_title,
            jp.plan_number,
            jp.service_type,
            jp.pricing_model,
            jp.price_per_visit,
            jp.estimated_duration_minutes,
            COALESCE(jv.is_flagged, 0) AS is_flagged,
            COALESCE(ct.has_reviewed, 0) AS contact_has_reviewed
        FROM calendar_stops cs
        JOIN properties p ON cs.property_id = p.id
        LEFT JOIN company_properties cp ON p.id = cp.property_id
        LEFT JOIN companies co ON cp.company_id = co.id
        LEFT JOIN contacts ct ON p.site_contact_id = ct.id
        LEFT JOIN users u ON cs.crew_id = u.id
        LEFT JOIN job_visits jv ON jv.stop_id = cs.id AND jv.status NOT IN ('cancelled')
        LEFT JOIN job_plans jp ON jv.plan_id = jp.id
        WHERE cs.stop_date BETWEEN ? AND ?
    ";
    $params = [$startDate, $endDate];

    if ($crewId !== null) {
        // Filter: show stops where this crew is assigned (lead or additional)
        $sql .= " AND (cs.crew_id = ? OR cs.id IN (SELECT stop_id FROM calendar_stop_crew WHERE user_id = ?))";
        $params[] = $crewId;
        $params[] = $crewId;
    }

    if ($serviceType !== null) {
        // Filter: only show stops that have at least one visit with this service type
        $sql .= " AND cs.id IN (SELECT jv2.stop_id FROM job_visits jv2 JOIN job_plans jp2 ON jv2.plan_id = jp2.id WHERE jv2.stop_id IS NOT NULL AND jp2.service_type = ?)";
        $params[] = $serviceType;
    }

    $sql .= " ORDER BY cs.stop_date, cs.crew_id, cs.route_order, jv.scheduled_time_start";

    $stmt = $db->prepare($sql);
    $stmt->execute($params);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // Collect IDs for the batch follow-up queries.
    $stopIds     = [];
    $propertyIds = [];
    foreach ($rows as $row) {
        $stopIds[(int)$row['stop_id']] = true;
        if (!empty($row['property_id'])) {
            $propertyIds[(int)$row['property_id']] = true;
        }
    }
    $stopIds     = array_keys($stopIds);
    $propertyIds = array_keys($propertyIds);

    // ── Batch 1: property_measurements lawn/garden area per property ──
    // Replaces the per-row correlated subquery that previously ran
    // SUM() for every row. One indexed lookup for the whole window.
    $measuredLawnByProperty = [];
    if (!empty($propertyIds)) {
        $ph = implode(',', array_fill(0, count($propertyIds), '?'));
        $mStmt = $db->prepare("
            SELECT property_id, SUM(area_sqft) AS measured_sqft
            FROM property_measurements
            WHERE property_id IN ({$ph})
              AND measurement_type IN ('lawn', 'garden')
            GROUP BY property_id
        ");
        $mStmt->execute($propertyIds);
        foreach ($mStmt->fetchAll(PDO::FETCH_ASSOC) as $m) {
            $measuredLawnByProperty[(int)$m['property_id']] = (float)$m['measured_sqft'];
        }
    }

    // ── Batch 2: last completed stop per property, before the window ──
    // The original subquery filtered on cs2.stop_date < cs.stop_date
    // (per-row precision). We now compute it relative to $startDate,
    // which is the intended semantic for the calendar view (show "last
    // time we were here before this view opened"). Callers that need
    // per-row precision are rare and can look it up separately.
    $lastCompletedByProperty = [];
    if (!empty($propertyIds)) {
        $ph = implode(',', array_fill(0, count($propertyIds), '?'));
        $lcStmt = $db->prepare("
            SELECT property_id, MAX(stop_date) AS last_completed
            FROM calendar_stops
            WHERE property_id IN ({$ph})
              AND status = 'completed'
              AND stop_date < ?
            GROUP BY property_id
        ");
        $lcStmt->execute(array_merge($propertyIds, [$startDate]));
        foreach ($lcStmt->fetchAll(PDO::FETCH_ASSOC) as $lc) {
            $lastCompletedByProperty[(int)$lc['property_id']] = $lc['last_completed'];
        }
    }

    // ── Batch 3: geofence drawn status per property ──
    $geofenceByProperty = [];
    if (!empty($propertyIds)) {
        $ph = implode(',', array_fill(0, count($propertyIds), '?'));
        $gfStmt = $db->prepare("
            SELECT DISTINCT property_id
            FROM job_geofences
            WHERE property_id IN ({$ph})
        ");
        $gfStmt->execute($propertyIds);
        foreach ($gfStmt->fetchAll(PDO::FETCH_ASSOC) as $gf) {
            $geofenceByProperty[(int)$gf['property_id']] = true;
        }
    }

    // Load multi-crew assignments from junction table
    $crewByStop = []; // stop_id => [ ['id' => ..., 'name' => ...], ... ]
    if (!empty($stopIds)) {
        $placeholders = implode(',', array_fill(0, count($stopIds), '?'));
        $crewStmt = $db->prepare("
            SELECT csc.stop_id, csc.user_id, u2.full_name
            FROM calendar_stop_crew csc
            JOIN users u2 ON csc.user_id = u2.id
            WHERE csc.stop_id IN ({$placeholders})
            ORDER BY csc.id
        ");
        $crewStmt->execute($stopIds);
        foreach ($crewStmt->fetchAll(PDO::FETCH_ASSOC) as $cr) {
            $crewByStop[(int)$cr['stop_id']][] = [
                'id'   => (int)$cr['user_id'],
                'name' => $cr['full_name'],
            ];
        }
    }

    // Group by date → stop_id → visits
    $result = [];
    foreach ($rows as $row) {
        $date = $row['stop_date'];
        $stopId = $row['stop_id'];

        if (!isset($result[$date][$stopId])) {
            // Multi-crew data from junction table (with fallback to single crew_id)
            $stopCrewList = $crewByStop[(int)$stopId] ?? [];
            $crewIdsArr = !empty($stopCrewList) ? array_column($stopCrewList, 'id') : ($row['crew_id'] ? [(int)$row['crew_id']] : []);
            $crewNamesArr = !empty($stopCrewList) ? array_column($stopCrewList, 'name') : ($row['crew_name'] ? [$row['crew_name']] : []);

            $result[$date][$stopId] = [
                'stop_id'       => (int)$stopId,
                'stop_date'     => $date,
                'route_order'   => (int)$row['route_order'],
                'route_pin'     => $row['route_pin'] ?? null,
                'crew_id'       => $row['crew_id'] ? (int)$row['crew_id'] : null,
                'crew_name'     => $row['crew_name'],
                'crew_ids'      => $crewIdsArr,
                'crew_names'    => $crewNamesArr,
                'estimated_arrival'   => $row['estimated_arrival'],
                'estimated_departure' => $row['estimated_departure'],
                'stop_status'   => $row['stop_status'],
                'property_id'   => (int)$row['property_id'],
                'property_address' => $row['property_address'],
                'property_city' => $row['property_city'],
                'latitude'      => $row['latitude'],
                'longitude'     => $row['longitude'],
                'company_name'  => $row['company_name'],
                'contact_id'    => $row['contact_id'] ? (int)$row['contact_id'] : null,
                'contact_name'  => $row['contact_name'],
                'contact_phone' => $row['contact_mobile'] ?: ($row['contact_phone'] ?? null),
                'property_name' => $row['property_name'],
                'property_notes' => !empty($row['property_notes']) ? trim($row['property_notes']) : null,
                'stop_notes'     => !empty($row['stop_notes'])     ? trim($row['stop_notes'])     : null,
                'or_status'     => $row['or_status'] ?? 'none',
                // Replaces the correlated COALESCE subquery that
                // previously ran once per row. Same semantic: prefer
                // the manually-entered total, fall back to the measured
                // sum (from batch 1), then the legacy lawn_size_sqft.
                'lawn_sqft'     => (function () use ($row, $measuredLawnByProperty) {
                    $pid = (int)($row['property_id'] ?? 0);
                    if (!empty($row['total_lawn_sqft']) && $row['total_lawn_sqft'] > 0) {
                        return (float)$row['total_lawn_sqft'];
                    }
                    if (!empty($measuredLawnByProperty[$pid])) {
                        return (float)$measuredLawnByProperty[$pid];
                    }
                    if (!empty($row['lawn_size_sqft']) && $row['lawn_size_sqft'] > 0) {
                        return (float)$row['lawn_size_sqft'];
                    }
                    return null;
                })(),
                'last_completed_date' => $lastCompletedByProperty[(int)$row['property_id']] ?? null,
                'has_geofence'  => isset($geofenceByProperty[(int)$row['property_id']]),
                'visits'        => [],
            ];
        }

        // Add visit if present (LEFT JOIN can produce null visit)
        if ($row['visit_id']) {
            $result[$date][$stopId]['visits'][] = [
                'visit_id'       => (int)$row['visit_id'],
                'visit_number'   => $row['visit_number'],
                'visit_status'   => $row['visit_status'],
                'is_invoiced'    => (int)($row['is_invoiced'] ?? 0),
                'invoice_id'     => $row['invoice_id'] ? (int)$row['invoice_id'] : null,
                'plan_id'        => (int)$row['plan_id'],
                'plan_title'     => $row['plan_title'],
                'plan_number'    => $row['plan_number'],
                'service_type'   => $row['service_type'],
                'pricing_model'  => $row['pricing_model'] ?? 'per_visit',
                'price_per_visit' => $row['price_per_visit'],
                'estimated_duration' => $row['estimated_duration_minutes'],
                'scheduled_time_start' => $row['scheduled_time_start'],
                'scheduled_time_end'   => $row['scheduled_time_end'],
                'sequence_index' => (int)$row['sequence_index'],
            ];
        }
    }

    // Remove stops that have no active visits (e.g. all visits cancelled)
    foreach ($result as $date => &$stops) {
        foreach ($stops as $stopId => $stop) {
            if (empty($stop['visits'])) {
                unset($stops[$stopId]);
            }
        }
        if (empty($stops)) {
            unset($result[$date]);
        }
    }
    unset($stops);

    return $result;
}


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

    // Resolve the display name of the client (who the work is billed to).
    // Mirrors the live invoice precedence (see invoices/create.php + migration
    // 1064): billing entity [C/O firm] → company → individual contact name.
    // Uses only columns the production invoice path already relies on; the
    // Phase-0 BillToResolver is NOT used here because its query references
    // p.billing_company_id, a column production does not have.
    $entity   = trim((string)($plan['billing_entity_name'] ?? ''));
    $company  = trim((string)($plan['company_name'] ?? ''));
    $pmFirm   = trim((string)($plan['pm_firm_name'] ?? ''));
    $contact  = trim(($plan['first_name'] ?? '') . ' ' . ($plan['last_name'] ?? ''));
    if ($entity !== '') {
        $coOf = $pmFirm ?: $company;
        $plan['client_display_name'] = $coOf !== '' ? ($entity . ' C/O ' . $coOf) : $entity;
    } elseif ($company !== '') {
        $plan['client_display_name'] = $company;
    } else {
        $plan['client_display_name'] = $contact !== '' ? $contact : '';
    }

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


// ============================================================================
// DASHBOARD & LIST HELPERS
// ============================================================================

/**
 * Get dashboard stats for plans and visits.
 */
function getPlanDashboardStats(): array {
    $db = getDB();

    $stats = [];

    // Plan counts by status
    $planStats = $db->query("SELECT status, COUNT(*) as count FROM job_plans GROUP BY status");
    while ($row = $planStats->fetch(PDO::FETCH_ASSOC)) {
        $stats['plans_' . $row['status']] = (int)$row['count'];
    }

    // Today's visits
    $todayStmt = $db->query("
        SELECT COUNT(*) as count FROM job_visits
        WHERE scheduled_date = CURDATE() AND status IN ('scheduled', 'in_progress')
    ");
    $stats['visits_today'] = (int)$todayStmt->fetch(PDO::FETCH_ASSOC)['count'];

    // This week's visits
    $weekStmt = $db->query("
        SELECT COUNT(*) as count FROM job_visits
        WHERE scheduled_date BETWEEN CURDATE() AND DATE_ADD(CURDATE(), INTERVAL 7 DAY)
        AND status IN ('scheduled', 'in_progress')
    ");
    $stats['visits_this_week'] = (int)$weekStmt->fetch(PDO::FETCH_ASSOC)['count'];

    // Active plans count
    $stats['plans_active_total'] = ($stats['plans_active'] ?? 0);

    return $stats;
}

/**
 * Get recent jobs on a property (for property detail views).
 */
function getRecentPlansOnProperty(int $propertyId, int $limit = 5): array {
    $db = getDB();
    $stmt = $db->prepare("
        SELECT jp.id, jp.plan_number, jp.title, jp.service_type, jp.status,
               jp.plan_start_date, jp.estimated_amount,
               (SELECT COUNT(*) FROM job_visits jv WHERE jv.plan_id = jp.id AND jv.status = 'completed') AS visits_done,
               (SELECT MIN(jv2.scheduled_date) FROM job_visits jv2 WHERE jv2.plan_id = jp.id AND jv2.status = 'scheduled' AND jv2.scheduled_date >= CURDATE()) AS next_visit
        FROM job_plans jp
        WHERE jp.property_id = ?
        ORDER BY jp.created_at DESC
        LIMIT ?
    ");
    $stmt->execute([$propertyId, $limit]);
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

/**
 * Resolve effective tracking requirements for a job plan.
 * Priority: plan overrides (if not NULL) > product defaults (most restrictive) > standard.
 *
 * @return array ['require_clock_in' => bool, 'require_gps' => bool, 'require_photos' => bool,
 *               'tracking_level' => string, 'source' => array]
 */
function resolveTrackingRequirementsForPlan(int $planId): array {
    $db = getDB();
    $defaults = [
        'auto_clock_in' => false,
        'require_clock_in' => false,
        'require_gps' => false,
        'require_photos' => false,
        'tracking_level' => 'standard',
        'source' => ['auto_clock_in' => 'default', 'clock_in' => 'default', 'gps' => 'default', 'photos' => 'default']
    ];

    // Check if tracking columns exist
    try {
        $check = $db->query("SHOW COLUMNS FROM job_plans LIKE 'tracking_level_override'");
        if ($check->rowCount() === 0) {
            return $defaults;
        }
    } catch (Exception $e) {
        return $defaults;
    }

    // Check if auto_clock_in_override column exists on job_plans
    $hasAutoClockInOverride = false;
    try {
        $aciCheck = $db->query("SHOW COLUMNS FROM job_plans LIKE 'auto_clock_in_override'");
        $hasAutoClockInOverride = ($aciCheck->rowCount() > 0);
    } catch (Exception $e) { /* ignore */ }

    // Get plan override columns
    $planCols = "tracking_level_override, require_clock_in_override,
               require_gps_override, require_photos_override";
    if ($hasAutoClockInOverride) {
        $planCols .= ", auto_clock_in_override";
    }
    $stmt = $db->prepare("SELECT {$planCols} FROM job_plans WHERE id = ?");
    $stmt->execute([$planId]);
    $plan = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$plan) {
        return $defaults;
    }

    // Get product-level defaults (most restrictive across all linked products)
    $pClockIn = 0;
    $pGps = 0;
    $pPhotos = 0;
    $pAutoClockIn = 0;
    $pLevel = 'standard';

    try {
        $checkProd = $db->query("SHOW COLUMNS FROM products LIKE 'tracking_level'");
        if ($checkProd->rowCount() > 0) {
            // Check if auto_clock_in column exists on products
            $hasAutoClockInProd = false;
            try {
                $aciProdCheck = $db->query("SHOW COLUMNS FROM products LIKE 'auto_clock_in'");
                $hasAutoClockInProd = ($aciProdCheck->rowCount() > 0);
            } catch (Exception $e) { /* ignore */ }

            // Check if plan_line_items has direct product_id column
            $hasDirectProductId = false;
            try {
                $dpCheck = $db->query("SHOW COLUMNS FROM plan_line_items LIKE 'product_id'");
                $hasDirectProductId = ($dpCheck->rowCount() > 0);
            } catch (Exception $e) { /* ignore */ }

            $autoClockInCol = $hasAutoClockInProd ? "MAX(p.auto_clock_in) AS auto_clock_in," : "";

            // Resolve products via two paths:
            //   1. Direct: plan_line_items.product_id → products
            //   2. Via quote: plan_line_items.quote_line_item_id → quote_line_items.product_id → products
            if ($hasDirectProductId) {
                $prodStmt = $db->prepare("
                    SELECT MAX(p.require_clock_in) AS require_clock_in,
                           MAX(p.require_gps) AS require_gps,
                           MAX(p.require_photos) AS require_photos,
                           {$autoClockInCol}
                           MAX(CASE WHEN p.tracking_level = 'heightened' THEN 2
                                    WHEN p.tracking_level = 'custom' THEN 1
                                    ELSE 0 END) AS level_rank
                    FROM plan_line_items pli
                    LEFT JOIN quote_line_items qli ON pli.quote_line_item_id = qli.id
                    JOIN products p ON p.id = COALESCE(pli.product_id, qli.product_id)
                    WHERE pli.plan_id = ?
                ");
            } else {
                $prodStmt = $db->prepare("
                    SELECT MAX(p.require_clock_in) AS require_clock_in,
                           MAX(p.require_gps) AS require_gps,
                           MAX(p.require_photos) AS require_photos,
                           {$autoClockInCol}
                           MAX(CASE WHEN p.tracking_level = 'heightened' THEN 2
                                    WHEN p.tracking_level = 'custom' THEN 1
                                    ELSE 0 END) AS level_rank
                    FROM plan_line_items pli
                    JOIN quote_line_items qli ON pli.quote_line_item_id = qli.id
                    JOIN products p ON qli.product_id = p.id
                    WHERE pli.plan_id = ?
                ");
            }
            $prodStmt->execute([$planId]);
            $prod = $prodStmt->fetch(PDO::FETCH_ASSOC);

            if ($prod && $prod['level_rank'] !== null) {
                $pClockIn = (int)$prod['require_clock_in'];
                $pGps = (int)$prod['require_gps'];
                $pPhotos = (int)$prod['require_photos'];
                $pAutoClockIn = $hasAutoClockInProd ? (int)($prod['auto_clock_in'] ?? 0) : 0;
                $pLevel = $prod['level_rank'] == 2 ? 'heightened' :
                          ($prod['level_rank'] == 1 ? 'custom' : 'standard');
            }
        }
    } catch (Exception $e) {
        // Products table may not have tracking columns yet
    }

    // Resolve auto_clock_in: plan override wins if not NULL, else product default
    $planAutoClockIn = $hasAutoClockInOverride ? ($plan['auto_clock_in_override'] ?? null) : null;

    // Resolve: plan override wins if not NULL, else product default
    return [
        'auto_clock_in' => $planAutoClockIn !== null
            ? (bool)(int)$planAutoClockIn
            : (bool)$pAutoClockIn,
        'require_clock_in' => $plan['require_clock_in_override'] !== null
            ? (bool)(int)$plan['require_clock_in_override']
            : (bool)$pClockIn,
        'require_gps' => $plan['require_gps_override'] !== null
            ? (bool)(int)$plan['require_gps_override']
            : (bool)$pGps,
        'require_photos' => $plan['require_photos_override'] !== null
            ? (bool)(int)$plan['require_photos_override']
            : (bool)$pPhotos,
        'tracking_level' => $plan['tracking_level_override'] !== null
            ? $plan['tracking_level_override']
            : $pLevel,
        'source' => [
            'auto_clock_in' => $planAutoClockIn !== null ? 'plan' : 'product',
            'clock_in' => $plan['require_clock_in_override'] !== null ? 'plan' : 'product',
            'gps' => $plan['require_gps_override'] !== null ? 'plan' : 'product',
            'photos' => $plan['require_photos_override'] !== null ? 'plan' : 'product',
        ]
    ];
}

/**
 * Resolve effective tracking requirements for a specific visit.
 * Looks up the plan from the visit, then delegates to resolveTrackingRequirementsForPlan().
 */
function resolveTrackingRequirements(int $visitId): array {
    $db = getDB();
    $stmt = $db->prepare("SELECT plan_id FROM job_visits WHERE id = ?");
    $stmt->execute([$visitId]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$row || !$row['plan_id']) {
        return [
            'auto_clock_in' => false,
            'require_clock_in' => false,
            'require_gps' => false,
            'require_photos' => false,
            'tracking_level' => 'standard',
            'source' => ['auto_clock_in' => 'default', 'clock_in' => 'default', 'gps' => 'default', 'photos' => 'default']
        ];
    }

    return resolveTrackingRequirementsForPlan((int)$row['plan_id']);
}


// ============================================================================
// PROFITABILITY CALCULATIONS
// ============================================================================

/**
 * Get the configured overhead percentage from overhead_settings.
 * Returns the percentage value (e.g. 20 for 20%), defaults to 20 if not set.
 */
function getOverheadPercentage(): float {
    $db = getDB();
    try {
        $stmt = $db->prepare("SELECT setting_value FROM overhead_settings WHERE setting_key = 'overhead_percent'");
        $stmt->execute();
        $val = $stmt->fetchColumn();
        return $val !== false ? (float)$val : 20.0;
    } catch (\Exception $e) {
        return 20.0;
    }
}

/**
 * Get overhead settings as an associative array.
 * Returns all key-value pairs from overhead_settings with sensible defaults.
 */
function getOverheadSettings(): array {
    $defaults = [
        'overhead_percent' => 20,
        'overhead_apply_mode' => 0,       // 0=percentage, 1=per_hour
        'estimated_billable_hours' => 160,
        'estimated_monthly_revenue' => 18000,
        'estimated_jobs_per_month' => 40,
        'profit_margin' => 35,
    ];

    $db = getDB();
    try {
        $stmt = $db->query("SELECT setting_key, setting_value FROM overhead_settings");
        $rows = $stmt->fetchAll(PDO::FETCH_KEY_PAIR);
        return array_merge($defaults, $rows);
    } catch (\Exception $e) {
        return $defaults;
    }
}

/**
 * Calculate the total monthly overhead from overhead_items.
 * Normalizes weekly/quarterly/annual items to monthly amounts.
 */
function getMonthlyOverheadTotal(): float {
    $db = getDB();
    $freqMultipliers = [
        'weekly'    => 52 / 12,   // ~4.33
        'monthly'   => 1,
        'quarterly' => 1 / 3,
        'annual'    => 1 / 12,
    ];

    try {
        $stmt = $db->query("SELECT amount, frequency FROM overhead_items WHERE is_active = 1");
        $total = 0;
        while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
            $mult = $freqMultipliers[$row['frequency']] ?? 1;
            $total += (float)$row['amount'] * $mult;
        }
        return $total;
    } catch (\Exception $e) {
        return 0;
    }
}

/**
 * Full profitability breakdown for a single plan (job view page).
 *
 * Revenue:  Sum of actual_amount from completed visits (falls back to price_per_visit)
 * Labor:    Sum of job_time_entries duration × crew hourly rate, or estimated from plan duration
 * Expenses: Sum of expenses linked to the plan's property
 * Overhead: Applied via overhead_settings (% mode or $/hr mode)
 *
 * @return array {revenue, labor_cost, labor_minutes, labor_estimated, expense_cost,
 *               overhead_cost, overhead_mode, total_cost, profit, margin_pct,
 *               completed_visits, has_data}
 */
function getPlanProfitability(int $planId): array {
    $db = getDB();
    $empty = [
        'revenue' => 0, 'labor_cost' => 0, 'labor_minutes' => 0,
        'labor_estimated' => true, 'expense_cost' => 0, 'overhead_cost' => 0,
        'overhead_mode' => 0, 'total_cost' => 0, 'profit' => 0,
        'margin_pct' => 0, 'completed_visits' => 0, 'has_data' => false,
    ];

    // 1. Revenue + plan info
    $revStmt = $db->prepare("
        SELECT
            jp.price_per_visit,
            jp.property_id,
            jp.estimated_duration_minutes,
            jp.default_crew_id,
            COUNT(*) AS completed_count,
            SUM(COALESCE(jv.actual_amount, jp.price_per_visit)) AS total_revenue
        FROM job_visits jv
        JOIN job_plans jp ON jv.plan_id = jp.id
        WHERE jv.plan_id = ? AND jv.status = 'completed'
        GROUP BY jp.id
    ");
    $revStmt->execute([$planId]);
    $rev = $revStmt->fetch(PDO::FETCH_ASSOC);

    if (!$rev || (int)$rev['completed_count'] === 0) {
        return $empty;
    }

    $revenue = (float)$rev['total_revenue'];
    $completedCount = (int)$rev['completed_count'];
    $propertyId = (int)$rev['property_id'];
    $estimatedDuration = (int)$rev['estimated_duration_minutes'];
    $defaultCrewId = $rev['default_crew_id'] ? (int)$rev['default_crew_id'] : null;

    // 2. Labor cost from actual time entries
    $laborStmt = $db->prepare("
        SELECT
            COALESCE(SUM(jte.duration_minutes), 0) AS total_labor_minutes,
            COALESCE(SUM(jte.duration_minutes * (COALESCE(u.hourly_rate, 25) / 60)), 0) AS total_labor_cost
        FROM job_time_entries jte
        JOIN users u ON jte.user_id = u.id
        JOIN job_visits jv ON jte.visit_id = jv.id
        WHERE jv.plan_id = ?
          AND jte.status IN ('completed', 'edited')
    ");
    $laborStmt->execute([$planId]);
    $labor = $laborStmt->fetch(PDO::FETCH_ASSOC);

    $laborMinutes = (float)($labor['total_labor_minutes'] ?? 0);
    $laborCost = (float)($labor['total_labor_cost'] ?? 0);
    $laborEstimated = false;

    // If no actual time entries, estimate from plan duration × crew rate
    if ($laborMinutes <= 0 && $estimatedDuration > 0) {
        $laborEstimated = true;
        $crewRate = 25.0;
        if ($defaultCrewId) {
            $rateStmt = $db->prepare("SELECT COALESCE(hourly_rate, 25) FROM users WHERE id = ?");
            $rateStmt->execute([$defaultCrewId]);
            $crewRate = (float)$rateStmt->fetchColumn() ?: 25.0;
        }
        $laborMinutes = $estimatedDuration * $completedCount;
        $laborCost = ($laborMinutes / 60) * $crewRate;
    }

    // 3. Expenses linked to property
    $expCost = 0;
    if ($propertyId) {
        $expStmt = $db->prepare("
            SELECT COALESCE(SUM(total), 0) AS total_expenses
            FROM expenses
            WHERE property_id = ?
              AND status IN ('draft', 'approved', 'forwarded')
        ");
        $expStmt->execute([$propertyId]);
        $expCost = (float)$expStmt->fetchColumn();
    }

    // 4. Overhead
    $ohSettings = getOverheadSettings();
    $overheadMode = (int)$ohSettings['overhead_apply_mode'];
    $overheadCost = 0;

    if ($overheadMode === 1) {
        // $/hr mode: monthly overhead / billable hours * actual labor hours
        $monthlyOverhead = getMonthlyOverheadTotal();
        $billableHours = max(1, (float)$ohSettings['estimated_billable_hours']);
        $perHourRate = $monthlyOverhead / $billableHours;
        $overheadCost = $perHourRate * ($laborMinutes / 60);
    } else {
        // % mode: overhead as percentage of direct costs (labor + expenses)
        $ohPct = (float)$ohSettings['overhead_percent'];
        $overheadCost = ($laborCost + $expCost) * ($ohPct / 100);
    }

    // 5. Totals
    $totalCost = $laborCost + $expCost + $overheadCost;
    $profit = $revenue - $totalCost;
    $marginPct = $revenue > 0 ? ($profit / $revenue) * 100 : 0;

    return [
        'revenue'          => round($revenue, 2),
        'labor_cost'       => round($laborCost, 2),
        'labor_minutes'    => round($laborMinutes, 0),
        'labor_estimated'  => $laborEstimated,
        'expense_cost'     => round($expCost, 2),
        'overhead_cost'    => round($overheadCost, 2),
        'overhead_mode'    => $overheadMode,
        'total_cost'       => round($totalCost, 2),
        'profit'           => round($profit, 2),
        'margin_pct'       => round($marginPct, 1),
        'completed_visits' => $completedCount,
        'has_data'         => true,
    ];
}

/**
 * Lightweight batch profitability estimates for schedule cards.
 * One query for all plan IDs — returns simplified margin percentages.
 *
 * @param int[] $planIds
 * @return array planId => ['margin_pct' => int|null, 'has_data' => bool]
 */
function getStopProfitabilityBatch(array $planIds): array {
    if (empty($planIds)) return [];
    $db = getDB();

    $placeholders = implode(',', array_fill(0, count($planIds), '?'));

    $stmt = $db->prepare("
        SELECT
            jp.id AS plan_id,
            jp.price_per_visit,
            jp.estimated_duration_minutes,
            COALESCE(u.hourly_rate, 25) AS crew_rate,
            (SELECT COUNT(*) FROM job_visits jv
             WHERE jv.plan_id = jp.id AND jv.status = 'completed') AS completed_count,
            (SELECT COALESCE(SUM(jv2.actual_amount), 0)
             FROM job_visits jv2
             WHERE jv2.plan_id = jp.id AND jv2.status = 'completed') AS actual_revenue,
            (SELECT COALESCE(SUM(jte.duration_minutes), 0)
             FROM job_time_entries jte
             JOIN job_visits jv3 ON jte.visit_id = jv3.id
             WHERE jv3.plan_id = jp.id
               AND jte.status IN ('completed', 'edited')) AS actual_labor_minutes
        FROM job_plans jp
        LEFT JOIN users u ON jp.default_crew_id = u.id
        WHERE jp.id IN ({$placeholders})
    ");
    $stmt->execute($planIds);

    $overheadPct = getOverheadPercentage();
    $results = [];

    while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
        $pid = (int)$row['plan_id'];
        $price = (float)$row['price_per_visit'];
        $completedCount = (int)$row['completed_count'];

        if ($completedCount === 0) {
            $results[$pid] = ['margin_pct' => null, 'has_data' => false];
            continue;
        }

        $crewRate = (float)$row['crew_rate'];
        $actualRevenue = (float)$row['actual_revenue'];

        // Revenue: use actual if available, else price × completed
        $revenue = $actualRevenue > 0 ? $actualRevenue : ($price * $completedCount);
        if ($revenue <= 0) {
            $results[$pid] = ['margin_pct' => null, 'has_data' => false];
            continue;
        }

        // Labor: actual time entries, or estimated from plan duration
        $laborMinutes = (float)$row['actual_labor_minutes'];
        if ($laborMinutes <= 0) {
            $laborMinutes = (float)$row['estimated_duration_minutes'] * $completedCount;
        }
        $laborCost = ($laborMinutes / 60) * $crewRate;
        $overhead = $laborCost * ($overheadPct / 100);
        $totalCost = $laborCost + $overhead;
        $margin = (($revenue - $totalCost) / $revenue) * 100;

        $results[$pid] = [
            'margin_pct' => (int)round($margin),
            'has_data' => true,
        ];
    }

    return $results;
}


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
// FERTILIZER BUNDLE FUNCTIONS
// ─────────────────────────────────────────────────────────────────

/**
 * Generate visits for a pre-sold fertilizer bundle using explicit dates.
 * Called by createJobPlan() when is_prepaid_bundle=1 and fertilizer_dates[] provided.
 *
 * Each visit is created with actual_amount=0.00 (pre-sold, no charge) and
 * materials_json auto-calculated from the plan's product application_rate × property area.
 *
 * @param int   $planId  The plan id
 * @param array $dates   Ordered array of 'Y-m-d' date strings (one per application)
 */
function generateFertilizerVisits(int $planId, array $dates): void {
    $db = getDB();

    // Load plan + property
    $planStmt = $db->prepare("
        SELECT jp.*, p.id AS prop_id,
               p.address AS property_address, p.city AS property_city
        FROM job_plans jp
        JOIN properties p ON jp.property_id = p.id
        WHERE jp.id = ?
    ");
    $planStmt->execute([$planId]);
    $plan = $planStmt->fetch(PDO::FETCH_ASSOC);
    if (!$plan) return;

    // Auto-calculate materials once (shared across all visits in this plan)
    $materialsJson = calculateMaterialsForVisit($planId);

    $visitNumber = 1;
    foreach ($dates as $dateStr) {
        $dateStr = trim($dateStr);
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $dateStr)) continue;

        $visitNum = generateVisitNumber($plan['plan_number'], $visitNumber);

        try {
            $insertStmt = $db->prepare("
                INSERT IGNORE INTO job_visits (
                    visit_number, plan_id, scheduled_date, sequence_index,
                    status, actual_amount, materials_json,
                    assigned_crew_id,
                    scheduled_time_start, scheduled_time_end
                ) VALUES (
                    ?, ?, ?, ?,
                    'scheduled', 0.00, ?,
                    ?,
                    ?, ?
                )
            ");
            $insertStmt->execute([
                $visitNum,
                $planId,
                $dateStr,
                $visitNumber,
                $materialsJson ?: null,
                $plan['default_crew_id'] ?? null,
                $plan['default_time_start'] ?? null,
                $plan['default_time_end'] ?? null,
            ]);

            // Ensure calendar stop for routing
            ensureCalendarStop(
                (int)$plan['property_id'],
                $dateStr,
                $plan['default_crew_id'] ? (int)$plan['default_crew_id'] : null,
                $plan['default_time_start'] ?? null,
                $plan['default_time_end'] ?? null
            );
        } catch (Throwable $e) {
            error_log("generateFertilizerVisits: visit {$visitNum} on {$dateStr} failed: " . $e->getMessage());
        }

        $visitNumber++;
    }

    // Update visits_generated_through watermark
    if (!empty($dates)) {
        $lastDate = max($dates);
        try {
            $db->prepare("UPDATE job_plans SET visits_generated_through = ? WHERE id = ?")
               ->execute([$lastDate, $planId]);
        } catch (Throwable $e) { /* non-critical */ }
    }
}

/**
 * Auto-calculate materials JSON for a fertilizer visit using the plan's
 * product application_rate and the property's measured lawn area.
 *
 * Returns a JSON string for job_visits.materials_json, or null if no product data.
 *
 * @param int $planId
 * @return string|null JSON array: [{"product_id":N,"product_name":"...","qty":2,"unit":"bags",...}]
 */
function calculateMaterialsForVisit(int $planId): ?string {
    $db = getDB();

    // Get plan's primary product via plan_line_items (first item with a product_id)
    $productStmt = $db->prepare("
        SELECT pli.product_id, p.name AS product_name,
               p.application_rate, p.sku
        FROM plan_line_items pli
        JOIN products p ON pli.product_id = p.id
        WHERE pli.plan_id = ?
          AND pli.product_id IS NOT NULL
        ORDER BY pli.sort_order ASC
        LIMIT 1
    ");
    $productStmt->execute([$planId]);
    $product = $productStmt->fetch(PDO::FETCH_ASSOC);

    if (!$product || empty($product['application_rate'])) return null;

    // Get property's lawn area from measurements
    $planRow = $db->prepare("SELECT property_id FROM job_plans WHERE id = ?");
    $planRow->execute([$planId]);
    $propertyId = (int)$planRow->fetchColumn();

    $areaStmt = $db->prepare("
        SELECT SUM(area_sqft) AS total_sqft
        FROM property_measurements
        WHERE property_id = ?
          AND measurement_group_key IN ('lawn_area', 'lawn', 'garden')
        GROUP BY property_id
    ");
    $areaStmt->execute([$propertyId]);
    $areaSqft = (float)($areaStmt->fetchColumn() ?: 0);

    // Parse application_rate to compute quantity
    // Supports formats like: "2 bags per 4000 sqft", "1 bag per 5000 sq ft", "3 kg per 1000 sqft"
    $qty = null;
    $unit = 'bags';
    $rateStr = strtolower(trim($product['application_rate']));

    if (preg_match('/^(\d+\.?\d*)\s+(\w+)\s+per\s+(\d+\.?\d*)\s+sq/i', $rateStr, $m)) {
        $rateQty   = (float)$m[1];
        $unit      = $m[2];
        $rateArea  = (float)$m[3];
        if ($rateArea > 0 && $areaSqft > 0) {
            $qty = round(($areaSqft / $rateArea) * $rateQty, 1);
        }
    }

    $materials = [[
        'product_id'       => (int)$product['product_id'],
        'product_name'     => $product['product_name'],
        'sku'              => $product['sku'] ?? '',
        'qty'              => $qty ?? 1,
        'unit'             => $unit,
        'area_sqft'        => (int)$areaSqft,
        'application_rate' => $product['application_rate'],
    ]];

    return json_encode($materials, JSON_UNESCAPED_UNICODE);
}

// ============================================================================
// PURCHASE TASK SCHEDULE INTEGRATION
// ============================================================================

/**
 * Fetch purchase tasks that should appear on the schedule for a date range.
 *
 * Appearance rules (any one is sufficient):
 *   - procurement_mode = 'asap' and not yet delivered/verified
 *   - scheduled_date <= endDate and not yet delivered/verified
 *   - No scheduled_date and DATE(created_at) <= endDate and not delivered/verified
 *
 * Returns a flat array of task rows, each keyed by the date(s) they should
 * appear on within [startDate, endDate]. Tasks with no fixed date (asap or
 * no scheduled_date) are duplicated across every date in the range so they
 * show up as persistent reminders.
 *
 * @param PDO    $db
 * @param string $startDate  Y-m-d
 * @param string $endDate    Y-m-d
 * @param int|null $crewId   Filter by assigned_to, or null for all
 * @return array  [dateStr => [task, ...], ...]
 */
function getPurchaseTasksForSchedule(PDO $db, string $startDate, string $endDate, ?int $crewId = null): array
{
    // Check task_type column exists before querying (migration 970 guard)
    try {
        $colCheck = $db->query("SHOW COLUMNS FROM tasks LIKE 'task_type'")->fetch();
        if (!$colCheck) return [];
    } catch (Exception $e) {
        return [];
    }

    try {
        $sql = "
            SELECT t.*,
                   v.name  AS vendor_name,
                   vl.label   AS location_label,
                   vl.address AS location_address,
                   vl.lat,
                   vl.lng,
                   vl.city           AS location_city,
                   vl.phone          AS location_phone,
                   vl.hours_weekday,
                   vl.hours_saturday,
                   vl.hours_sunday,
                   vl.notes          AS location_notes,
                   vl.is_preferred,
                   (SELECT COUNT(*) FROM task_items WHERE task_id = t.id) AS items_count,
                   u.full_name AS assigned_to_name,
                   jp.plan_number,
                   jp.title          AS plan_title,
                   CONCAT(c.first_name, ' ', c.last_name) AS contact_name
            FROM tasks t
            LEFT JOIN vendors v  ON t.vendor_id = v.id
            LEFT JOIN vendor_locations vl ON t.vendor_location_id = vl.id
            LEFT JOIN users u ON t.assigned_to = u.id
            LEFT JOIN job_plans jp ON t.plan_id = jp.id
            LEFT JOIN contacts c   ON t.contact_id = c.id
            WHERE t.task_type = 'purchase'
              AND (t.purchase_status IS NULL OR t.purchase_status NOT IN ('delivered', 'verified'))
              AND (
                  (t.scheduled_date IS NOT NULL AND t.scheduled_date <= ?)
                  OR (t.scheduled_date IS NULL AND DATE(t.created_at) <= ?)
                  OR t.procurement_mode = 'asap'
              )
        ";
        $params = [$endDate, $endDate];

        if ($crewId !== null) {
            $sql .= " AND t.assigned_to = ?";
            $params[] = $crewId;
        }

        $sql .= " ORDER BY
            CASE t.priority WHEN 'high' THEN 0 WHEN 'normal' THEN 1 ELSE 2 END,
            COALESCE(t.scheduled_date, DATE(t.created_at)) ASC,
            t.id ASC";

        $stmt = $db->prepare($sql);
        $stmt->execute($params);
        $tasks = $stmt->fetchAll(PDO::FETCH_ASSOC);
    } catch (Exception $e) {
        error_log('[getPurchaseTasksForSchedule] ' . $e->getMessage());
        return [];
    }

    // Batch-fetch task_items to avoid N+1 queries
    $taskIds = array_column($tasks, 'id');
    $itemsByTask = [];
    if (!empty($taskIds)) {
        try {
            $ph = implode(',', array_fill(0, count($taskIds), '?'));
            $iStmt = $db->prepare(
                "SELECT id, task_id, name, quantity, unit, estimated_unit_price, is_purchased, sort_order
                 FROM task_items
                 WHERE task_id IN ($ph)
                 ORDER BY task_id, sort_order, id"
            );
            $iStmt->execute($taskIds);
            foreach ($iStmt->fetchAll(PDO::FETCH_ASSOC) as $item) {
                $itemsByTask[(int)$item['task_id']][] = $item;
            }
        } catch (Exception $e) {
            // task_items table may not exist pre-migration 970
        }
    }
    foreach ($tasks as &$task) {
        $task['items'] = $itemsByTask[(int)$task['id']] ?? [];
    }
    unset($task);

    // Build date range array
    $dates = [];
    $cur = new DateTime($startDate);
    $end = new DateTime($endDate);
    while ($cur <= $end) {
        $dates[] = $cur->format('Y-m-d');
        $cur->modify('+1 day');
    }

    $byDate = [];
    foreach ($dates as $d) {
        $byDate[$d] = [];
    }

    foreach ($tasks as $task) {
        $scheduledDate = $task['scheduled_date'] ?? null;
        $procMode      = $task['procurement_mode'] ?? null;
        $createdDate   = $task['created_at'] ? substr($task['created_at'], 0, 10) : $startDate;

        // Determine which dates this task appears on
        if ($procMode === 'asap' || $scheduledDate === null) {
            // Persistent — show on every date in range from earliest applicable date
            $earliestDate = ($scheduledDate !== null) ? $scheduledDate : $createdDate;
            foreach ($dates as $d) {
                if ($d >= $earliestDate) {
                    $byDate[$d][] = $task;
                }
            }
        } else {
            // Fixed-date — show on scheduled_date and every day after (overdue reminder)
            foreach ($dates as $d) {
                if ($d >= $scheduledDate) {
                    $byDate[$d][] = $task;
                }
            }
        }
    }

    return $byDate;
}

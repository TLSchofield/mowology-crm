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
                plan_number, quote_id, property_id, company_id,
                title, description, service_type, service_package_id, billing_template_id,
                pricing_model, price_per_visit, monthly_flat_price, seasonal_price, estimated_amount,
                checklist_template, photo_types_required, gps_enforcement,
                checklist_blocks_completion, photos_block_completion,
                is_recurring, recurrence_pattern, recurrence_interval,
                recurrence_interval_unit, recurrence_day_of_week,
                plan_start_date, plan_end_date, blackout_dates,
                default_crew_id, default_crew_size, estimated_duration_minutes,
                default_time_start, default_time_end,
                horizon_days, status, created_by
            ) VALUES (
                ?, ?, ?, ?,
                ?, ?, ?, ?, ?,
                ?, ?, ?, ?, ?,
                ?, ?, ?,
                ?, ?,
                ?, ?, ?,
                ?, ?,
                ?, ?, ?,
                ?, ?, ?,
                ?, ?,
                ?, 'active', ?
            )
        ");

        $stmt->execute([
            $planNumber,
            $planData['quote_id'] ?? null,
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

        // Generate initial visits (outside transaction for clarity)
        generateVisits($planId);

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

    $planData = [
        'quote_id'         => $quoteId,
        'property_id'      => $quote['property_id'],
        'company_id'       => $quote['company_id'],
        'title'            => $quote['title'] ?: 'Plan from ' . $quote['quote_number'],
        'description'      => $quote['description'],
        'service_type'     => $quote['service_type'] ?? 'landscaping',
        'estimated_amount' => $quote['amount'] ?? $quote['total'] ?? 0,
        'price_per_visit'  => $quote['amount'] ?? $quote['total'] ?? 0,
        'plan_start_date'  => date('Y-m-d'),
        'is_recurring'     => 0,
    ];

    $result = createJobPlan($planData, $userId);

    if ($result['success']) {
        // ROI attribution
        if (function_exists('createROIAttribution')) {
            $leadEventId = !empty($quote['lead_event_id']) ? (int)$quote['lead_event_id'] : null;

            $quoteStmt = $db->prepare("
                SELECT source FROM quote_requests
                WHERE id IN (SELECT quote_request_id FROM quotes WHERE id = ?)
                LIMIT 1
            ");
            $quoteStmt->execute([$quoteId]);
            $quoteSource = $quoteStmt->fetchColumn();

            createROIAttribution($result['plan_id'], $leadEventId, $quoteSource ?: 'website', $planData['estimated_amount']);
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

    $stmt = $db->prepare("
        INSERT INTO plan_line_items
            (plan_id, quote_line_item_id, service_type, description, quantity,
             unit_type, unit_price, line_total, sort_order)
        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)
    ");

    $sortOrder = 0;
    foreach ($items as $item) {
        $qty = floatval($item['quantity'] ?? 1);
        $unitPrice = floatval($item['unit_price'] ?? 0);
        $lineTotal = floatval($item['line_total'] ?? ($qty * $unitPrice));

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

        foreach ($plans as $plan) {
            try {
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
                    // Determine start: max(today, plan_start_date, visits_generated_through+1day)
                    $fromDate = clone $today;
                    if ($plan['plan_start_date']) {
                        $planStart = new DateTime($plan['plan_start_date']);
                        if ($planStart > $fromDate) $fromDate = $planStart;
                    }
                    if ($plan['visits_generated_through']) {
                        $genThrough = new DateTime($plan['visits_generated_through']);
                        $genThrough->modify('+1 day');
                        if ($genThrough > $fromDate) $fromDate = $genThrough;
                    }

                    if ($fromDate > $toDate) {
                        $plansProcessed++;
                        continue; // Already generated up to horizon
                    }

                    $dates = calculateRecurrenceDates($plan, $fromDate->format('Y-m-d'), $toDate->format('Y-m-d'));
                } else {
                    // One-time plan: single visit on plan_start_date
                    $visitDate = $plan['plan_start_date'] ?: date('Y-m-d');
                    // Check if visit already exists
                    $checkStmt = $db->prepare("SELECT COUNT(*) FROM job_visits WHERE plan_id = ?");
                    $checkStmt->execute([$plan['id']]);
                    if ((int)$checkStmt->fetchColumn() > 0) {
                        $plansProcessed++;
                        continue; // One-time already has its visit
                    }
                    $dates = [$visitDate];
                }

                // Get next sequence index
                $seqStmt = $db->prepare("SELECT MAX(sequence_index) FROM job_visits WHERE plan_id = ?");
                $seqStmt->execute([$plan['id']]);
                $nextSeq = ((int)$seqStmt->fetchColumn()) + 1;

                foreach ($dates as $date) {
                    // Ensure calendar stop exists
                    $stopId = ensureCalendarStop(
                        (int)$plan['property_id'],
                        $date,
                        $plan['default_crew_id'] ? (int)$plan['default_crew_id'] : null
                    );

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
 * Calculate occurrence dates for a recurring plan.
 * Returns array of YYYY-MM-DD strings. Respects blackout_dates.
 */
function calculateRecurrenceDates(array $plan, string $fromDate, string $toDate): array {
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
                if ($targetDow !== null) {
                    $shouldInclude = ($currentDow === (int)$targetDow);
                } else {
                    // Default: same day of week as plan start
                    $shouldInclude = ($currentDow === (int)$planStart->format('w'));
                }
                break;

            case 'biweekly':
                $targetDay = ($targetDow !== null) ? (int)$targetDow : (int)$planStart->format('w');
                if ($currentDow === $targetDay) {
                    $diffDays = (int)$current->diff($planStart)->days;
                    $diffWeeks = (int)floor($diffDays / 7);
                    $shouldInclude = ($diffWeeks % 2 === 0);
                }
                break;

            case 'monthly':
                $targetDay = (int)$planStart->format('j'); // day of month
                $currentDay = (int)$current->format('j');
                $shouldInclude = ($currentDay === $targetDay);
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
                }
                $shouldInclude = ($unitValue >= 0 && $unitValue % $interval === 0);
                // For weeks/months custom, also check day-of-week match
                if ($shouldInclude && $intervalUnit === 'weeks') {
                    $targetDay = ($targetDow !== null) ? (int)$targetDow : (int)$planStart->format('w');
                    $shouldInclude = ($currentDow === $targetDay);
                }
                break;
        }

        // Check blackout
        if ($shouldInclude && isset($blackouts[$dateStr])) {
            $shouldInclude = false;
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
function ensureCalendarStop(int $propertyId, string $date, ?int $crewId): int {
    $db = getDB();

    // Try INSERT, on dup just touch updated_at
    $stmt = $db->prepare("
        INSERT INTO calendar_stops (property_id, stop_date, crew_id, status)
        VALUES (?, ?, ?, 'scheduled')
        ON DUPLICATE KEY UPDATE updated_at = NOW()
    ");
    $stmt->execute([$propertyId, $date, $crewId]);

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
function getCalendarStops(string $startDate, string $endDate, ?int $crewId = null): array {
    $db = getDB();

    // Single query: stops with their visits
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
            co.company_name,
            u.full_name AS crew_name,
            jv.id AS visit_id,
            jv.visit_number,
            jv.status AS visit_status,
            jv.scheduled_time_start,
            jv.scheduled_time_end,
            jv.assigned_crew_id,
            jv.sequence_index,
            jp.id AS plan_id,
            jp.title AS plan_title,
            jp.plan_number,
            jp.service_type,
            jp.price_per_visit,
            jp.estimated_duration_minutes
        FROM calendar_stops cs
        JOIN properties p ON cs.property_id = p.id
        LEFT JOIN company_properties cp ON p.id = cp.property_id
        LEFT JOIN companies co ON cp.company_id = co.id
        LEFT JOIN users u ON cs.crew_id = u.id
        LEFT JOIN job_visits jv ON jv.stop_id = cs.id AND jv.status NOT IN ('cancelled')
        LEFT JOIN job_plans jp ON jv.plan_id = jp.id
        WHERE cs.stop_date BETWEEN ? AND ?
    ";
    $params = [$startDate, $endDate];

    if ($crewId !== null) {
        $sql .= " AND cs.crew_id = ?";
        $params[] = $crewId;
    }

    $sql .= " ORDER BY cs.stop_date, cs.crew_id, cs.route_order, jv.scheduled_time_start";

    $stmt = $db->prepare($sql);
    $stmt->execute($params);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // Group by date → stop_id → visits
    $result = [];
    foreach ($rows as $row) {
        $date = $row['stop_date'];
        $stopId = $row['stop_id'];

        if (!isset($result[$date][$stopId])) {
            $result[$date][$stopId] = [
                'stop_id'       => (int)$stopId,
                'stop_date'     => $date,
                'route_order'   => (int)$row['route_order'],
                'crew_id'       => $row['crew_id'] ? (int)$row['crew_id'] : null,
                'crew_name'     => $row['crew_name'],
                'estimated_arrival'   => $row['estimated_arrival'],
                'estimated_departure' => $row['estimated_departure'],
                'stop_status'   => $row['stop_status'],
                'property_id'   => (int)$row['property_id'],
                'property_address' => $row['property_address'],
                'property_city' => $row['property_city'],
                'latitude'      => $row['latitude'],
                'longitude'     => $row['longitude'],
                'company_name'  => $row['company_name'],
                'visits'        => [],
            ];
        }

        // Add visit if present (LEFT JOIN can produce null visit)
        if ($row['visit_id']) {
            $result[$date][$stopId]['visits'][] = [
                'visit_id'       => (int)$row['visit_id'],
                'visit_number'   => $row['visit_number'],
                'visit_status'   => $row['visit_status'],
                'plan_id'        => (int)$row['plan_id'],
                'plan_title'     => $row['plan_title'],
                'plan_number'    => $row['plan_number'],
                'service_type'   => $row['service_type'],
                'price_per_visit' => $row['price_per_visit'],
                'estimated_duration' => $row['estimated_duration_minutes'],
                'scheduled_time_start' => $row['scheduled_time_start'],
                'scheduled_time_end'   => $row['scheduled_time_end'],
                'sequence_index' => (int)$row['sequence_index'],
            ];
        }
    }

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
               co.company_name,
               ct.first_name, ct.last_name, ct.email AS contact_email, ct.phone AS contact_phone,
               u.full_name AS default_crew_name,
               creator.full_name AS created_by_name,
               q.quote_number
        FROM job_plans jp
        LEFT JOIN properties p ON jp.property_id = p.id
        LEFT JOIN companies co ON jp.company_id = co.id
        LEFT JOIN contacts ct ON co.primary_contact_id = ct.id
        LEFT JOIN users u ON jp.default_crew_id = u.id
        LEFT JOIN users creator ON jp.created_by = creator.id
        LEFT JOIN quotes q ON jp.quote_id = q.id
        WHERE jp.id = ?
    ");
    $stmt->execute([$planId]);
    $plan = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$plan) return null;

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
        WHERE plan_id = ?
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
    try {
        $stmt = $db->prepare("
            UPDATE job_plans
            SET status = 'active', status_changed_at = NOW(),
                paused_at = NULL, paused_reason = NULL,
                visits_generated_through = NULL
            WHERE id = ? AND status = 'paused'
        ");
        $stmt->execute([$planId]);

        if ($stmt->rowCount() === 0) return false;

        // Regenerate visits
        generateVisits($planId);
        return true;
    } catch (Exception $e) {
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

    // If crew changed, also update calendar stops
    if (array_key_exists('default_crew_id', $changes)) {
        // This is trickier — we need to handle the UNIQUE constraint on stops
        // For now, update stops that only have visits from this plan
        // A more sophisticated approach would merge stops
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
        $stmt = $db->prepare("SELECT * FROM job_visits WHERE id = ? AND status = 'scheduled'");
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

        // Update visit
        $setClauses = ["scheduled_date = ?", "stop_id = ?"];
        $params = [$newDate, $newStopId];

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

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

        // Insert crew assignments if provided (outside transaction — non-critical)
        if (!empty($planData['crew_ids']) && is_array($planData['crew_ids'])) {
            setPlanCrewAssignments($planId, $planData['crew_ids'], $planData['default_crew_id'] ?? null);
        }

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

            createROIAttribution($result['plan_id'], $leadEventId, $quoteSource, $planData['estimated_amount']);
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

                    $dates = calculateRecurrenceDates($plan, $fromDate->format('Y-m-d'), $toDate->format('Y-m-d'), $holidays);
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
                    $targetDay = ($targetDow !== null) ? (int)$targetDow : (int)$planStart->format('w');
                    $shouldInclude = ($currentDow === $targetDay);
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
function getCalendarStops(string $startDate, string $endDate, ?int $crewId = null, ?string $serviceType = null): array {
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
            p.property_name,
            co.company_name,
            CONCAT(ct.first_name, ' ', ct.last_name) AS contact_name,
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

    // Collect all stop IDs to batch-load crew assignments from junction table
    $stopIds = [];
    foreach ($rows as $row) {
        $stopIds[(int)$row['stop_id']] = true;
    }
    $stopIds = array_keys($stopIds);

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
                'contact_name'  => $row['contact_name'],
                'property_name' => $row['property_name'],
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

            $autoClockInCol = $hasAutoClockInProd ? "MAX(p.auto_clock_in) AS auto_clock_in," : "";

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
// PLAN EDITING
// ============================================================================

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
            'estimated_duration_minutes',
            'default_crew_id', 'default_time_start', 'default_time_end',
            'plan_start_date', 'plan_end_date',
            'is_recurring', 'recurrence_pattern', 'recurrence_interval',
            'recurrence_interval_unit', 'recurrence_day_of_week',
            'horizon_days',
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

            // Reset generation tracker so visits regenerate from scratch
            $stmt = $db->prepare("UPDATE job_plans SET visits_generated_through = NULL WHERE id = ?");
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

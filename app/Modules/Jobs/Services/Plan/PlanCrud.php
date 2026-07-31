<?php
/**
 * Part of the Job Plan / Visit / Calendar Stop function library.
 * createJobPlan / createPlanFromQuote
 *
 * Loaded via app/Modules/Jobs/Services/PlanFunctions.php (aggregator).
 * Global functions — names/signatures unchanged from the original monolith.
 */

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

        // Get company_id from property if not provided: explicit company_properties
        // link first, then the same inferred fallback getCompanyProperties()/
        // BillToResolver use (the property's site contact being a company's
        // primary/billing contact) — otherwise plans for inferred-only company
        // relationships silently invoice the individual contact instead of the
        // company (see companies/view.php Jobs tab + invoice bill-to).
        $companyId = !empty($planData['company_id']) ? (int)$planData['company_id'] : null;
        if (!$companyId) {
            $stmt = $db->prepare("
                SELECT cp.company_id FROM company_properties cp WHERE cp.property_id = ? LIMIT 1
            ");
            $stmt->execute([$planData['property_id']]);
            $found = $stmt->fetchColumn();
            $companyId = $found ? (int)$found : null;
        }
        if (!$companyId) {
            $stmt = $db->prepare("
                SELECT co.id
                FROM properties p
                JOIN contacts con ON con.id = p.site_contact_id
                JOIN companies co ON (co.primary_contact_id = con.id OR co.billing_contact_id = con.id)
                WHERE p.id = ?
                LIMIT 1
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

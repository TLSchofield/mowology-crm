<?php
/**
 * Part of the Job Plan / Visit / Calendar Stop function library.
 * Plan line-item add/get/update + quote linkage helpers
 *
 * Loaded via app/Modules/Jobs/Services/PlanFunctions.php (aggregator).
 * Global functions — names/signatures unchanged from the original monolith.
 */

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

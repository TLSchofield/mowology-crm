<?php
/**
 * Customer Quote Upsell API
 *
 * Public (token-authenticated) endpoint for managing upsells on customer quotes.
 * No CRM login required — authenticates via the quote's access_token.
 *
 * Actions:
 *   get-upsells   (GET)  — returns available upsells for products in the quote
 *   add-upsell    (POST) — adds an upsell line item, recalculates totals
 *   remove-upsell (POST) — removes an upsell line item, recalculates totals
 */

// Bootstrap — find paths.php via upward search
$__dir = __DIR__;
for ($__i = 0; $__i < 5; $__i++) {
    $__dir = dirname($__dir);
    if (is_file($__dir . '/app/Core/paths.php')) {
        require_once $__dir . '/app/Core/paths.php';
        break;
    }
}
unset($__dir, $__i);

require_once PUBLIC_ROOT . '/app_config/config.php';
require_once APP_ROOT . '/Services/MeasurementService.php';
require_once APP_ROOT . '/Services/QuoteCalculator.php';

header('Content-Type: application/json');
header('X-Content-Type-Options: nosniff');

$db = getDB();
$action = $_GET['action'] ?? ($_POST['action'] ?? null);
$token  = $_GET['token'] ?? ($_POST['token'] ?? '');

try {
    // Validate token
    if (empty($token)) {
        throw new Exception('Missing quote token');
    }

    $quoteStmt = $db->prepare("
        SELECT q.id, q.property_id, q.status, q.subtotal, q.tax_rate, q.tax_amount, q.amount
        FROM quotes q
        WHERE q.access_token = ? AND q.token_expires_at > NOW()
    ");
    $quoteStmt->execute([$token]);
    $quote = $quoteStmt->fetch(PDO::FETCH_ASSOC);

    if (!$quote) {
        throw new Exception('Invalid or expired quote token');
    }

    // Allow upsell modification on both 'sent' (bundled price) and 'accepted' (regular price)
    if (!in_array($quote['status'], ['sent', 'accepted'])) {
        throw new Exception('This quote can no longer be modified');
    }
    $isPostAccept = ($quote['status'] === 'accepted');

    $quoteId    = (int)$quote['id'];
    $propertyId = (int)$quote['property_id'];

    if ($action === 'get-upsells') {
        // Get product IDs from this quote's line items
        $productIds = $db->prepare("
            SELECT DISTINCT product_id FROM quote_line_items
            WHERE quote_id = ? AND product_id IS NOT NULL AND is_upsell = 0
        ");
        $productIds->execute([$quoteId]);
        $ids = $productIds->fetchAll(PDO::FETCH_COLUMN, 0);

        if (empty($ids)) {
            echo json_encode(['success' => true, 'upsells' => []]);
            exit;
        }

        // Get upsells for these products — include bundled_price + is_popular (migration 1020)
        $placeholders = implode(',', array_fill(0, count($ids), '?'));
        $upsellStmt = $db->prepare("
            SELECT u.*, p.name as upsell_product_name, p.base_price as upsell_price,
                   p.description as upsell_description
            FROM product_upsells u
            JOIN products p ON u.upsell_product_id = p.id
            WHERE u.base_product_id IN ({$placeholders})
            AND u.is_active = 1 AND p.active = 1 AND p.is_archived = 0
            ORDER BY u.is_popular DESC, u.sort_order ASC
        ");
        $upsellStmt->execute($ids);
        $upsells = $upsellStmt->fetchAll(PDO::FETCH_ASSOC);

        // Check which upsells are already added to the quote
        $existingUpsells = $db->prepare("
            SELECT product_id FROM quote_line_items WHERE quote_id = ? AND is_upsell = 1
        ");
        $existingUpsells->execute([$quoteId]);
        $alreadyAdded = $existingUpsells->fetchAll(PDO::FETCH_COLUMN, 0);

        // For each upsell, calculate regular (full) price using pricing rule if available,
        // then derive bundled_price. bundled_price may be explicitly set on product_upsells
        // (from admin UI) OR fall back to 85% of regular price.
        foreach ($upsells as &$upsell) {
            $upsell['is_added'] = in_array($upsell['upsell_product_id'], $alreadyAdded);

            // Check for pricing rule to calculate measurement-based price
            $ruleStmt = $db->prepare("
                SELECT r.*, mg.group_key, mg.group_label, mg.unit
                FROM product_pricing_rules r
                JOIN measurement_groups mg ON r.measurement_group_id = mg.id
                WHERE r.product_id = ? AND r.is_active = 1
                ORDER BY r.priority DESC LIMIT 1
            ");
            $ruleStmt->execute([$upsell['upsell_product_id']]);
            $rule = $ruleStmt->fetch(PDO::FETCH_ASSOC);

            $regularPrice = (float)$upsell['upsell_price'];
            if ($rule) {
                $measurementTotals = getMeasurementTotalsForProperty($propertyId);
                $groupKey = $rule['group_key'];

                if (isset($measurementTotals[$groupKey])) {
                    $totalUnits = ($groupKey === 'hedge_linear')
                        ? $measurementTotals[$groupKey]['linear_ft']
                        : $measurementTotals[$groupKey]['sqft'];

                    $product = [
                        'id' => $upsell['upsell_product_id'],
                        'name' => $upsell['upsell_product_name'],
                        'base_price' => $upsell['upsell_price'],
                    ];

                    $calcItem = calculateLineItemFromRule($rule, $totalUnits, $product);
                    $regularPrice = (float)$calcItem['line_total'];
                }
            }

            // Bundled price — admin-set override, else 85% default
            $bundledPrice = isset($upsell['bundled_price']) && $upsell['bundled_price'] !== null
                ? (float)$upsell['bundled_price']
                : round($regularPrice * 0.85, 2);

            // Ensure bundled is never more than regular
            if ($bundledPrice > $regularPrice) $bundledPrice = $regularPrice;

            $upsell['regular_price']    = $regularPrice;
            $upsell['bundled_price']    = $bundledPrice;
            $upsell['savings']          = round($regularPrice - $bundledPrice, 2);
            $upsell['is_popular']       = !empty($upsell['is_popular']) ? 1 : 0;
            // Backwards-compat for existing frontend code
            $upsell['calculated_price'] = $isPostAccept ? $regularPrice : $bundledPrice;
        }
        unset($upsell);

        echo json_encode([
            'success'          => true,
            'upsells'          => $upsells,
            'is_post_accept'   => $isPostAccept,
        ]);

    } elseif ($action === 'add-upsell') {
        $upsellProductId = intval($_POST['upsell_product_id'] ?? 0);
        if (!$upsellProductId) throw new Exception('Upsell product ID is required');

        // Verify product exists and is active
        $prodStmt = $db->prepare("SELECT * FROM products WHERE id = ? AND active = 1 AND is_archived = 0");
        $prodStmt->execute([$upsellProductId]);
        $product = $prodStmt->fetch(PDO::FETCH_ASSOC);
        if (!$product) throw new Exception('Product not found or inactive');

        // Check not already added
        $existCheck = $db->prepare("
            SELECT id FROM quote_line_items WHERE quote_id = ? AND product_id = ? AND is_upsell = 1
        ");
        $existCheck->execute([$quoteId, $upsellProductId]);
        if ($existCheck->fetch()) {
            throw new Exception('This upsell is already added to the quote');
        }

        // Look up the product_upsells row for admin-set bundled_price
        $upsellMetaStmt = $db->prepare("
            SELECT bundled_price FROM product_upsells
            WHERE upsell_product_id = ? AND is_active = 1
            LIMIT 1
        ");
        $upsellMetaStmt->execute([$upsellProductId]);
        $upsellMeta = $upsellMetaStmt->fetch(PDO::FETCH_ASSOC);
        $adminBundledPrice = $upsellMeta && $upsellMeta['bundled_price'] !== null
            ? (float)$upsellMeta['bundled_price'] : null;

        // Calculate regular (full) price using pricing rule if available
        $ruleStmt = $db->prepare("
            SELECT r.*, mg.group_key, mg.group_label, mg.unit
            FROM product_pricing_rules r
            JOIN measurement_groups mg ON r.measurement_group_id = mg.id
            WHERE r.product_id = ? AND r.is_active = 1
            ORDER BY r.priority DESC LIMIT 1
        ");
        $ruleStmt->execute([$upsellProductId]);
        $rule = $ruleStmt->fetch(PDO::FETCH_ASSOC);

        $lineItem = null;
        if ($rule) {
            $measurementTotals = getMeasurementTotalsForProperty($propertyId);
            $groupKey = $rule['group_key'];

            if (isset($measurementTotals[$groupKey])) {
                $totalUnits = ($groupKey === 'hedge_linear')
                    ? $measurementTotals[$groupKey]['linear_ft']
                    : $measurementTotals[$groupKey]['sqft'];

                $lineItem = calculateLineItemFromRule($rule, $totalUnits, $product, $measurementTotals[$groupKey]['names'] ?? []);
            }
        }

        // Fallback to flat base_price
        if (!$lineItem) {
            $lineItem = [
                'product_id' => (int)$product['id'],
                'service_type' => $product['name'],
                'description' => $product['description'] ?? '',
                'quantity' => 1,
                'unit_type' => 'each',
                'unit_price' => (float)$product['base_price'],
                'line_total' => (float)$product['base_price'],
                'pricing_snapshot' => json_encode(['product_id' => $product['id'], 'flat_price' => $product['base_price'], 'calculated_at' => date('Y-m-d H:i:s')]),
            ];
        }

        // Apply bundled pricing discount if pre-accept
        $regularTotal = (float)$lineItem['line_total'];
        if (!$isPostAccept) {
            // Pre-accept: use bundled price (admin override or 85% default)
            $bundledTotal = $adminBundledPrice !== null ? $adminBundledPrice : round($regularTotal * 0.85, 2);
            if ($bundledTotal > $regularTotal) $bundledTotal = $regularTotal;
            $lineItem['line_total']  = $bundledTotal;
            $lineItem['unit_price']  = $bundledTotal / max(1, (float)($lineItem['quantity'] ?? 1));
            // Record the discount in pricing_snapshot for audit
            $snapshot = json_decode($lineItem['pricing_snapshot'] ?? '{}', true) ?: [];
            $snapshot['bundled_discount'] = [
                'regular_price' => $regularTotal,
                'bundled_price' => $bundledTotal,
                'savings'       => round($regularTotal - $bundledTotal, 2),
                'applied_at'    => date('Y-m-d H:i:s'),
            ];
            $lineItem['pricing_snapshot'] = json_encode($snapshot);
        }
        // Post-accept: keep regularTotal as the line_total (already set)

        // Get next sort_order
        $maxSort = $db->prepare("SELECT MAX(sort_order) as m FROM quote_line_items WHERE quote_id = ?");
        $maxSort->execute([$quoteId]);
        $nextSort = ($maxSort->fetch()['m'] ?? 0) + 1;

        // Insert line item — tag added_post_accept so we can audit/price differently
        // (column added by migration 1020; fall back gracefully if column missing)
        try {
            $insertStmt = $db->prepare("
                INSERT INTO quote_line_items
                    (quote_id, product_id, pricing_rule_id, measurement_group_key, service_type,
                     description, quantity, unit_type, unit_price, line_total,
                     units_used, price_per_unit, minimum_applied, included_units,
                     pricing_snapshot, sort_order, is_optional, is_upsell, added_post_accept)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 0, 1, ?)
            ");
            $insertStmt->execute([
                $quoteId,
                $lineItem['product_id'] ?? null,
                $lineItem['pricing_rule_id'] ?? null,
                $lineItem['measurement_group_key'] ?? null,
                $lineItem['service_type'],
                $lineItem['description'] ?? '',
                $lineItem['quantity'] ?? 1,
                $lineItem['unit_type'] ?? 'each',
                $lineItem['unit_price'],
                $lineItem['line_total'],
                $lineItem['units_used'] ?? null,
                $lineItem['price_per_unit'] ?? null,
                $lineItem['minimum_applied'] ?? 0,
                $lineItem['included_units'] ?? null,
                $lineItem['pricing_snapshot'] ?? null,
                $nextSort,
                $isPostAccept ? 1 : 0,
            ]);
        } catch (PDOException $e) {
            // Fallback for envs without migration 1020 yet — omit added_post_accept
            $insertStmt = $db->prepare("
                INSERT INTO quote_line_items
                    (quote_id, product_id, pricing_rule_id, measurement_group_key, service_type,
                     description, quantity, unit_type, unit_price, line_total,
                     units_used, price_per_unit, minimum_applied, included_units,
                     pricing_snapshot, sort_order, is_optional, is_upsell)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 0, 1)
            ");
            $insertStmt->execute([
                $quoteId,
                $lineItem['product_id'] ?? null,
                $lineItem['pricing_rule_id'] ?? null,
                $lineItem['measurement_group_key'] ?? null,
                $lineItem['service_type'],
                $lineItem['description'] ?? '',
                $lineItem['quantity'] ?? 1,
                $lineItem['unit_type'] ?? 'each',
                $lineItem['unit_price'],
                $lineItem['line_total'],
                $lineItem['units_used'] ?? null,
                $lineItem['price_per_unit'] ?? null,
                $lineItem['minimum_applied'] ?? 0,
                $lineItem['included_units'] ?? null,
                $lineItem['pricing_snapshot'] ?? null,
                $nextSort,
            ]);
        }

        // Recalculate quote totals
        $newTotals = recalculateQuoteTotals($quoteId);

        echo json_encode([
            'success'        => true,
            'message'        => 'Upsell added',
            'line_item_id'   => $db->lastInsertId(),
            'totals'         => $newTotals,
            'is_post_accept' => $isPostAccept,
            'line_total'     => (float)$lineItem['line_total'],
            'regular_total'  => $regularTotal,
            'savings_applied'=> $isPostAccept ? 0 : round($regularTotal - (float)$lineItem['line_total'], 2),
        ]);

    } elseif ($action === 'remove-upsell') {
        $upsellProductId = intval($_POST['upsell_product_id'] ?? 0);
        if (!$upsellProductId) throw new Exception('Upsell product ID is required');

        // Remove the upsell line item
        $stmt = $db->prepare("
            DELETE FROM quote_line_items
            WHERE quote_id = ? AND product_id = ? AND is_upsell = 1
        ");
        $stmt->execute([$quoteId, $upsellProductId]);

        if ($stmt->rowCount() === 0) {
            throw new Exception('Upsell not found on this quote');
        }

        // Recalculate totals
        $newTotals = recalculateQuoteTotals($quoteId);

        echo json_encode([
            'success' => true,
            'message' => 'Upsell removed',
            'totals' => $newTotals,
        ]);

    } else {
        throw new Exception('Invalid action');
    }

} catch (Exception $e) {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
}

/**
 * Recalculate and update quote totals from line items.
 */
function recalculateQuoteTotals(int $quoteId): array {
    $db = getDB();

    $stmt = $db->prepare("
        SELECT SUM(line_total) as subtotal
        FROM quote_line_items
        WHERE quote_id = ? AND is_optional = 0
    ");
    $stmt->execute([$quoteId]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);

    $subtotal  = round((float)$row['subtotal'], 2);
    $taxRate   = 0.05;
    $taxAmount = round($subtotal * $taxRate, 2);
    $total     = $subtotal + $taxAmount;

    $db->prepare("
        UPDATE quotes SET
            subtotal = ?, tax_amount = ?, amount = ?, total_amount = ?
        WHERE id = ?
    ")->execute([$subtotal, $taxAmount, $total, $total, $quoteId]);

    return [
        'subtotal'   => $subtotal,
        'tax_rate'   => $taxRate,
        'tax_amount' => $taxAmount,
        'total'      => $total,
    ];
}

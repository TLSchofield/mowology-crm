<?php
/**
 * QuoteCalculator — Deterministic pricing engine for measurement-linked auto-quoting.
 *
 * All functions are pure: they take data in and return results.
 * Prices are calculated and stored as snapshots on quote line items so
 * quotes never change if measurements or pricing rules are later updated.
 */

require_once dirname(__DIR__) . '/Core/paths.php';
require_once __DIR__ . '/MeasurementService.php';

/**
 * Calculate a single quote line item from a pricing rule + measurement total.
 *
 * @param array $rule  Row from product_pricing_rules JOIN measurement_groups:
 *   id, product_id, measurement_group_id, pricing_model, price_per_unit,
 *   minimum_price, included_units, default_frequency, group_key, group_label, unit
 * @param float $totalUnits  Total sqft or linear_ft for the measurement group
 * @param array $product     Row from products table
 * @param array $measurementNames  Names of measurements (e.g., ['Front Lawn', 'Back Lawn'])
 * @return array  Line item array ready for quote_line_items insertion
 */
function calculateLineItemFromRule(array $rule, float $totalUnits, array $product, array $measurementNames = []): array {
    $model       = $rule['pricing_model'];
    $perUnit     = (float)($rule['price_per_unit'] ?? 0);
    $minPrice    = (float)($rule['minimum_price'] ?? 0);
    $included    = (float)($rule['included_units'] ?? 0);
    $basePrice   = (float)($product['base_price'] ?? 0);
    $unitLabel   = $rule['unit'] ?? 'sqft';
    $groupKey    = $rule['group_key'] ?? '';
    $groupLabel  = $rule['group_label'] ?? '';

    $lineTotal    = 0;
    $minApplied   = false;

    switch ($model) {
        case 'flat':
            $lineTotal = $basePrice;
            break;

        case 'per_sqft':
            $lineTotal = $totalUnits * $perUnit;
            if ($minPrice > 0 && $lineTotal < $minPrice) {
                $lineTotal  = $minPrice;
                $minApplied = true;
            }
            break;

        case 'per_linear_ft':
            $lineTotal = $totalUnits * $perUnit;
            if ($minPrice > 0 && $lineTotal < $minPrice) {
                $lineTotal  = $minPrice;
                $minApplied = true;
            }
            break;

        case 'min_plus_sqft':
            $excessUnits = max(0, $totalUnits - $included);
            $lineTotal   = $minPrice + ($excessUnits * $perUnit);
            $minApplied  = true;
            break;

        case 'min_plus_linear_ft':
            $excessUnits = max(0, $totalUnits - $included);
            $lineTotal   = $minPrice + ($excessUnits * $perUnit);
            $minApplied  = true;
            break;
    }

    // Round to 2 decimals — line item always shows qty=1 at the calculated price.
    // Actual per-unit rate and total units are preserved in snapshot columns.
    $lineTotal = round($lineTotal, 2);

    // Build description with area names
    $areaDesc = !empty($measurementNames)
        ? implode(', ', $measurementNames) . ' (' . number_format($totalUnits) . ' ' . $unitLabel . ')'
        : number_format($totalUnits) . ' ' . $unitLabel;

    // Build pricing snapshot (immutable record of how price was calculated)
    $snapshot = json_encode([
        'rule_id'         => $rule['id'],
        'product_id'      => $product['id'],
        'product_name'    => $product['name'],
        'pricing_model'   => $model,
        'price_per_unit'  => $perUnit,
        'minimum_price'   => $minPrice,
        'included_units'  => $included,
        'total_units'     => $totalUnits,
        'group_key'       => $groupKey,
        'group_label'     => $groupLabel,
        'unit'            => $unitLabel,
        'base_price'      => $basePrice,
        'frequency'       => $rule['default_frequency'] ?? 'one_off',
        'calculated_at'   => date('Y-m-d H:i:s'),
    ]);

    return [
        'product_id'            => (int)$product['id'],
        'pricing_rule_id'       => (int)$rule['id'],
        'service_type'          => $product['name'],
        'description'           => $areaDesc,
        'quantity'              => 1,
        'unit_type'             => 'each',
        'unit_price'            => $lineTotal,
        'line_total'            => $lineTotal,
        'measurement_group_key' => $groupKey,
        'units_used'            => $totalUnits,
        'price_per_unit'        => $perUnit,
        'minimum_applied'       => $minApplied ? 1 : 0,
        'included_units'        => $included > 0 ? $included : null,
        'pricing_snapshot'      => $snapshot,
        'is_optional'           => false,
        'is_upsell'             => 0,
        'bundle_id'             => null,
    ];
}

/**
 * Auto-populate quote line items from property measurements.
 *
 * For each measurement group that has data, finds the default pricing rule
 * and calculates a line item.
 *
 * @param int        $propertyId
 * @param array|null $ruleOverrides  Optional: [group_key => rule_id] to force specific rules
 * @return array     ['items' => [...], 'measurements' => [...], 'warnings' => [...]]
 */
function autoPopulateQuoteFromMeasurements(int $propertyId, ?array $ruleOverrides = null): array {
    $db = getDB();
    $items    = [];
    $warnings = [];

    // Get measurement totals per group
    $measurementTotals = getMeasurementTotalsForProperty($propertyId);

    if (empty($measurementTotals)) {
        return [
            'items'        => [],
            'measurements' => [],
            'warnings'     => ['No measurements found for this property. Use the Area Measurement tool first.'],
        ];
    }

    // Get all active pricing rules with product + group info
    $rules = $db->query("
        SELECT r.*, mg.group_key, mg.group_label, mg.unit,
               p.name as product_name, p.base_price, p.base_cost,
               p.description as product_description, p.min_price as product_min_price,
               p.taxable, p.gst_rate
        FROM product_pricing_rules r
        JOIN measurement_groups mg ON r.measurement_group_id = mg.id
        JOIN products p ON r.product_id = p.id
        WHERE r.is_active = 1 AND p.active = 1 AND p.is_archived = 0 AND mg.is_active = 1
        ORDER BY r.priority DESC, r.id ASC
    ")->fetchAll(PDO::FETCH_ASSOC);

    // Index rules by group_key
    $rulesByGroup = [];
    foreach ($rules as $rule) {
        $rulesByGroup[$rule['group_key']][] = $rule;
    }

    // For each measurement group that has data, find best matching rule
    foreach ($measurementTotals as $groupKey => $totals) {
        $totalUnits = ($groupKey === 'hedge_linear')
            ? $totals['linear_ft']
            : $totals['sqft'];

        if ($totalUnits <= 0) continue;

        // Find the rule to use
        $selectedRule = null;

        // Check for explicit override
        if ($ruleOverrides && isset($ruleOverrides[$groupKey])) {
            $overrideId = (int)$ruleOverrides[$groupKey];
            foreach ($rules as $rule) {
                if ($rule['id'] === $overrideId) {
                    $selectedRule = $rule;
                    break;
                }
            }
        }

        // Fall back to default rule for this group
        if (!$selectedRule && isset($rulesByGroup[$groupKey])) {
            foreach ($rulesByGroup[$groupKey] as $rule) {
                if ($rule['is_default_for_group']) {
                    $selectedRule = $rule;
                    break;
                }
            }
            // If no default, use highest priority
            if (!$selectedRule) {
                $selectedRule = $rulesByGroup[$groupKey][0];
            }
        }

        if (!$selectedRule) {
            $warnings[] = "No pricing rule found for measurement group: {$groupKey}";
            continue;
        }

        // Build the product array from the rule's joined data
        $product = [
            'id'          => $selectedRule['product_id'],
            'name'        => $selectedRule['product_name'],
            'base_price'  => $selectedRule['base_price'],
            'base_cost'   => $selectedRule['base_cost'],
            'description' => $selectedRule['product_description'],
            'min_price'   => $selectedRule['product_min_price'],
        ];

        $lineItem = calculateLineItemFromRule(
            $selectedRule,
            $totalUnits,
            $product,
            $totals['names']
        );

        $items[] = $lineItem;
    }

    return [
        'items'        => $items,
        'measurements' => $measurementTotals,
        'warnings'     => $warnings,
    ];
}

/**
 * Auto-populate quote line items from specific selected pricing rule IDs.
 *
 * Unlike autoPopulateQuoteFromMeasurements() which picks one rule per group,
 * this generates one line item per selected rule — allowing multiple services
 * for the same measurement group (e.g., 7-day and 14-day lawn cut).
 *
 * @param int   $propertyId
 * @param int[] $selectedRuleIds  Array of product_pricing_rules.id values
 * @return array ['items' => [...], 'measurements' => [...], 'warnings' => [...]]
 */
function autoPopulateFromSelectedRules(int $propertyId, array $selectedRuleIds): array {
    $db = getDB();
    $items    = [];
    $warnings = [];

    if (empty($selectedRuleIds)) {
        return ['items' => [], 'measurements' => [], 'warnings' => ['No rules selected.']];
    }

    // Get measurement totals per group
    $measurementTotals = getMeasurementTotalsForProperty($propertyId);

    if (empty($measurementTotals)) {
        return [
            'items'        => [],
            'measurements' => [],
            'warnings'     => ['No measurements found for this property.'],
        ];
    }

    // Fetch the selected rules with product + group info
    $placeholders = implode(',', array_fill(0, count($selectedRuleIds), '?'));
    $stmt = $db->prepare("
        SELECT r.*, mg.group_key, mg.group_label, mg.unit,
               p.name as product_name, p.base_price, p.base_cost,
               p.description as product_description, p.min_price as product_min_price,
               p.taxable, p.gst_rate
        FROM product_pricing_rules r
        JOIN measurement_groups mg ON r.measurement_group_id = mg.id
        JOIN products p ON r.product_id = p.id
        WHERE r.id IN ($placeholders) AND r.is_active = 1 AND p.active = 1 AND p.is_archived = 0
        ORDER BY mg.sort_order, r.priority DESC
    ");
    $stmt->execute($selectedRuleIds);
    $rules = $stmt->fetchAll(PDO::FETCH_ASSOC);

    foreach ($rules as $rule) {
        $groupKey = $rule['group_key'];

        if (!isset($measurementTotals[$groupKey])) {
            $warnings[] = "No measurements for group: {$groupKey}";
            continue;
        }

        $totals = $measurementTotals[$groupKey];
        $totalUnits = ($groupKey === 'hedge_linear')
            ? $totals['linear_ft']
            : $totals['sqft'];

        if ($totalUnits <= 0) continue;

        $product = [
            'id'          => $rule['product_id'],
            'name'        => $rule['product_name'],
            'base_price'  => $rule['base_price'],
            'base_cost'   => $rule['base_cost'],
            'description' => $rule['product_description'],
            'min_price'   => $rule['product_min_price'],
        ];

        $items[] = calculateLineItemFromRule($rule, $totalUnits, $product, $totals['names']);
    }

    return [
        'items'        => $items,
        'measurements' => $measurementTotals,
        'warnings'     => $warnings,
    ];
}

/**
 * Calculate bundle line items for a property.
 *
 * @param int $bundleId
 * @param int $propertyId
 * @return array ['items' => [...], 'discount' => [...], 'warnings' => [...]]
 */
function calculateBundleLineItems(int $bundleId, int $propertyId): array {
    $db = getDB();
    $items    = [];
    $warnings = [];

    // Get bundle + items
    $bundle = $db->prepare("SELECT * FROM product_bundles WHERE id = ? AND is_active = 1")->execute([$bundleId]);
    $bundle = $db->prepare("SELECT * FROM product_bundles WHERE id = ? AND is_active = 1");
    $bundle->execute([$bundleId]);
    $bundle = $bundle->fetch(PDO::FETCH_ASSOC);

    if (!$bundle) {
        return ['items' => [], 'discount' => null, 'warnings' => ['Bundle not found or inactive.']];
    }

    $bundleItems = $db->prepare("
        SELECT bi.*, p.name as product_name, p.base_price, p.base_cost,
               p.description as product_description, p.min_price as product_min_price
        FROM product_bundle_items bi
        JOIN products p ON bi.product_id = p.id
        WHERE bi.bundle_id = ? AND p.active = 1 AND p.is_archived = 0
        ORDER BY bi.sort_order ASC
    ");
    $bundleItems->execute([$bundleId]);
    $bundleItems = $bundleItems->fetchAll(PDO::FETCH_ASSOC);

    $measurementTotals = getMeasurementTotalsForProperty($propertyId);

    $subtotal = 0;

    foreach ($bundleItems as $bi) {
        $product = [
            'id'          => $bi['product_id'],
            'name'        => $bi['product_name'],
            'base_price'  => $bi['base_price'],
            'base_cost'   => $bi['base_cost'],
            'description' => $bi['product_description'],
            'min_price'   => $bi['product_min_price'],
        ];

        // Check if this product has a pricing rule
        $ruleStmt = $db->prepare("
            SELECT r.*, mg.group_key, mg.group_label, mg.unit
            FROM product_pricing_rules r
            JOIN measurement_groups mg ON r.measurement_group_id = mg.id
            WHERE r.product_id = ? AND r.is_active = 1
            ORDER BY r.priority DESC
            LIMIT 1
        ");
        $ruleStmt->execute([$bi['product_id']]);
        $rule = $ruleStmt->fetch(PDO::FETCH_ASSOC);

        if ($rule && isset($measurementTotals[$rule['group_key']])) {
            $groupKey   = $rule['group_key'];
            $totalUnits = ($groupKey === 'hedge_linear')
                ? $measurementTotals[$groupKey]['linear_ft']
                : $measurementTotals[$groupKey]['sqft'];

            $lineItem = calculateLineItemFromRule($rule, $totalUnits * $bi['quantity_multiplier'], $product, $measurementTotals[$groupKey]['names'] ?? []);
        } else {
            // No rule or no measurements — use flat base_price
            $price = $bi['override_price'] ?? $product['base_price'];
            $lineItem = [
                'product_id'            => (int)$product['id'],
                'pricing_rule_id'       => null,
                'service_type'          => $product['name'],
                'description'           => $product['description'] ?? '',
                'quantity'              => $bi['quantity_multiplier'],
                'unit_type'             => 'each',
                'unit_price'            => (float)$price,
                'line_total'            => round((float)$price * $bi['quantity_multiplier'], 2),
                'measurement_group_key' => null,
                'units_used'            => null,
                'price_per_unit'        => null,
                'minimum_applied'       => 0,
                'included_units'        => null,
                'pricing_snapshot'      => json_encode(['bundle_id' => $bundleId, 'product_id' => $product['id'], 'calculated_at' => date('Y-m-d H:i:s')]),
                'is_optional'           => false,
                'is_upsell'             => 0,
                'bundle_id'             => $bundleId,
            ];
        }

        $lineItem['bundle_id'] = $bundleId;
        $subtotal += $lineItem['line_total'];
        $items[] = $lineItem;
    }

    // Apply bundle discount
    $discountLine = null;
    if ($bundle['discount_value'] > 0 && $subtotal > 0) {
        $discountAmount = ($bundle['discount_type'] === 'percentage')
            ? round($subtotal * ($bundle['discount_value'] / 100), 2)
            : min($bundle['discount_value'], $subtotal);

        $discountLine = [
            'product_id'            => null,
            'pricing_rule_id'       => null,
            'service_type'          => 'Bundle Discount (' . htmlspecialchars($bundle['bundle_name']) . ')',
            'description'           => ($bundle['discount_type'] === 'percentage')
                ? $bundle['discount_value'] . '% bundle discount'
                : '$' . number_format($bundle['discount_value'], 2) . ' bundle discount',
            'quantity'              => 1,
            'unit_type'             => 'each',
            'unit_price'            => -$discountAmount,
            'line_total'            => -$discountAmount,
            'measurement_group_key' => null,
            'units_used'            => null,
            'price_per_unit'        => null,
            'minimum_applied'       => 0,
            'included_units'        => null,
            'pricing_snapshot'      => json_encode(['bundle_id' => $bundleId, 'discount_type' => $bundle['discount_type'], 'discount_value' => $bundle['discount_value']]),
            'is_optional'           => false,
            'is_upsell'             => 0,
            'bundle_id'             => $bundleId,
        ];
    }

    return [
        'items'    => $items,
        'discount' => $discountLine,
        'warnings' => $warnings,
    ];
}

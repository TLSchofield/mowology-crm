<?php
/**
 * API: Quote Auto-Fill from Measurements
 *
 * Actions:
 *   get-measurements  (GET)  — returns measurement totals for a property
 *   auto-fill         (POST) — calculates line items from pricing rules + measurements
 *   preview-bundle    (POST) — preview a bundle applied to property measurements
 *
 * Returns JSON. Requires CRM login.
 */

// Bootstrap
$__dir = __DIR__;
for ($__i = 0; $__i < 5; $__i++) {
    $__dir = dirname($__dir);
    if (is_file($__dir . '/app/Core/paths.php')) {
        require_once $__dir . '/app/Core/paths.php';
        break;
    }
}
unset($__dir, $__i);

require_once PUBLIC_ROOT . '/loginAuth/auth.php';
require_once CRM_INCLUDES . '/functions.php';
require_once APP_ROOT . '/Services/MeasurementService.php';
require_once APP_ROOT . '/Services/QuoteCalculator.php';

requireLogin();
$user = getCurrentUser();

header('Content-Type: application/json');

$action = $_GET['action'] ?? ($_POST['action'] ?? null);
$db = getDB();

try {

    if ($action === 'get-measurements') {
        // GET: Return measurement totals for a property
        $propertyId = intval($_GET['property_id'] ?? 0);
        if (!$propertyId) throw new Exception('Property ID is required');

        $totals = getMeasurementTotalsForProperty($propertyId);

        // Also get available pricing rules per group
        $rules = $db->query("
            SELECT r.id, r.product_id, r.measurement_group_id, r.pricing_model,
                   r.price_per_unit, r.minimum_price, r.included_units,
                   r.default_frequency, r.is_default_for_group,
                   mg.group_key, mg.group_label, mg.unit,
                   p.name as product_name, p.base_price
            FROM product_pricing_rules r
            JOIN measurement_groups mg ON r.measurement_group_id = mg.id
            JOIN products p ON r.product_id = p.id
            WHERE r.is_active = 1 AND p.active = 1 AND p.is_archived = 0
            ORDER BY mg.sort_order, r.priority DESC
        ")->fetchAll(PDO::FETCH_ASSOC);

        // Group rules by group_key
        $rulesByGroup = [];
        foreach ($rules as $rule) {
            $rulesByGroup[$rule['group_key']][] = $rule;
        }

        echo json_encode([
            'success'      => true,
            'measurements' => $totals,
            'rules'        => $rulesByGroup,
            'has_data'     => !empty($totals),
        ]);

    } elseif ($action === 'auto-fill') {
        // POST: Calculate line items from pricing rules + measurements
        $propertyId = intval($_POST['property_id'] ?? 0);
        if (!$propertyId) throw new Exception('Property ID is required');

        // New: selected_rules = array of specific rule IDs to generate items for
        $selectedRules = null;
        if (!empty($_POST['selected_rules'])) {
            $selectedRules = json_decode($_POST['selected_rules'], true);
        }

        // Legacy: rule overrides per group (single rule per group)
        $ruleOverrides = null;
        if (!empty($_POST['rule_overrides'])) {
            $ruleOverrides = json_decode($_POST['rule_overrides'], true);
        }

        if ($selectedRules && is_array($selectedRules)) {
            $result = autoPopulateFromSelectedRules($propertyId, $selectedRules);
        } else {
            $result = autoPopulateQuoteFromMeasurements($propertyId, $ruleOverrides);
        }

        echo json_encode([
            'success'      => true,
            'items'        => $result['items'],
            'measurements' => $result['measurements'],
            'warnings'     => $result['warnings'],
        ]);

    } elseif ($action === 'preview-bundle') {
        // POST: Preview a bundle applied to property measurements
        $bundleId   = intval($_POST['bundle_id'] ?? 0);
        $propertyId = intval($_POST['property_id'] ?? 0);
        if (!$bundleId || !$propertyId) throw new Exception('Bundle ID and Property ID are required');

        $result = calculateBundleLineItems($bundleId, $propertyId);

        $items = $result['items'];
        if ($result['discount']) {
            $items[] = $result['discount'];
        }

        echo json_encode([
            'success'  => true,
            'items'    => $items,
            'warnings' => $result['warnings'],
        ]);

    } else {
        throw new Exception('Invalid action: ' . htmlspecialchars($action ?? ''));
    }

} catch (Exception $e) {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
}

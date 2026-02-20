<?php
/**
 * API: Product and Category Management
 * Handles CRUD operations for products and categories
 * Returns JSON
 */

if (!defined('APP_ROOT')) {
    $__dir = __DIR__;
    for ($__i = 0; $__i < 5; $__i++) {
        $__dir = dirname($__dir);
        if (is_file($__dir . '/app/Core/paths.php')) {
            require_once $__dir . '/app/Core/paths.php';
            break;
        }
    }
    unset($__dir, $__i);
}

require_once PUBLIC_ROOT . '/loginAuth/auth.php';
require_once CRM_INCLUDES . '/functions.php';

requireLogin();
$user = getCurrentUser();

header('Content-Type: application/json');

$action = $_GET['action'] ?? null;
$db = getDB();

try {
    if ($action === 'get-categories') {
        // Get all active categories for dropdown
        $stmt = $db->prepare("
            SELECT id, name, description, display_order
            FROM product_categories
            WHERE active = 1
            ORDER BY display_order, name
        ");
        $stmt->execute();
        echo json_encode([
            'success' => true,
            'categories' => $stmt->fetchAll(PDO::FETCH_ASSOC)
        ]);

    } elseif ($action === 'add-category') {
        // Add new category
        $data = json_decode(file_get_contents('php://input'), true);

        if (empty($data['name'])) {
            throw new Exception('Category name is required');
        }

        $stmt = $db->prepare("
            INSERT INTO product_categories (name, description, display_order, active)
            VALUES (?, ?, ?, 1)
        ");
        $stmt->execute([
            $data['name'],
            $data['description'] ?? null,
            $data['display_order'] ?? 0
        ]);

        echo json_encode([
            'success' => true,
            'id' => $db->lastInsertId(),
            'message' => 'Category added successfully'
        ]);

    } elseif ($action === 'update-category') {
        // Update category
        $data = json_decode(file_get_contents('php://input'), true);

        if (empty($data['id']) || empty($data['name'])) {
            throw new Exception('Category ID and name are required');
        }

        $stmt = $db->prepare("
            UPDATE product_categories
            SET name = ?, description = ?, display_order = ?
            WHERE id = ?
        ");
        $stmt->execute([
            $data['name'],
            $data['description'] ?? null,
            $data['display_order'] ?? 0,
            $data['id']
        ]);

        echo json_encode([
            'success' => true,
            'message' => 'Category updated successfully'
        ]);

    } elseif ($action === 'delete-category') {
        // Delete category (only if no products use it)
        $data = json_decode(file_get_contents('php://input'), true);

        if (empty($data['id'])) {
            throw new Exception('Category ID is required');
        }

        // Check for products in this category
        $stmt = $db->prepare("SELECT COUNT(*) as count FROM products WHERE category_id = ? AND is_archived = 0");
        $stmt->execute([$data['id']]);
        $result = $stmt->fetch();

        if ($result['count'] > 0) {
            throw new Exception('Cannot delete category with active products. Archive the products first.');
        }

        $stmt = $db->prepare("DELETE FROM product_categories WHERE id = ?");
        $stmt->execute([$data['id']]);

        echo json_encode([
            'success' => true,
            'message' => 'Category deleted successfully'
        ]);

    } elseif ($action === 'save-product') {
        // Save product (create or update)
        $data = json_decode(file_get_contents('php://input'), true);

        if (empty($data['name']) || empty($data['category_id'])) {
            throw new Exception('Product name and category are required');
        }

        // Normalize checkbox values: "on" → 1, missing/falsy → 0
        $boolFields = ['uses_cost_calculator', 'taxable', 'track_inventory', 'active', 'featured'];
        foreach ($boolFields as $field) {
            $val = $data[$field] ?? null;
            $data[$field] = ($val === 'on' || $val === '1' || $val === 1 || $val === true) ? 1 : 0;
        }
        // Default taxable to 1 if not explicitly set
        if (!isset($data['taxable']) || $data['taxable'] === '') {
            $data['taxable'] = 1;
        }

        // Normalize SKU: empty string → null (UNIQUE constraint allows multiple NULLs but not multiple empty strings)
        if (isset($data['sku']) && trim($data['sku']) === '') {
            $data['sku'] = null;
        }

        // Check if min_price column exists (migration 119 may not have run)
        $hasMinPrice = false;
        try {
            $colCheck = $db->query("SHOW COLUMNS FROM products LIKE 'min_price'");
            $hasMinPrice = ($colCheck->rowCount() > 0);
        } catch (Exception $e) {
            // Ignore — column doesn't exist
        }

        // Check if weather_policy column exists (migration 204)
        $hasWeatherPolicy = false;
        try {
            $wpCheck = $db->query("SHOW COLUMNS FROM products LIKE 'weather_policy'");
            $hasWeatherPolicy = ($wpCheck->rowCount() > 0);
        } catch (Exception $e) {
            // Ignore
        }

        // Check if rollover columns exist (migration 502)
        $hasRollover = false;
        try {
            $roCheck = $db->query("SHOW COLUMNS FROM products LIKE 'auto_rollover'");
            $hasRollover = ($roCheck->rowCount() > 0);
        } catch (Exception $e) {
            // Ignore
        }

        // Check if product intelligence columns exist (migration 600)
        $hasProductIntel = false;
        try {
            $piCheck = $db->query("SHOW COLUMNS FROM products LIKE 'sds_sheet_url'");
            $hasProductIntel = ($piCheck->rowCount() > 0);
        } catch (Exception $e) {
            // Ignore
        }

        // Check if tracking flag columns exist (tracking flags migration)
        $hasTrackingFlags = false;
        $hasAutoClockIn = false;
        try {
            $tfCheck = $db->query("SHOW COLUMNS FROM products LIKE 'tracking_level'");
            $hasTrackingFlags = ($tfCheck->rowCount() > 0);
            $aciCheck = $db->query("SHOW COLUMNS FROM products LIKE 'auto_clock_in'");
            $hasAutoClockIn = ($aciCheck->rowCount() > 0);
        } catch (Exception $e) {
            // Ignore
        }

        // Validate weather_policy value
        $validPolicies = ['ANY', 'DRY_ONLY', 'LIGHT_RAIN_OK', 'TEMP_LIMITED', 'WIND_LIMITED'];
        $weatherPolicy = (isset($data['weather_policy']) && in_array($data['weather_policy'], $validPolicies))
            ? $data['weather_policy'] : 'ANY';

        // Validate tracking_level value
        $validTrackingLevels = ['standard', 'heightened', 'custom'];
        $trackingLevel = (isset($data['tracking_level']) && in_array($data['tracking_level'], $validTrackingLevels))
            ? $data['tracking_level'] : 'standard';

        if (empty($data['id'])) {
            // Create new product
            $columns = "name, category_id, unit_type_id, description, long_description,
                    base_cost, base_price, markup_percentage, uses_cost_calculator,
                    taxable, gst_rate, pst_rate, track_inventory, current_stock,
                    reorder_point, supplier_info, image_url, display_order, active,
                    featured, created_by, sku";
            $placeholders = "?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?";
            $params = [
                $data['name'],
                $data['category_id'],
                $data['unit_type_id'] ?? 1,
                $data['description'] ?? null,
                $data['long_description'] ?? null,
                $data['base_cost'] ?? 0,
                $data['base_price'] ?? 0,
                $data['markup_percentage'] ?? 35,
                $data['uses_cost_calculator'],
                $data['taxable'],
                $data['gst_rate'] ?? 5,
                $data['pst_rate'] ?? 0,
                $data['track_inventory'],
                $data['current_stock'] ?? 0,
                $data['reorder_point'] ?? 0,
                $data['supplier_info'] ?? null,
                $data['image_url'] ?? null,
                $data['display_order'] ?? 0,
                $data['active'],
                $data['featured'],
                $user['id'],
                $data['sku'] ?? null
            ];

            if ($hasMinPrice) {
                $columns = "name, category_id, unit_type_id, description, long_description,
                    base_cost, base_price, min_price, markup_percentage, uses_cost_calculator,
                    taxable, gst_rate, pst_rate, track_inventory, current_stock,
                    reorder_point, supplier_info, image_url, display_order, active,
                    featured, created_by, sku";
                $placeholders = "?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?";
                // Insert min_price after base_price (index 6)
                array_splice($params, 7, 0, [!empty($data['min_price']) ? $data['min_price'] : null]);
            }

            if ($hasWeatherPolicy) {
                $columns .= ", weather_policy";
                $placeholders .= ", ?";
                $params[] = $weatherPolicy;
            }

            if ($hasTrackingFlags) {
                $columns .= ", tracking_level, require_clock_in, require_gps, require_photos";
                $placeholders .= ", ?, ?, ?, ?";
                $params[] = $trackingLevel;
                $params[] = !empty($data['require_clock_in']) ? 1 : 0;
                $params[] = !empty($data['require_gps']) ? 1 : 0;
                $params[] = !empty($data['require_photos']) ? 1 : 0;
            }

            if ($hasAutoClockIn) {
                $columns .= ", auto_clock_in";
                $placeholders .= ", ?";
                $params[] = !empty($data['auto_clock_in']) ? 1 : 0;
            }

            if ($hasRollover) {
                $columns .= ", auto_rollover, max_rollover_days";
                $placeholders .= ", ?, ?";
                $params[] = isset($data['auto_rollover']) ? (int)$data['auto_rollover'] : 1;
                $params[] = !empty($data['max_rollover_days']) ? (int)$data['max_rollover_days'] : null;
            }

            if ($hasProductIntel) {
                $columns .= ", sds_sheet_url, application_notes, best_season, trigger_month, ph_range_min, ph_range_max, dilution_rate, application_rate, safety_warnings, crew_talking_points";
                $placeholders .= ", ?, ?, ?, ?, ?, ?, ?, ?, ?, ?";
                $params[] = !empty($data['sds_sheet_url']) ? $data['sds_sheet_url'] : null;
                $params[] = !empty($data['application_notes']) ? $data['application_notes'] : null;
                $params[] = !empty($data['best_season']) ? $data['best_season'] : null;
                $params[] = !empty($data['trigger_month']) ? (int)$data['trigger_month'] : null;
                $params[] = isset($data['ph_range_min']) && $data['ph_range_min'] !== '' ? $data['ph_range_min'] : null;
                $params[] = isset($data['ph_range_max']) && $data['ph_range_max'] !== '' ? $data['ph_range_max'] : null;
                $params[] = !empty($data['dilution_rate']) ? $data['dilution_rate'] : null;
                $params[] = !empty($data['application_rate']) ? $data['application_rate'] : null;
                $params[] = !empty($data['safety_warnings']) ? $data['safety_warnings'] : null;
                $params[] = !empty($data['crew_talking_points']) ? $data['crew_talking_points'] : null;
            }

            $stmt = $db->prepare("INSERT INTO products ({$columns}) VALUES ({$placeholders})");
            $stmt->execute($params);

            $productId = $db->lastInsertId();
            echo json_encode([
                'success' => true,
                'id' => $productId,
                'message' => 'Product created successfully'
            ]);
        } else {
            // Update existing product
            $setClauses = "name = ?, category_id = ?, unit_type_id = ?, description = ?,
                    long_description = ?, base_cost = ?, base_price = ?,
                    markup_percentage = ?, taxable = ?, gst_rate = ?, pst_rate = ?,
                    track_inventory = ?, current_stock = ?, reorder_point = ?,
                    supplier_info = ?, image_url = ?, display_order = ?,
                    active = ?, featured = ?";
            $params = [
                $data['name'],
                $data['category_id'],
                $data['unit_type_id'] ?? 1,
                $data['description'] ?? null,
                $data['long_description'] ?? null,
                $data['base_cost'] ?? 0,
                $data['base_price'] ?? 0,
                $data['markup_percentage'] ?? 35,
                $data['taxable'],
                $data['gst_rate'] ?? 5,
                $data['pst_rate'] ?? 0,
                $data['track_inventory'],
                $data['current_stock'] ?? 0,
                $data['reorder_point'] ?? 0,
                $data['supplier_info'] ?? null,
                $data['image_url'] ?? null,
                $data['display_order'] ?? 0,
                $data['active'],
                $data['featured']
            ];

            if ($hasMinPrice) {
                $setClauses = "name = ?, category_id = ?, unit_type_id = ?, description = ?,
                    long_description = ?, base_cost = ?, base_price = ?, min_price = ?,
                    markup_percentage = ?, taxable = ?, gst_rate = ?, pst_rate = ?,
                    track_inventory = ?, current_stock = ?, reorder_point = ?,
                    supplier_info = ?, image_url = ?, display_order = ?,
                    active = ?, featured = ?";
                // Insert min_price after base_price (index 6)
                array_splice($params, 7, 0, [!empty($data['min_price']) ? $data['min_price'] : null]);
            }

            if ($hasWeatherPolicy) {
                $setClauses .= ", weather_policy = ?";
                $params[] = $weatherPolicy;
            }

            if ($hasTrackingFlags) {
                $setClauses .= ", tracking_level = ?, require_clock_in = ?, require_gps = ?, require_photos = ?";
                $params[] = $trackingLevel;
                $params[] = !empty($data['require_clock_in']) ? 1 : 0;
                $params[] = !empty($data['require_gps']) ? 1 : 0;
                $params[] = !empty($data['require_photos']) ? 1 : 0;
            }

            if ($hasAutoClockIn) {
                $setClauses .= ", auto_clock_in = ?";
                $params[] = !empty($data['auto_clock_in']) ? 1 : 0;
            }

            if ($hasRollover) {
                $setClauses .= ", auto_rollover = ?, max_rollover_days = ?";
                $params[] = isset($data['auto_rollover']) ? (int)$data['auto_rollover'] : 1;
                $params[] = !empty($data['max_rollover_days']) ? (int)$data['max_rollover_days'] : null;
            }

            if ($hasProductIntel) {
                $setClauses .= ", sds_sheet_url = ?, application_notes = ?, best_season = ?, trigger_month = ?, ph_range_min = ?, ph_range_max = ?, dilution_rate = ?, application_rate = ?, safety_warnings = ?, crew_talking_points = ?";
                $params[] = !empty($data['sds_sheet_url']) ? $data['sds_sheet_url'] : null;
                $params[] = !empty($data['application_notes']) ? $data['application_notes'] : null;
                $params[] = !empty($data['best_season']) ? $data['best_season'] : null;
                $params[] = !empty($data['trigger_month']) ? (int)$data['trigger_month'] : null;
                $params[] = isset($data['ph_range_min']) && $data['ph_range_min'] !== '' ? $data['ph_range_min'] : null;
                $params[] = isset($data['ph_range_max']) && $data['ph_range_max'] !== '' ? $data['ph_range_max'] : null;
                $params[] = !empty($data['dilution_rate']) ? $data['dilution_rate'] : null;
                $params[] = !empty($data['application_rate']) ? $data['application_rate'] : null;
                $params[] = !empty($data['safety_warnings']) ? $data['safety_warnings'] : null;
                $params[] = !empty($data['crew_talking_points']) ? $data['crew_talking_points'] : null;
            }

            $params[] = $data['id'];
            $stmt = $db->prepare("UPDATE products SET {$setClauses} WHERE id = ?");
            $stmt->execute($params);

            echo json_encode([
                'success' => true,
                'message' => 'Product updated successfully'
            ]);
        }

    } elseif ($action === 'archive-product') {
        // Archive a product (soft delete - preserves history)
        $data = json_decode(file_get_contents('php://input'), true);

        if (empty($data['id'])) {
            throw new Exception('Product ID is required');
        }

        $stmt = $db->prepare("
            UPDATE products
            SET is_archived = 1, archived_at = NOW(), archived_by = ?
            WHERE id = ?
        ");
        $stmt->execute([$user['id'], $data['id']]);

        echo json_encode([
            'success' => true,
            'message' => 'Product archived successfully'
        ]);

    } elseif ($action === 'restore-product') {
        // Restore an archived product
        $data = json_decode(file_get_contents('php://input'), true);

        if (empty($data['id'])) {
            throw new Exception('Product ID is required');
        }

        $stmt = $db->prepare("
            UPDATE products
            SET is_archived = 0, archived_at = NULL, archived_by = NULL
            WHERE id = ?
        ");
        $stmt->execute([$data['id']]);

        echo json_encode([
            'success' => true,
            'message' => 'Product restored successfully'
        ]);

    } elseif ($action === 'list-products') {
        // List products with optional filtering
        $includeArchived = isset($_GET['archived']) && $_GET['archived'] === '1';
        $category = $_GET['category'] ?? null;
        $search = $_GET['search'] ?? null;

        $sql = "
            SELECT p.*, c.name as category_name, u.abbreviation as unit_abbreviation
            FROM products p
            LEFT JOIN product_categories c ON p.category_id = c.id
            LEFT JOIN unit_types u ON p.unit_type_id = u.id
            WHERE 1
        ";

        $params = [];

        if (!$includeArchived) {
            $sql .= " AND p.is_archived = 0";
        }

        if ($category) {
            $sql .= " AND p.category_id = ?";
            $params[] = $category;
        }

        if ($search) {
            $sql .= " AND (p.name LIKE ? OR p.description LIKE ? OR p.sku LIKE ?)";
            $pattern = "%{$search}%";
            $params[] = $pattern;
            $params[] = $pattern;
            $params[] = $pattern;
        }

        $sql .= " ORDER BY p.display_order, p.name";

        $stmt = $db->prepare($sql);
        $stmt->execute($params);

        echo json_encode([
            'success' => true,
            'products' => $stmt->fetchAll(PDO::FETCH_ASSOC)
        ]);

    } elseif ($action === 'bulk-delete') {
        // Bulk delete products (hard delete for test data cleanup)
        $data = json_decode(file_get_contents('php://input'), true);
        $ids = array_filter(array_map('intval', $data['ids'] ?? []), function($id) { return $id > 0; });

        if (empty($ids)) {
            throw new Exception('No valid product IDs provided');
        }

        if (count($ids) > 100) {
            throw new Exception('Maximum 100 products can be deleted at once');
        }

        // Check for products referenced elsewhere — archive those instead of deleting
        $placeholders = implode(',', array_fill(0, count($ids), '?'));
        $protectedIds = [];

        // Check upsells (as the upsell target)
        $stmt = $db->prepare("SELECT DISTINCT upsell_product_id FROM product_upsells WHERE upsell_product_id IN ({$placeholders})");
        $stmt->execute($ids);
        foreach ($stmt->fetchAll(PDO::FETCH_COLUMN) as $pid) {
            $protectedIds[(int)$pid] = true;
        }

        // Check bundle items
        $stmt = $db->prepare("SELECT DISTINCT product_id FROM product_bundle_items WHERE product_id IN ({$placeholders})");
        $stmt->execute($ids);
        foreach ($stmt->fetchAll(PDO::FETCH_COLUMN) as $pid) {
            $protectedIds[(int)$pid] = true;
        }

        // Check quote line items
        $stmt = $db->prepare("SELECT DISTINCT product_id FROM quote_line_items WHERE product_id IN ({$placeholders})");
        $stmt->execute($ids);
        foreach ($stmt->fetchAll(PDO::FETCH_COLUMN) as $pid) {
            $protectedIds[(int)$pid] = true;
        }

        // Check plan line items
        try {
            $stmt = $db->prepare("SELECT DISTINCT product_id FROM plan_line_items WHERE product_id IN ({$placeholders})");
            $stmt->execute($ids);
            foreach ($stmt->fetchAll(PDO::FETCH_COLUMN) as $pid) {
                $protectedIds[(int)$pid] = true;
            }
        } catch (Exception $e) {
            // plan_line_items may not exist yet
        }

        $deletableIds = array_values(array_filter($ids, function($id) use ($protectedIds) {
            return !isset($protectedIds[$id]);
        }));
        $archiveIds = array_values(array_filter($ids, function($id) use ($protectedIds) {
            return isset($protectedIds[$id]);
        }));

        $db->beginTransaction();
        try {
            $deleted = 0;
            $archived = 0;

            if (!empty($deletableIds)) {
                $delPlaceholders = implode(',', array_fill(0, count($deletableIds), '?'));
                $stmt = $db->prepare("DELETE FROM products WHERE id IN ({$delPlaceholders})");
                $stmt->execute($deletableIds);
                $deleted = $stmt->rowCount();
            }

            if (!empty($archiveIds)) {
                $archPlaceholders = implode(',', array_fill(0, count($archiveIds), '?'));
                $archParams = array_merge([$user['id']], $archiveIds);
                $stmt = $db->prepare("UPDATE products SET is_archived = 1, archived_at = NOW(), archived_by = ? WHERE id IN ({$archPlaceholders})");
                $stmt->execute($archParams);
                $archived = $stmt->rowCount();
            }

            $db->commit();

            $msg = $deleted . ' product(s) deleted';
            if ($archived > 0) {
                $msg .= ', ' . $archived . ' product(s) archived instead (referenced in quotes, plans, upsells, or bundles)';
            }

            echo json_encode([
                'success' => true,
                'deleted_count' => $deleted,
                'archived_count' => $archived,
                'message' => $msg
            ]);
        } catch (Exception $e) {
            $db->rollBack();
            throw $e;
        }

    // ── Measurement Groups ──────────────────────────────────

    } elseif ($action === 'get-measurement-groups') {
        $stmt = $db->query("SELECT * FROM measurement_groups WHERE is_active = 1 ORDER BY sort_order");
        echo json_encode(['success' => true, 'groups' => $stmt->fetchAll(PDO::FETCH_ASSOC)]);

    // ── Product Pricing Rules ──────────────────────────────

    } elseif ($action === 'get-pricing-rules') {
        $productId = isset($_GET['product_id']) ? intval($_GET['product_id']) : 0;

        $sql = "
            SELECT r.*, mg.group_key, mg.group_label, mg.unit
            FROM product_pricing_rules r
            JOIN measurement_groups mg ON r.measurement_group_id = mg.id
        ";
        $params = [];
        if ($productId) {
            $sql .= " WHERE r.product_id = ?";
            $params[] = $productId;
        }
        $sql .= " ORDER BY r.product_id, r.priority DESC";

        $stmt = $db->prepare($sql);
        $stmt->execute($params);
        echo json_encode(['success' => true, 'rules' => $stmt->fetchAll(PDO::FETCH_ASSOC)]);

    } elseif ($action === 'save-pricing-rule') {
        $data = json_decode(file_get_contents('php://input'), true);

        if (empty($data['product_id']) || empty($data['measurement_group_id']) || empty($data['pricing_model'])) {
            throw new Exception('Product, measurement group, and pricing model are required');
        }

        $validModels = ['flat', 'per_sqft', 'per_linear_ft', 'min_plus_sqft', 'min_plus_linear_ft'];
        if (!in_array($data['pricing_model'], $validModels)) {
            throw new Exception('Invalid pricing model');
        }

        $validFreqs = ['one_off', 'daily', '7_day', '14_day', '21_day', 'monthly', 'seasonal'];
        $freq = (isset($data['default_frequency']) && in_array($data['default_frequency'], $validFreqs))
            ? $data['default_frequency'] : 'one_off';

        if (!empty($data['id'])) {
            // Update
            $stmt = $db->prepare("
                UPDATE product_pricing_rules SET
                    measurement_group_id = ?, pricing_model = ?, price_per_unit = ?,
                    minimum_price = ?, included_units = ?, default_frequency = ?,
                    is_default_for_group = ?, priority = ?, notes = ?, is_active = ?
                WHERE id = ? AND product_id = ?
            ");
            $stmt->execute([
                $data['measurement_group_id'],
                $data['pricing_model'],
                $data['price_per_unit'] ?? 0,
                $data['minimum_price'] ?? 0,
                $data['included_units'] ?? 0,
                $freq,
                $data['is_default_for_group'] ?? 0,
                $data['priority'] ?? 0,
                $data['notes'] ?? null,
                $data['is_active'] ?? 1,
                $data['id'],
                $data['product_id'],
            ]);
            echo json_encode(['success' => true, 'message' => 'Pricing rule updated']);
        } else {
            // Create
            $stmt = $db->prepare("
                INSERT INTO product_pricing_rules
                    (product_id, measurement_group_id, pricing_model, price_per_unit,
                     minimum_price, included_units, default_frequency,
                     is_default_for_group, priority, notes, is_active)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
            ");
            $stmt->execute([
                $data['product_id'],
                $data['measurement_group_id'],
                $data['pricing_model'],
                $data['price_per_unit'] ?? 0,
                $data['minimum_price'] ?? 0,
                $data['included_units'] ?? 0,
                $freq,
                $data['is_default_for_group'] ?? 0,
                $data['priority'] ?? 0,
                $data['notes'] ?? null,
                $data['is_active'] ?? 1,
            ]);
            echo json_encode(['success' => true, 'id' => $db->lastInsertId(), 'message' => 'Pricing rule created']);
        }

    } elseif ($action === 'delete-pricing-rule') {
        $data = json_decode(file_get_contents('php://input'), true);
        if (empty($data['id'])) throw new Exception('Rule ID is required');

        $stmt = $db->prepare("DELETE FROM product_pricing_rules WHERE id = ?");
        $stmt->execute([$data['id']]);
        echo json_encode(['success' => true, 'message' => 'Pricing rule deleted']);

    } elseif ($action === 'delete-all-pricing-rules') {
        $data = json_decode(file_get_contents('php://input'), true);
        if (empty($data['product_id'])) throw new Exception('Product ID is required');

        $stmt = $db->prepare("DELETE FROM product_pricing_rules WHERE product_id = ?");
        $stmt->execute([intval($data['product_id'])]);
        echo json_encode(['success' => true, 'message' => 'All pricing rules deleted for product']);

    // ── Product Upsells ────────────────────────────────────

    } elseif ($action === 'get-upsells') {
        $productId = isset($_GET['product_id']) ? intval($_GET['product_id']) : 0;

        $sql = "
            SELECT u.*, p.name as upsell_product_name, p.base_price as upsell_price,
                   p.description as upsell_description
            FROM product_upsells u
            JOIN products p ON u.upsell_product_id = p.id
        ";
        $params = [];
        if ($productId) {
            $sql .= " WHERE u.base_product_id = ?";
            $params[] = $productId;
        }
        $sql .= " ORDER BY u.sort_order";

        $stmt = $db->prepare($sql);
        $stmt->execute($params);
        echo json_encode(['success' => true, 'upsells' => $stmt->fetchAll(PDO::FETCH_ASSOC)]);

    } elseif ($action === 'save-upsell') {
        $data = json_decode(file_get_contents('php://input'), true);

        if (empty($data['base_product_id']) || empty($data['upsell_product_id'])) {
            throw new Exception('Base product and upsell product are required');
        }

        if ($data['base_product_id'] == $data['upsell_product_id']) {
            throw new Exception('A product cannot be its own upsell');
        }

        $validTypes = ['recommended', 'addon', 'upgrade'];
        $type = (isset($data['upsell_type']) && in_array($data['upsell_type'], $validTypes))
            ? $data['upsell_type'] : 'recommended';

        if (!empty($data['id'])) {
            $stmt = $db->prepare("
                UPDATE product_upsells SET
                    upsell_product_id = ?, upsell_type = ?, display_text = ?,
                    default_checked = ?, sort_order = ?, is_active = ?
                WHERE id = ?
            ");
            $stmt->execute([
                $data['upsell_product_id'],
                $type,
                $data['display_text'] ?? null,
                $data['default_checked'] ?? 0,
                $data['sort_order'] ?? 0,
                $data['is_active'] ?? 1,
                $data['id'],
            ]);
            echo json_encode(['success' => true, 'message' => 'Upsell updated']);
        } else {
            $stmt = $db->prepare("
                INSERT INTO product_upsells
                    (base_product_id, upsell_product_id, upsell_type, display_text,
                     default_checked, sort_order, is_active)
                VALUES (?, ?, ?, ?, ?, ?, ?)
            ");
            $stmt->execute([
                $data['base_product_id'],
                $data['upsell_product_id'],
                $type,
                $data['display_text'] ?? null,
                $data['default_checked'] ?? 0,
                $data['sort_order'] ?? 0,
                $data['is_active'] ?? 1,
            ]);
            echo json_encode(['success' => true, 'id' => $db->lastInsertId(), 'message' => 'Upsell created']);
        }

    } elseif ($action === 'delete-upsell') {
        $data = json_decode(file_get_contents('php://input'), true);
        if (empty($data['id'])) throw new Exception('Upsell ID is required');

        $stmt = $db->prepare("DELETE FROM product_upsells WHERE id = ?");
        $stmt->execute([$data['id']]);
        echo json_encode(['success' => true, 'message' => 'Upsell deleted']);

    } elseif ($action === 'delete-all-upsells') {
        $data = json_decode(file_get_contents('php://input'), true);
        if (empty($data['product_id'])) throw new Exception('Product ID is required');

        $stmt = $db->prepare("DELETE FROM product_upsells WHERE base_product_id = ?");
        $stmt->execute([intval($data['product_id'])]);
        echo json_encode(['success' => true, 'message' => 'All upsells deleted for product']);

    // ── Product Bundles ────────────────────────────────────

    } elseif ($action === 'get-bundles') {
        $stmt = $db->query("
            SELECT b.*, GROUP_CONCAT(bi.product_id ORDER BY bi.sort_order) as product_ids
            FROM product_bundles b
            LEFT JOIN product_bundle_items bi ON b.id = bi.bundle_id
            WHERE b.is_active = 1
            GROUP BY b.id
            ORDER BY b.sort_order
        ");
        $bundles = $stmt->fetchAll(PDO::FETCH_ASSOC);

        // Load items for each bundle
        foreach ($bundles as &$bundle) {
            $itemStmt = $db->prepare("
                SELECT bi.*, p.name as product_name, p.base_price, p.description as product_description
                FROM product_bundle_items bi
                JOIN products p ON bi.product_id = p.id
                WHERE bi.bundle_id = ?
                ORDER BY bi.sort_order
            ");
            $itemStmt->execute([$bundle['id']]);
            $bundle['items'] = $itemStmt->fetchAll(PDO::FETCH_ASSOC);
        }
        unset($bundle);

        echo json_encode(['success' => true, 'bundles' => $bundles]);

    } elseif ($action === 'save-bundle') {
        $data = json_decode(file_get_contents('php://input'), true);

        if (empty($data['bundle_name'])) {
            throw new Exception('Bundle name is required');
        }

        $validTiers = ['good', 'better', 'best', 'custom'];
        $tier = (isset($data['tier']) && in_array($data['tier'], $validTiers)) ? $data['tier'] : 'custom';

        $validDiscountTypes = ['percentage', 'fixed'];
        $discountType = (isset($data['discount_type']) && in_array($data['discount_type'], $validDiscountTypes))
            ? $data['discount_type'] : 'percentage';

        $db->beginTransaction();
        try {
            if (!empty($data['id'])) {
                $stmt = $db->prepare("
                    UPDATE product_bundles SET
                        bundle_name = ?, tier = ?, description = ?,
                        discount_type = ?, discount_value = ?, is_active = ?, sort_order = ?
                    WHERE id = ?
                ");
                $stmt->execute([
                    $data['bundle_name'], $tier, $data['description'] ?? null,
                    $discountType, $data['discount_value'] ?? 0,
                    $data['is_active'] ?? 1, $data['sort_order'] ?? 0, $data['id'],
                ]);
                $bundleId = $data['id'];

                // Clear existing items
                $db->prepare("DELETE FROM product_bundle_items WHERE bundle_id = ?")->execute([$bundleId]);
            } else {
                $stmt = $db->prepare("
                    INSERT INTO product_bundles (bundle_name, tier, description, discount_type, discount_value, is_active, sort_order)
                    VALUES (?, ?, ?, ?, ?, ?, ?)
                ");
                $stmt->execute([
                    $data['bundle_name'], $tier, $data['description'] ?? null,
                    $discountType, $data['discount_value'] ?? 0,
                    $data['is_active'] ?? 1, $data['sort_order'] ?? 0,
                ]);
                $bundleId = $db->lastInsertId();
            }

            // Insert bundle items
            if (!empty($data['items']) && is_array($data['items'])) {
                $itemStmt = $db->prepare("
                    INSERT INTO product_bundle_items (bundle_id, product_id, quantity_multiplier, override_price, sort_order)
                    VALUES (?, ?, ?, ?, ?)
                ");
                foreach ($data['items'] as $idx => $item) {
                    if (empty($item['product_id'])) continue;
                    $itemStmt->execute([
                        $bundleId,
                        $item['product_id'],
                        $item['quantity_multiplier'] ?? 1,
                        !empty($item['override_price']) ? $item['override_price'] : null,
                        $item['sort_order'] ?? $idx,
                    ]);
                }
            }

            $db->commit();
            echo json_encode(['success' => true, 'id' => $bundleId, 'message' => 'Bundle saved']);
        } catch (Exception $e) {
            $db->rollBack();
            throw $e;
        }

    } elseif ($action === 'delete-bundle') {
        $data = json_decode(file_get_contents('php://input'), true);
        if (empty($data['id'])) throw new Exception('Bundle ID is required');

        $stmt = $db->prepare("DELETE FROM product_bundles WHERE id = ?");
        $stmt->execute([$data['id']]);
        echo json_encode(['success' => true, 'message' => 'Bundle deleted']);

    // ── Vendor Supply (Product Intelligence) ────────────

    } elseif ($action === 'get-vendor-supply') {
        // Get vendor products linked to an internal product, with price info
        $productId = isset($_GET['product_id']) ? intval($_GET['product_id']) : 0;
        if (!$productId) throw new Exception('Product ID is required');

        // Check if vendor_products.product_id column exists (migration 514)
        $hasVpLink = false;
        try {
            $vpCheck = $db->query("SHOW COLUMNS FROM vendor_products LIKE 'product_id'");
            $hasVpLink = ($vpCheck->rowCount() > 0);
        } catch (Exception $e) {
            // vendor_products table may not exist
        }

        if (!$hasVpLink) {
            echo json_encode(['success' => true, 'vendors' => []]);
        } else {
            $stmt = $db->prepare("
                SELECT vp.id AS vendor_product_id,
                       vp.name AS vendor_product_name,
                       vp.price_per_unit AS last_price,
                       vp.unit,
                       v.name AS vendor_name,
                       (SELECT MAX(e.expense_date)
                        FROM expenses e
                        WHERE e.vendor_id = vp.vendor_id) AS last_purchased
                FROM vendor_products vp
                JOIN vendors v ON v.id = vp.vendor_id
                WHERE vp.product_id = ? AND vp.is_active = 1
                ORDER BY v.name, vp.name
            ");
            $stmt->execute([$productId]);
            $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

            // Since there's no price history table, set prev_price/price_change to null
            foreach ($rows as &$row) {
                $row['prev_price'] = null;
                $row['price_change'] = 0;
            }
            unset($row);

            echo json_encode(['success' => true, 'vendors' => $rows]);
        }

    } elseif ($action === 'search-vendor-products') {
        // Search unlinked vendor products for linking to an internal product
        $search = trim($_GET['search'] ?? '');
        if (strlen($search) < 2) {
            echo json_encode(['success' => true, 'results' => []]);
        } else {
            // Check if vendor_products.product_id column exists
            $hasVpLink = false;
            try {
                $vpCheck = $db->query("SHOW COLUMNS FROM vendor_products LIKE 'product_id'");
                $hasVpLink = ($vpCheck->rowCount() > 0);
            } catch (Exception $e) {
                // vendor_products table may not exist
            }

            if (!$hasVpLink) {
                echo json_encode(['success' => true, 'results' => []]);
            } else {
                $like = '%' . $search . '%';
                $stmt = $db->prepare("
                    SELECT vp.id, vp.name, vp.price_per_unit, vp.unit,
                           v.name AS vendor_name
                    FROM vendor_products vp
                    JOIN vendors v ON v.id = vp.vendor_id
                    WHERE vp.is_active = 1
                      AND (vp.product_id IS NULL)
                      AND (vp.name LIKE ? OR v.name LIKE ?)
                    ORDER BY v.name, vp.name
                    LIMIT 20
                ");
                $stmt->execute([$like, $like]);

                echo json_encode(['success' => true, 'results' => $stmt->fetchAll(PDO::FETCH_ASSOC)]);
            }
        }

    } elseif ($action === 'link-vendor-product') {
        // Link a vendor product to this internal product
        $data = json_decode(file_get_contents('php://input'), true);

        $vpId = isset($data['vendor_product_id']) ? intval($data['vendor_product_id']) : 0;
        $productId = isset($data['product_id']) ? intval($data['product_id']) : 0;

        if (!$vpId) throw new Exception('Vendor product ID is required');
        if (!$productId) throw new Exception('Product ID is required');

        // Verify both exist
        $checkVp = $db->prepare("SELECT id FROM vendor_products WHERE id = ?");
        $checkVp->execute([$vpId]);
        if (!$checkVp->fetch()) throw new Exception('Vendor product not found');

        $checkP = $db->prepare("SELECT id, name FROM products WHERE id = ?");
        $checkP->execute([$productId]);
        $product = $checkP->fetch(PDO::FETCH_ASSOC);
        if (!$product) throw new Exception('Product not found');

        $stmt = $db->prepare("UPDATE vendor_products SET product_id = ? WHERE id = ?");
        $stmt->execute([$productId, $vpId]);

        echo json_encode([
            'success' => true,
            'message' => 'Linked to ' . $product['name']
        ]);

    } elseif ($action === 'unlink-vendor-product') {
        // Remove the link between a vendor product and this internal product
        $data = json_decode(file_get_contents('php://input'), true);

        $vpId = isset($data['vendor_product_id']) ? intval($data['vendor_product_id']) : 0;
        if (!$vpId) throw new Exception('Vendor product ID is required');

        $stmt = $db->prepare("UPDATE vendor_products SET product_id = NULL WHERE id = ?");
        $stmt->execute([$vpId]);

        echo json_encode(['success' => true, 'message' => 'Vendor product unlinked']);

    } else {
        throw new Exception('Invalid action: ' . htmlspecialchars($action));
    }

} catch (Exception $e) {
    http_response_code(400);
    echo json_encode([
        'success' => false,
        'error' => $e->getMessage()
    ]);
}

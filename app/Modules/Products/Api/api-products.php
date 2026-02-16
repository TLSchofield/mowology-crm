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

        $db->beginTransaction();
        try {
            $placeholders = implode(',', array_fill(0, count($ids), '?'));
            $stmt = $db->prepare("DELETE FROM products WHERE id IN ({$placeholders})");
            $stmt->execute($ids);
            $deleted = $stmt->rowCount();
            $db->commit();

            echo json_encode([
                'success' => true,
                'deleted_count' => $deleted,
                'message' => $deleted . ' product(s) deleted'
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

        $validFreqs = ['one_off', '7_day', '14_day', '21_day', 'monthly', 'seasonal'];
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

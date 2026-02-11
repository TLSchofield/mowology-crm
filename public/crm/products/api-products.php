<?php
/**
 * API: Product and Category Management
 * Handles CRUD operations for products and categories
 * Returns JSON
 */

require_once dirname(__DIR__) . '/../loginAuth/auth.php';
require_once dirname(__DIR__) . '/includes/functions.php';

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

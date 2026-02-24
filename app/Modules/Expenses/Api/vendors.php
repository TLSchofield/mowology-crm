<?php
/**
 * Vendors API — CRUD for vendor directory
 *
 * GET:  ?action=list
 * GET:  ?action=get&id=X             — full vendor detail (includes products, locations, expenses)
 * GET:  ?action=products&vendor_id=X  — vendor products grouped by category with internal product links
 * GET:  ?action=search&q=term         — autocomplete search
 * POST: {action: 'create', ...fields}
 * POST: {action: 'update', id, ...fields}
 * POST: {action: 'link_vendor_product', vendor_product_id, product_id}   — link vendor product → internal product
 * POST: {action: 'unlink_vendor_product', vendor_product_id}             — remove link
 * POST: {action: 'add_location', vendor_id, label, address, lat, lng, city}
 * POST: {action: 'delete_location', id}
 */
declare(strict_types=1);
header('Content-Type: application/json');

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

try {
    require_once PUBLIC_ROOT . '/loginAuth/auth.php';
    require_once CRM_INCLUDES . '/functions.php';

    requireLogin();
    $user = getCurrentUser();
    requirePermission('expenses.view');

    $canEdit = userHasPermission('expenses.edit');
    $db = getDB();

    if ($_SERVER['REQUEST_METHOD'] === 'GET') {
        $action = $_GET['action'] ?? '';
    } else {
        $input = json_decode(file_get_contents('php://input'), true);
        $action = $input['action'] ?? '';
    }

    switch ($action) {
        case 'list':
            handleVendorList($db);
            break;

        case 'get':
            handleVendorGet($db);
            break;

        case 'search':
            handleVendorSearch($db);
            break;

        case 'products':
            handleVendorProducts($db);
            break;

        case 'link_vendor_product':
            if (!$canEdit) throw new Exception('Permission denied');
            handleLinkVendorProduct($db, $input);
            break;

        case 'unlink_vendor_product':
            if (!$canEdit) throw new Exception('Permission denied');
            handleUnlinkVendorProduct($db, $input);
            break;

        case 'create':
            if (!$canEdit) throw new Exception('Permission denied');
            handleVendorCreate($db, $input);
            break;

        case 'update':
            if (!$canEdit) throw new Exception('Permission denied');
            handleVendorUpdate($db, $input);
            break;

        case 'add_location':
            if (!$canEdit) throw new Exception('Permission denied');
            handleAddLocation($db, $input);
            break;

        case 'delete_location':
            if (!$canEdit) throw new Exception('Permission denied');
            handleDeleteLocation($db, $input);
            break;

        case 'add_vendor_product':
            if (!$canEdit) throw new Exception('Permission denied');
            handleAddVendorProduct($db, $input);
            break;

        case 'delete_vendor_product':
            if (!$canEdit) throw new Exception('Permission denied');
            handleDeleteVendorProduct($db, $input);
            break;

        case 'delete':
            if (!$canEdit) throw new Exception('Permission denied');
            handleVendorDelete($db, $input);
            break;

        case 'categories':
            handleCategories();
            break;

        default:
            throw new Exception('Invalid action');
    }

} catch (Exception $e) {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
}


function handleVendorList(PDO $db): void
{
    $includeInactive = ($_GET['include_inactive'] ?? '0') === '1';

    $where = $includeInactive ? '' : 'WHERE v.is_active = 1';

    $stmt = $db->query("
        SELECT v.*,
               (SELECT COUNT(*) FROM vendor_locations WHERE vendor_id = v.id) AS location_count,
               (SELECT COUNT(*) FROM expenses WHERE vendor_id = v.id) AS expense_count,
               (SELECT COALESCE(SUM(total), 0) FROM expenses WHERE vendor_id = v.id) AS total_spent,
               (SELECT COUNT(*) FROM vendor_products WHERE vendor_id = v.id AND is_active = 1) AS product_count
        FROM vendors v
        {$where}
        ORDER BY v.name
    ");
    $vendors = $stmt->fetchAll(PDO::FETCH_ASSOC);

    echo json_encode(['success' => true, 'vendors' => $vendors]);
}


function handleVendorGet(PDO $db): void
{
    $id = (int)($_GET['id'] ?? 0);
    if (!$id) throw new Exception('Vendor ID required');

    $stmt = $db->prepare("SELECT * FROM vendors WHERE id = ?");
    $stmt->execute([$id]);
    $vendor = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$vendor) throw new Exception('Vendor not found');

    // Get locations
    $locStmt = $db->prepare("SELECT * FROM vendor_locations WHERE vendor_id = ? ORDER BY label");
    $locStmt->execute([$id]);
    $vendor['locations'] = $locStmt->fetchAll(PDO::FETCH_ASSOC);

    // Get vendor products with linked internal product info
    $prodStmt = $db->prepare("
        SELECT vp.id, vp.name, vp.category, vp.unit, vp.price_per_unit,
               vp.alt_unit, vp.alt_price, vp.bag_price, vp.ocr_aliases,
               vp.product_id, vp.is_active,
               p.name AS linked_product_name, p.sku AS linked_product_sku
        FROM vendor_products vp
        LEFT JOIN products p ON p.id = vp.product_id
        WHERE vp.vendor_id = ? AND vp.is_active = 1
        ORDER BY vp.category, vp.name
    ");
    $prodStmt->execute([$id]);
    $vendor['products'] = $prodStmt->fetchAll(PDO::FETCH_ASSOC);
    $vendor['product_count'] = count($vendor['products']);

    // Recent expenses
    $expStmt = $db->prepare("
        SELECT id, expense_date, total, accounting_category, description
        FROM expenses WHERE vendor_id = ?
        ORDER BY expense_date DESC LIMIT 10
    ");
    $expStmt->execute([$id]);
    $vendor['recent_expenses'] = $expStmt->fetchAll(PDO::FETCH_ASSOC);

    // Spend summary
    $spendStmt = $db->prepare("
        SELECT COUNT(*) AS expense_count,
               COALESCE(SUM(total), 0) AS total_spent,
               MIN(expense_date) AS first_expense,
               MAX(expense_date) AS last_expense
        FROM expenses WHERE vendor_id = ?
    ");
    $spendStmt->execute([$id]);
    $vendor['spend_summary'] = $spendStmt->fetch(PDO::FETCH_ASSOC);

    echo json_encode(['success' => true, 'vendor' => $vendor]);
}


function handleVendorSearch(PDO $db): void
{
    $q = trim($_GET['q'] ?? '');
    if (strlen($q) < 2) {
        echo json_encode(['success' => true, 'vendors' => []]);
        return;
    }

    $stmt = $db->prepare("
        SELECT id, name, aliases, default_accounting_category, default_gbp_category
        FROM vendors
        WHERE is_active = 1
          AND (name LIKE ? OR aliases LIKE ?)
        ORDER BY name
        LIMIT 20
    ");
    $like = '%' . $q . '%';
    $stmt->execute([$like, $like]);

    echo json_encode(['success' => true, 'vendors' => $stmt->fetchAll(PDO::FETCH_ASSOC)]);
}


function handleVendorCreate(PDO $db, ?array $input): void
{
    if (!verifyCSRFToken($input['csrf_token'] ?? '')) {
        throw new Exception('Invalid security token');
    }

    $name = trim($input['name'] ?? '');
    if (empty($name)) throw new Exception('Vendor name is required');

    $stmt = $db->prepare("
        INSERT INTO vendors (name, aliases, default_accounting_category, default_gbp_category, phone, website, notes, gst_exempt)
        VALUES (?, ?, ?, ?, ?, ?, ?, ?)
    ");
    $stmt->execute([
        $name,
        $input['aliases'] ?? null,
        $input['default_accounting_category'] ?? null,
        $input['default_gbp_category'] ?? null,
        $input['phone'] ?? null,
        $input['website'] ?? null,
        $input['notes'] ?? null,
        (int)($input['gst_exempt'] ?? 0),
    ]);

    echo json_encode([
        'success' => true,
        'message' => 'Vendor created',
        'vendor_id' => (int)$db->lastInsertId(),
    ]);
}


function handleVendorUpdate(PDO $db, ?array $input): void
{
    if (!verifyCSRFToken($input['csrf_token'] ?? '')) {
        throw new Exception('Invalid security token');
    }

    $id = (int)($input['id'] ?? 0);
    if (!$id) throw new Exception('Vendor ID required');

    $name = trim($input['name'] ?? '');
    if (empty($name)) throw new Exception('Vendor name is required');

    $stmt = $db->prepare("
        UPDATE vendors SET
            name = ?, aliases = ?, default_accounting_category = ?,
            default_gbp_category = ?, phone = ?, website = ?,
            notes = ?, gst_exempt = ?, is_active = ?
        WHERE id = ?
    ");
    $stmt->execute([
        $name,
        $input['aliases'] ?? null,
        $input['default_accounting_category'] ?? null,
        $input['default_gbp_category'] ?? null,
        $input['phone'] ?? null,
        $input['website'] ?? null,
        $input['notes'] ?? null,
        (int)($input['gst_exempt'] ?? 0),
        isset($input['is_active']) ? (int)$input['is_active'] : 1,
        $id,
    ]);

    echo json_encode(['success' => true, 'message' => 'Vendor updated']);
}


function handleAddLocation(PDO $db, ?array $input): void
{
    if (!verifyCSRFToken($input['csrf_token'] ?? '')) {
        throw new Exception('Invalid security token');
    }

    $vendorId = (int)($input['vendor_id'] ?? 0);
    if (!$vendorId) throw new Exception('Vendor ID required');

    $stmt = $db->prepare("
        INSERT INTO vendor_locations (vendor_id, label, address, lat, lng, city)
        VALUES (?, ?, ?, ?, ?, ?)
    ");
    $stmt->execute([
        $vendorId,
        $input['label'] ?? null,
        $input['address'] ?? null,
        !empty($input['lat']) ? (float)$input['lat'] : null,
        !empty($input['lng']) ? (float)$input['lng'] : null,
        $input['city'] ?? null,
    ]);

    echo json_encode([
        'success' => true,
        'message' => 'Location added',
        'location_id' => (int)$db->lastInsertId(),
    ]);
}


function handleDeleteLocation(PDO $db, ?array $input): void
{
    if (!verifyCSRFToken($input['csrf_token'] ?? '')) {
        throw new Exception('Invalid security token');
    }

    $id = (int)($input['id'] ?? 0);
    if (!$id) throw new Exception('Location ID required');

    $stmt = $db->prepare("DELETE FROM vendor_locations WHERE id = ?");
    $stmt->execute([$id]);

    echo json_encode(['success' => true, 'message' => 'Location deleted']);
}


function handleVendorDelete(PDO $db, array $input): void
{
    $id = (int)($input['id'] ?? 0);
    if (!$id) throw new Exception('Vendor ID is required');

    // Check for linked expenses first
    $stmt = $db->prepare("SELECT COUNT(*) FROM expenses WHERE vendor_id = ?");
    $stmt->execute([$id]);
    $count = (int)$stmt->fetchColumn();

    if ($count > 0) {
        // Soft-delete: set is_active = 0 instead of deleting
        $db->prepare("UPDATE vendors SET is_active = 0 WHERE id = ?")->execute([$id]);
        echo json_encode(['success' => true, 'message' => 'Vendor deactivated (has linked expenses)']);
        return;
    }

    // Hard delete: no linked expenses
    $db->prepare("DELETE FROM vendor_locations WHERE vendor_id = ?")->execute([$id]);
    $db->prepare("DELETE FROM vendors WHERE id = ?")->execute([$id]);
    echo json_encode(['success' => true, 'message' => 'Vendor deleted']);
}


function handleVendorProducts(PDO $db): void
{
    $vendorId = (int)($_GET['vendor_id'] ?? 0);
    if (!$vendorId) throw new Exception('Vendor ID required');

    // Get products grouped by category, with linked internal product info
    $stmt = $db->prepare("
        SELECT vp.id, vp.name, vp.category, vp.unit, vp.price_per_unit,
               vp.alt_unit, vp.alt_price, vp.bag_price, vp.ocr_aliases,
               vp.product_id, vp.is_active,
               p.name AS linked_product_name, p.sku AS linked_product_sku
        FROM vendor_products vp
        LEFT JOIN products p ON p.id = vp.product_id
        WHERE vp.vendor_id = ? AND vp.is_active = 1
        ORDER BY vp.category, vp.name
    ");
    $stmt->execute([$vendorId]);
    $products = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // Group by category
    $grouped = [];
    foreach ($products as $p) {
        $cat = $p['category'] ?: 'Uncategorized';
        if (!isset($grouped[$cat])) {
            $grouped[$cat] = [];
        }
        $grouped[$cat][] = $p;
    }

    echo json_encode([
        'success'  => true,
        'products' => $products,
        'grouped'  => $grouped,
        'count'    => count($products),
    ]);
}


function handleLinkVendorProduct(PDO $db, ?array $input): void
{
    if (!verifyCSRFToken($input['csrf_token'] ?? '')) {
        throw new Exception('Invalid security token');
    }

    $vpId = (int)($input['vendor_product_id'] ?? 0);
    $productId = (int)($input['product_id'] ?? 0);
    if (!$vpId) throw new Exception('Vendor product ID required');
    if (!$productId) throw new Exception('Internal product ID required');

    // Verify both exist
    $checkVp = $db->prepare("SELECT id FROM vendor_products WHERE id = ?");
    $checkVp->execute([$vpId]);
    if (!$checkVp->fetch()) throw new Exception('Vendor product not found');

    $checkP = $db->prepare("SELECT id, name FROM products WHERE id = ?");
    $checkP->execute([$productId]);
    $product = $checkP->fetch(PDO::FETCH_ASSOC);
    if (!$product) throw new Exception('Internal product not found');

    // Update the link
    $stmt = $db->prepare("UPDATE vendor_products SET product_id = ? WHERE id = ?");
    $stmt->execute([$productId, $vpId]);

    echo json_encode([
        'success' => true,
        'message' => 'Linked to ' . $product['name'],
        'linked_product_name' => $product['name'],
    ]);
}


function handleUnlinkVendorProduct(PDO $db, ?array $input): void
{
    if (!verifyCSRFToken($input['csrf_token'] ?? '')) {
        throw new Exception('Invalid security token');
    }

    $vpId = (int)($input['vendor_product_id'] ?? 0);
    if (!$vpId) throw new Exception('Vendor product ID required');

    $stmt = $db->prepare("UPDATE vendor_products SET product_id = NULL WHERE id = ?");
    $stmt->execute([$vpId]);

    echo json_encode(['success' => true, 'message' => 'Product unlinked']);
}


function handleAddVendorProduct(PDO $db, ?array $input): void
{
    if (!verifyCSRFToken($input['csrf_token'] ?? '')) {
        throw new Exception('Invalid security token');
    }

    $vendorId = (int)($input['vendor_id'] ?? 0);
    if (!$vendorId) throw new Exception('Vendor ID required');

    $name = trim($input['name'] ?? '');
    if (empty($name)) throw new Exception('Product name is required');

    $stmt = $db->prepare("
        INSERT INTO vendor_products (vendor_id, name, category, unit, price_per_unit, alt_unit, alt_price, bag_price, ocr_aliases)
        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)
    ");
    $stmt->execute([
        $vendorId,
        $name,
        $input['category'] ?? null,
        $input['unit'] ?? null,
        !empty($input['price_per_unit']) ? (float)$input['price_per_unit'] : null,
        $input['alt_unit'] ?? null,
        !empty($input['alt_price']) ? (float)$input['alt_price'] : null,
        !empty($input['bag_price']) ? (float)$input['bag_price'] : null,
        $input['ocr_aliases'] ?? null,
    ]);

    echo json_encode([
        'success' => true,
        'message' => 'Product added',
        'product_id' => (int)$db->lastInsertId(),
    ]);
}


function handleDeleteVendorProduct(PDO $db, ?array $input): void
{
    if (!verifyCSRFToken($input['csrf_token'] ?? '')) {
        throw new Exception('Invalid security token');
    }

    $id = (int)($input['id'] ?? 0);
    if (!$id) throw new Exception('Product ID required');

    // Soft-delete: preserve for history
    $stmt = $db->prepare("UPDATE vendor_products SET is_active = 0 WHERE id = ?");
    $stmt->execute([$id]);

    echo json_encode(['success' => true, 'message' => 'Product removed']);
}


function handleCategories(): void
{
    $accounting = [
        'Materials', 'Fuel', 'Tools/Equipment', 'Repairs/Maintenance',
        'Disposal/Dump', 'Subcontractors', 'Marketing', 'Office/Admin',
        'Licenses/Permits', 'Meals', 'Vehicle', 'Other',
    ];

    $gbp = [
        'Garden center/nursery', 'Hardware store', 'Building materials',
        'Equipment rental', 'Gas station', 'Waste disposal/landfill',
        'Restaurant/food', 'Office supply', 'Auto parts',
        'Wholesale store', 'Other',
    ];

    $paymentMethods = [
        'cash', 'credit_card', 'debit', 'company_card', 'etransfer', 'cheque',
    ];

    echo json_encode([
        'success' => true,
        'accounting_categories' => $accounting,
        'gbp_categories' => $gbp,
        'payment_methods' => $paymentMethods,
    ]);
}

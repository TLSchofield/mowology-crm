<?php
/**
 * Vendors API — CRUD for vendor directory
 *
 * GET:  ?action=list
 * GET:  ?action=get&id=X
 * GET:  ?action=search&q=term  — autocomplete search
 * POST: {action: 'create', ...fields}
 * POST: {action: 'update', id, ...fields}
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
               COUNT(vl.id) AS location_count,
               COUNT(e.id) AS expense_count,
               COALESCE(SUM(e.total), 0) AS total_spent
        FROM vendors v
        LEFT JOIN vendor_locations vl ON vl.vendor_id = v.id
        LEFT JOIN expenses e ON e.vendor_id = v.id
        {$where}
        GROUP BY v.id
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

    // Recent expenses
    $expStmt = $db->prepare("
        SELECT id, expense_date, total, accounting_category, description
        FROM expenses WHERE vendor_id = ?
        ORDER BY expense_date DESC LIMIT 10
    ");
    $expStmt->execute([$id]);
    $vendor['recent_expenses'] = $expStmt->fetchAll(PDO::FETCH_ASSOC);

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
        INSERT INTO vendors (name, aliases, default_accounting_category, default_gbp_category, phone, website, notes)
        VALUES (?, ?, ?, ?, ?, ?, ?)
    ");
    $stmt->execute([
        $name,
        $input['aliases'] ?? null,
        $input['default_accounting_category'] ?? null,
        $input['default_gbp_category'] ?? null,
        $input['phone'] ?? null,
        $input['website'] ?? null,
        $input['notes'] ?? null,
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
            notes = ?, is_active = ?
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

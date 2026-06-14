<?php
/**
 * Purchase Tasks API
 * /crm/api/purchase-tasks.php
 *
 * GET  ?action=get&task_id=N                    — fetch single task with items
 * GET  ?action=vendor_search&q=term             — (legacy) autocomplete from past task history
 * GET  ?action=vendor_list                      — all active vendors for dropdown
 * GET  ?action=vendor_locations&vendor_id=N     — locations for a specific vendor
 * POST action=create                             — create (admin/manager)
 * POST action=update                             — update (admin/manager)
 * POST action=create_vendor_quick                — create vendor + optional location (admin/manager)
 * POST action=update_status                      — transition purchase_status
 * POST action=toggle_item                        — toggle is_purchased on an item
 */
declare(strict_types=1);

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
requireLogin();
session_write_close();

header('Content-Type: application/json');

$db     = getDB();
$user   = getCurrentUser();
$userId = (int)$user['id'];
$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';

function ptApiError(string $msg, int $code = 400): void {
    http_response_code($code);
    echo json_encode(['success' => false, 'error' => $msg]);
    exit;
}

function ptApiOk(array $data = []): void {
    echo json_encode(array_merge(['success' => true], $data));
    exit;
}

// ── GET ────────────────────────────────────────────────────────────────────────
if ($method === 'GET') {
    $action = $_GET['action'] ?? 'get';

    // Vendor autocomplete — (legacy) searches past task history
    if ($action === 'vendor_search') {
        $q = trim($_GET['q'] ?? '');
        if (strlen($q) < 1) ptApiOk(['vendors' => []]);

        $like = '%' . $q . '%';
        $stmt = $db->prepare("
            SELECT vendor_name,
                   location_label,
                   location_address,
                   COUNT(*) AS use_count
            FROM purchase_tasks
            WHERE vendor_name IS NOT NULL
              AND vendor_name LIKE ?
            GROUP BY vendor_name, location_label, location_address
            ORDER BY use_count DESC, vendor_name ASC
            LIMIT 8
        ");
        $stmt->execute([$like]);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

        $vendors = [];
        foreach ($rows as $r) {
            $vendors[] = [
                'vendor_name'      => $r['vendor_name'],
                'location_label'   => $r['location_label'] ?: '',
                'location_address' => $r['location_address'] ?: '',
            ];
        }
        ptApiOk(['vendors' => $vendors]);
    }

    // All active vendors — for the dropdown
    if ($action === 'vendor_list') {
        $stmt = $db->query("
            SELECT id, name,
                   (SELECT COUNT(*) FROM vendor_locations WHERE vendor_id = v.id) AS location_count
            FROM vendors v
            WHERE is_active = 1
            ORDER BY name
        ");
        ptApiOk(['vendors' => $stmt->fetchAll(PDO::FETCH_ASSOC)]);
    }

    // Locations for a single vendor — populates the location dropdown
    if ($action === 'vendor_locations') {
        $vendorId = (int)($_GET['vendor_id'] ?? 0);
        if ($vendorId <= 0) ptApiError('Invalid vendor_id');

        $stmt = $db->prepare("
            SELECT id, label, address, city, lat, lng, is_preferred
            FROM vendor_locations
            WHERE vendor_id = ?
            ORDER BY is_preferred DESC, label ASC
        ");
        $stmt->execute([$vendorId]);
        ptApiOk(['locations' => $stmt->fetchAll(PDO::FETCH_ASSOC)]);
    }

    // Single task fetch
    $taskId = (int)($_GET['task_id'] ?? 0);
    if ($taskId <= 0) ptApiError('Invalid task_id');

    $stmt = $db->prepare("
        SELECT pt.*,
               u.full_name   AS assigned_to_name,
               CONCAT(c.first_name, ' ', c.last_name) AS contact_name,
               CONCAT(p.address, ', ', p.city) AS property_display
        FROM purchase_tasks pt
        LEFT JOIN users      u  ON pt.assigned_to_id = u.id
        LEFT JOIN contacts   c  ON pt.contact_id     = c.id
        LEFT JOIN properties p  ON pt.property_id    = p.id
        WHERE pt.id = ?
    ");
    $stmt->execute([$taskId]);
    $task = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$task) ptApiError('Task not found', 404);

    $itemStmt = $db->prepare("
        SELECT id, description, quantity, unit, unit_price, is_purchased, sort_order
        FROM purchase_task_items WHERE task_id = ? ORDER BY sort_order, id
    ");
    $itemStmt->execute([$taskId]);
    $task['items'] = $itemStmt->fetchAll(PDO::FETCH_ASSOC);

    // Load contact's properties for the linked client dropdown
    if (!empty($task['contact_id'])) {
        $propStmt = $db->prepare("
            SELECT id, address, city, province FROM properties
            WHERE site_contact_id = ? AND status = 'active' ORDER BY address
        ");
        $propStmt->execute([(int)$task['contact_id']]);
        $task['contact_properties'] = $propStmt->fetchAll(PDO::FETCH_ASSOC);
    } else {
        $task['contact_properties'] = [];
    }

    ptApiOk(['task' => $task]);
}

// ── POST ───────────────────────────────────────────────────────────────────────
if ($method !== 'POST') ptApiError('Method not allowed', 405);

$raw   = file_get_contents('php://input');
$body  = ($raw ? json_decode($raw, true) : null) ?? $_POST;
$token = $body['csrf_token'] ?? $_SERVER['HTTP_X_CSRF_TOKEN'] ?? '';

if (!verifyCSRFToken($token)) ptApiError('Invalid CSRF token', 403);

$action = $body['action'] ?? '';

// ── update_status ──────────────────────────────────────────────────────────────
if ($action === 'update_status') {
    $taskId    = (int)($body['task_id'] ?? 0);
    $newStatus = $body['status'] ?? '';

    if ($taskId <= 0) ptApiError('Invalid task_id');
    if (!in_array($newStatus, ['pending','in_transit','purchased','cancelled'], true)) ptApiError('Invalid status');

    $stmt = $db->prepare("SELECT id, purchase_status, assigned_to_id FROM purchase_tasks WHERE id = ?");
    $stmt->execute([$taskId]);
    $task = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$task) ptApiError('Task not found', 404);

    if ($user['role'] !== 'admin' && $user['role'] !== 'manager') {
        if ((int)($task['assigned_to_id'] ?? 0) !== $userId) ptApiError('Not authorized', 403);
    }

    $db->prepare("UPDATE purchase_tasks SET purchase_status = ?, updated_at = NOW() WHERE id = ?")
       ->execute([$newStatus, $taskId]);

    ptApiOk(['task_id' => $taskId, 'new_status' => $newStatus]);
}

// ── toggle_item ────────────────────────────────────────────────────────────────
if ($action === 'toggle_item') {
    $itemId = (int)($body['item_id'] ?? 0);
    if ($itemId <= 0) ptApiError('Invalid item_id');

    $stmt = $db->prepare("
        SELECT pti.id, pti.is_purchased, pti.task_id, pt.assigned_to_id, pt.purchase_status
        FROM purchase_task_items pti
        JOIN purchase_tasks pt ON pti.task_id = pt.id
        WHERE pti.id = ?
    ");
    $stmt->execute([$itemId]);
    $item = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$item) ptApiError('Item not found', 404);

    if ($user['role'] !== 'admin' && $user['role'] !== 'manager') {
        if ((int)($item['assigned_to_id'] ?? 0) !== $userId) ptApiError('Not authorized', 403);
    }

    $newVal = (int)$item['is_purchased'] === 1 ? 0 : 1;
    $db->prepare("UPDATE purchase_task_items SET is_purchased = ? WHERE id = ?")
       ->execute([$newVal, $itemId]);

    // Auto-complete task when all items checked
    $countStmt = $db->prepare("SELECT COUNT(*) FROM purchase_task_items WHERE task_id = ? AND is_purchased = 0");
    $countStmt->execute([$item['task_id']]);
    $remaining  = (int)$countStmt->fetchColumn();
    $taskStatus = $item['purchase_status'];

    if ($remaining === 0 && $taskStatus !== 'purchased') {
        $db->prepare("UPDATE purchase_tasks SET purchase_status = 'purchased', updated_at = NOW() WHERE id = ?")
           ->execute([$item['task_id']]);
        $taskStatus = 'purchased';
    }

    ptApiOk(['item_id' => $itemId, 'is_purchased' => $newVal, 'task_status' => $taskStatus]);
}

// ── Shared field parser for create + update ────────────────────────────────────
function ptParseFields(array $body, PDO $db): array {
    $vendorId  = !empty($body['vendor_id'])          ? (int)$body['vendor_id']          : null;
    $locationId = !empty($body['vendor_location_id']) ? (int)$body['vendor_location_id'] : null;

    // Populate free-text display columns from the FK'd vendor/location so the
    // table view and any other readers don't need to know about the FK columns.
    $vendorName    = trim($body['vendor_name']      ?? '') ?: null;
    $locationLabel = trim($body['location_label']   ?? '') ?: null;
    $locationAddr  = trim($body['location_address'] ?? '') ?: null;

    if ($vendorId) {
        $row = $db->prepare("SELECT name FROM vendors WHERE id = ?");
        $row->execute([$vendorId]);
        $v = $row->fetch(PDO::FETCH_ASSOC);
        if ($v) $vendorName = $v['name'];
    }
    if ($locationId) {
        $row = $db->prepare("SELECT label, address FROM vendor_locations WHERE id = ?");
        $row->execute([$locationId]);
        $loc = $row->fetch(PDO::FETCH_ASSOC);
        if ($loc) {
            $locationLabel = $loc['label'] ?: $locationLabel;
            $locationAddr  = $loc['address'] ?: $locationAddr;
        }
    }

    return [
        'task_date'  => $body['task_date'],
        'title'      => trim($body['title']),
        'vendor'     => $vendorName,
        'location'   => $locationAddr,
        'locationL'  => $locationLabel,
        'vendorId'   => $vendorId,
        'locationId' => $locationId,
        'assignTo'   => !empty($body['assigned_to_id']) ? (int)$body['assigned_to_id'] : null,
        'mode'       => $body['procurement_mode'] ?? 'vendor_run',
        'priority'   => $body['priority']         ?? 'normal',
        'total'      => isset($body['estimated_total']) && $body['estimated_total'] !== ''
                            ? (float)$body['estimated_total'] : null,
        'notes'      => trim($body['notes']       ?? '') ?: null,
        'contactId'  => !empty($body['contact_id'])  ? (int)$body['contact_id']  : null,
        'propertyId' => !empty($body['property_id']) ? (int)$body['property_id'] : null,
    ];
}

function ptSaveItems(PDO $db, int $taskId, array $items): void {
    $itemIns = $db->prepare("
        INSERT INTO purchase_task_items (task_id, description, quantity, unit, unit_price, sort_order)
        VALUES (?, ?, ?, ?, ?, ?)
    ");
    foreach ($items as $idx => $item) {
        if (empty($item['description'])) continue;
        $itemIns->execute([
            $taskId,
            trim($item['description']),
            (float)($item['quantity'] ?? 1),
            $item['unit'] ?? null,
            isset($item['unit_price']) && $item['unit_price'] !== '' ? (float)$item['unit_price'] : null,
            (int)$idx,
        ]);
    }
}

// ── create ─────────────────────────────────────────────────────────────────────
if ($action === 'create') {
    if ($user['role'] !== 'admin' && $user['role'] !== 'manager') ptApiError('Admin or manager required', 403);
    if (empty($body['task_date'])) ptApiError('Missing task_date');
    if (empty($body['title']))     ptApiError('Missing title');

    $f = ptParseFields($body, $db);

    $year = date('Y', strtotime($f['task_date']));
    $countStmt = $db->prepare("SELECT COUNT(*) FROM purchase_tasks WHERE YEAR(task_date) = ?");
    $countStmt->execute([$year]);
    $seq        = (int)$countStmt->fetchColumn() + 1;
    $taskNumber = 'PT-' . $year . '-' . str_pad((string)$seq, 4, '0', STR_PAD_LEFT);

    $ins = $db->prepare("
        INSERT INTO purchase_tasks
            (task_number, title, task_date, vendor_name, location_address, location_label,
             vendor_id, vendor_location_id,
             procurement_mode, priority, estimated_total, assigned_to_id, notes,
             contact_id, property_id, created_by_id)
        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
    ");
    $ins->execute([
        $taskNumber, $f['title'], $f['task_date'], $f['vendor'], $f['location'], $f['locationL'],
        $f['vendorId'], $f['locationId'],
        $f['mode'], $f['priority'], $f['total'], $f['assignTo'], $f['notes'],
        $f['contactId'], $f['propertyId'], $userId,
    ]);
    $newId = (int)$db->lastInsertId();

    $items = $body['items'] ?? [];
    if (is_array($items)) {
        ptSaveItems($db, $newId, $items);
    }

    ptApiOk(['task_id' => $newId, 'task_number' => $taskNumber, 'contact_id' => $f['contactId']]);
}

// ── update ─────────────────────────────────────────────────────────────────────
if ($action === 'update') {
    if ($user['role'] !== 'admin' && $user['role'] !== 'manager') ptApiError('Admin or manager required', 403);

    $taskId = (int)($body['task_id'] ?? 0);
    if ($taskId <= 0) ptApiError('Missing task_id');
    if (empty($body['task_date'])) ptApiError('Missing task_date');
    if (empty($body['title']))     ptApiError('Missing title');

    $f = ptParseFields($body, $db);

    $db->prepare("
        UPDATE purchase_tasks SET
            title = ?, task_date = ?, vendor_name = ?, location_address = ?, location_label = ?,
            vendor_id = ?, vendor_location_id = ?,
            procurement_mode = ?, priority = ?, estimated_total = ?, assigned_to_id = ?, notes = ?,
            contact_id = ?, property_id = ?, updated_at = NOW()
        WHERE id = ?
    ")->execute([
        $f['title'], $f['task_date'], $f['vendor'], $f['location'], $f['locationL'],
        $f['vendorId'], $f['locationId'],
        $f['mode'], $f['priority'], $f['total'], $f['assignTo'], $f['notes'],
        $f['contactId'], $f['propertyId'], $taskId,
    ]);

    $db->prepare("DELETE FROM purchase_task_items WHERE task_id = ?")->execute([$taskId]);
    $items = $body['items'] ?? [];
    if (is_array($items)) {
        ptSaveItems($db, $taskId, $items);
    }

    ptApiOk(['task_id' => $taskId, 'contact_id' => $f['contactId']]);
}

// ── create_vendor_quick ────────────────────────────────────────────────────────
// Minimal vendor + optional first location creation from within the purchase modal.
// Only requires schedule permissions — no need for expenses.edit.
if ($action === 'create_vendor_quick') {
    if ($user['role'] !== 'admin' && $user['role'] !== 'manager') ptApiError('Admin or manager required', 403);

    $name = trim($body['name'] ?? '');
    if (empty($name)) ptApiError('Vendor name is required');

    // Prevent exact-duplicate names
    $check = $db->prepare("SELECT id FROM vendors WHERE name = ? AND is_active = 1 LIMIT 1");
    $check->execute([$name]);
    $existing = $check->fetch(PDO::FETCH_ASSOC);
    if ($existing) {
        // Return the existing vendor so the UI can select it
        $locs = $db->prepare("
            SELECT id, label, address, city, is_preferred
            FROM vendor_locations WHERE vendor_id = ?
            ORDER BY is_preferred DESC, label
        ");
        $locs->execute([(int)$existing['id']]);
        ptApiOk([
            'vendor_id' => (int)$existing['id'],
            'name'      => $name,
            'duplicate' => true,
            'locations' => $locs->fetchAll(PDO::FETCH_ASSOC),
        ]);
    }

    $db->prepare("INSERT INTO vendors (name, is_active) VALUES (?, 1)")->execute([$name]);
    $vendorId = (int)$db->lastInsertId();

    $locationId = null;
    $locations  = [];

    $locLabel   = trim($body['location_label']   ?? '');
    $locAddress = trim($body['location_address'] ?? '');

    if ($locAddress || $locLabel) {
        $db->prepare("
            INSERT INTO vendor_locations (vendor_id, label, address, is_preferred)
            VALUES (?, ?, ?, 1)
        ")->execute([$vendorId, $locLabel ?: null, $locAddress ?: null]);
        $locationId  = (int)$db->lastInsertId();
        $locations[] = [
            'id'           => $locationId,
            'label'        => $locLabel,
            'address'      => $locAddress,
            'is_preferred' => 1,
        ];
    }

    ptApiOk([
        'vendor_id'   => $vendorId,
        'location_id' => $locationId,
        'name'        => $name,
        'locations'   => $locations,
    ]);
}

ptApiError('Unknown action');

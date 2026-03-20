<?php
/**
 * Tasks CRUD API (with Purchase Task support)
 *
 * GET                           — list tasks (filters: status, assigned_to, priority, task_type, purchase_status, etc.)
 * POST ?action=create           — create task (general or purchase)
 * POST ?action=update           — update task
 * POST ?action=complete         — mark task completed
 * POST ?action=reopen           — reopen a completed task
 * POST ?action=delete           — delete task
 *
 * Purchase workflow actions:
 * POST ?action=start_run        — en_route, record departure GPS, start time
 * POST ?action=arrive_vendor    — at_vendor, record arrival GPS, calc drive_time
 * POST ?action=complete_purchase — purchased, record purchase time
 * POST ?action=deliver          — delivered, record delivery GPS, calc total_time
 * POST ?action=verify           — verified (admin/manager only)
 * POST ?action=link_expense     — link an expense to a purchase task
 * POST ?action=save_items       — bulk upsert task_items
 * GET  ?action=get_items        — get task_items for a task
 */
declare(strict_types=1);
header('Content-Type: application/json');

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
require_once PUBLIC_ROOT . '/crm/includes/functions.php';
requireLogin();
$user = getCurrentUser();
session_write_close();

$db     = getDB();
$action = $_GET['action'] ?? $_POST['action'] ?? '';

// ── Helper: Generate purchase task number ────────────────────────────────────
function generatePurchaseNumber(PDO $db): string
{
    $year = date('Y');
    $prefix = "PUR-{$year}-";
    $stmt = $db->prepare("SELECT task_number FROM tasks WHERE task_number LIKE ? ORDER BY task_number DESC LIMIT 1");
    $stmt->execute([$prefix . '%']);
    $last = $stmt->fetchColumn();
    if ($last) {
        $seq = (int)substr($last, strlen($prefix)) + 1;
    } else {
        $seq = 1;
    }
    return $prefix . str_pad((string)$seq, 4, '0', STR_PAD_LEFT);
}

// ── Helper: Require task exists and return it ────────────────────────────────
function requireTask(PDO $db, int $taskId): array
{
    $stmt = $db->prepare("SELECT * FROM tasks WHERE id = ?");
    $stmt->execute([$taskId]);
    $task = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$task) {
        http_response_code(404);
        echo json_encode(['error' => 'Task not found']);
        exit;
    }
    return $task;
}

// ── Helper: Require task_id from input ───────────────────────────────────────
function requireTaskId(array $input): int
{
    $id = (int)($input['task_id'] ?? 0);
    if (!$id) {
        http_response_code(400);
        echo json_encode(['error' => 'task_id required']);
        exit;
    }
    return $id;
}

// ── GET: List tasks ──────────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'GET' && !$action) {
    $where  = [];
    $params = [];

    // Task type filter
    if (!empty($_GET['task_type'])) {
        $where[]  = 't.task_type = ?';
        $params[] = $_GET['task_type'];
    }

    if (!empty($_GET['status'])) {
        if ($_GET['status'] === 'overdue') {
            $where[] = "t.status != 'completed' AND t.due_date < CURDATE()";
        } else {
            $where[]  = 't.status = ?';
            $params[] = $_GET['status'];
        }
    }
    if (!empty($_GET['assigned_to'])) {
        if ($_GET['assigned_to'] === 'me') {
            $where[]  = 't.assigned_to = ?';
            $params[] = $user['id'];
        } else {
            $where[]  = 't.assigned_to = ?';
            $params[] = (int)$_GET['assigned_to'];
        }
    }
    if (!empty($_GET['priority'])) {
        $where[]  = 't.priority = ?';
        $params[] = $_GET['priority'];
    }
    if (!empty($_GET['contact_id'])) {
        $where[]  = 't.contact_id = ?';
        $params[] = (int)$_GET['contact_id'];
    }
    if (!empty($_GET['quote_id'])) {
        $where[]  = 't.quote_id = ?';
        $params[] = (int)$_GET['quote_id'];
    }
    if (!empty($_GET['plan_id'])) {
        $where[]  = 't.plan_id = ?';
        $params[] = (int)$_GET['plan_id'];
    }
    if (!empty($_GET['invoice_id'])) {
        $where[]  = 't.invoice_id = ?';
        $params[] = (int)$_GET['invoice_id'];
    }

    // Purchase-specific filters
    if (!empty($_GET['vendor_id'])) {
        $where[]  = 't.vendor_id = ?';
        $params[] = (int)$_GET['vendor_id'];
    }
    if (!empty($_GET['procurement_mode'])) {
        $where[]  = 't.procurement_mode = ?';
        $params[] = $_GET['procurement_mode'];
    }
    if (!empty($_GET['purchase_status'])) {
        $where[]  = 't.purchase_status = ?';
        $params[] = $_GET['purchase_status'];
    }
    // Purchase queue: all non-verified purchase tasks
    if (!empty($_GET['purchase_queue'])) {
        $where[] = "t.task_type = 'purchase' AND (t.purchase_status IS NULL OR t.purchase_status NOT IN ('verified'))";
    }
    // Verified purchases
    if (!empty($_GET['purchase_verified'])) {
        $where[] = "t.task_type = 'purchase' AND t.purchase_status = 'verified'";
    }

    $whereClause = $where ? 'WHERE ' . implode(' AND ', $where) : '';
    $limit  = min((int)($_GET['limit'] ?? 50), 200);
    $offset = max((int)($_GET['offset'] ?? 0), 0);

    $stmt = $db->prepare("
        SELECT t.*,
               u_assigned.full_name AS assigned_to_name,
               u_created.full_name AS created_by_name,
               c.first_name AS contact_first, c.last_name AS contact_last,
               q.quote_number,
               jp.plan_number,
               v.name AS vendor_name,
               vl.label AS vendor_location_label,
               vl.address AS vendor_location_address,
               (SELECT COUNT(*) FROM task_items ti WHERE ti.task_id = t.id) AS items_count
        FROM tasks t
        LEFT JOIN users u_assigned ON t.assigned_to = u_assigned.id
        LEFT JOIN users u_created ON t.created_by = u_created.id
        LEFT JOIN contacts c ON t.contact_id = c.id
        LEFT JOIN quotes q ON t.quote_id = q.id
        LEFT JOIN job_plans jp ON t.plan_id = jp.id
        LEFT JOIN vendors v ON t.vendor_id = v.id
        LEFT JOIN vendor_locations vl ON t.vendor_location_id = vl.id
        {$whereClause}
        ORDER BY
            CASE t.status WHEN 'in_progress' THEN 0 WHEN 'pending' THEN 1 ELSE 2 END,
            CASE t.priority WHEN 'high' THEN 0 WHEN 'normal' THEN 1 ELSE 2 END,
            COALESCE(t.due_date, '2099-12-31') ASC,
            t.created_at DESC
        LIMIT ? OFFSET ?
    ");
    $params[] = $limit;
    $params[] = $offset;
    $stmt->execute($params);

    echo json_encode(['success' => true, 'data' => $stmt->fetchAll(PDO::FETCH_ASSOC)]);
    exit;
}

// ── GET: get_items ───────────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'GET' && $action === 'get_items') {
    $taskId = (int)($_GET['task_id'] ?? 0);
    if (!$taskId) {
        http_response_code(400);
        echo json_encode(['error' => 'task_id required']);
        exit;
    }

    $stmt = $db->prepare("
        SELECT ti.*,
               vp.name AS vendor_product_name, vp.category AS vendor_product_category,
               vp.price_per_unit AS catalog_price, vp.unit AS catalog_unit
        FROM task_items ti
        LEFT JOIN vendor_products vp ON ti.vendor_product_id = vp.id
        WHERE ti.task_id = ?
        ORDER BY ti.sort_order, ti.id
    ");
    $stmt->execute([$taskId]);

    echo json_encode(['success' => true, 'data' => $stmt->fetchAll(PDO::FETCH_ASSOC)]);
    exit;
}

// ── POST actions ─────────────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['error' => 'Method not allowed']);
    exit;
}

$input = json_decode(file_get_contents('php://input'), true) ?? $_POST;
if (!verifyCSRFToken($input['csrf_token'] ?? '')) {
    http_response_code(403);
    echo json_encode(['error' => 'Invalid CSRF token']);
    exit;
}

try {
    switch ($action) {
        case 'create':
            $title = trim($input['title'] ?? '');
            if (!$title) {
                http_response_code(400);
                echo json_encode(['error' => 'Title is required']);
                exit;
            }

            $taskType = in_array($input['task_type'] ?? '', ['general', 'purchase']) ? $input['task_type'] : 'general';
            $taskNumber = null;
            $purchaseStatus = null;

            // Purchase-specific validation & defaults
            if ($taskType === 'purchase') {
                if (empty($input['vendor_id'])) {
                    http_response_code(400);
                    echo json_encode(['error' => 'Vendor is required for purchase tasks']);
                    exit;
                }
                $taskNumber = generatePurchaseNumber($db);
                $purchaseStatus = 'requested';
            }

            $procurementMode = null;
            if (!empty($input['procurement_mode']) && in_array($input['procurement_mode'], ['on_route', 'asap', 'truck_kit'])) {
                $procurementMode = $input['procurement_mode'];
            }

            $stmt = $db->prepare("
                INSERT INTO tasks (title, description, due_date, due_time, priority, assigned_to,
                                   contact_id, company_id, property_id, quote_id, plan_id, invoice_id,
                                   is_recurring, recurrence_pattern, recurrence_end_date, created_by,
                                   task_type, task_number, vendor_id, vendor_location_id,
                                   procurement_mode, requested_date, scheduled_date, purchase_status,
                                   estimated_total)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
            ");
            $stmt->execute([
                $title,
                trim($input['description'] ?? '') ?: null,
                !empty($input['due_date']) ? $input['due_date'] : null,
                !empty($input['due_time']) ? $input['due_time'] : null,
                in_array($input['priority'] ?? '', ['high', 'normal', 'low']) ? $input['priority'] : 'normal',
                !empty($input['assigned_to']) ? (int)$input['assigned_to'] : $user['id'],
                !empty($input['contact_id']) ? (int)$input['contact_id'] : null,
                !empty($input['company_id']) ? (int)$input['company_id'] : null,
                !empty($input['property_id']) ? (int)$input['property_id'] : null,
                !empty($input['quote_id']) ? (int)$input['quote_id'] : null,
                !empty($input['plan_id']) ? (int)$input['plan_id'] : null,
                !empty($input['invoice_id']) ? (int)$input['invoice_id'] : null,
                !empty($input['is_recurring']) ? 1 : 0,
                !empty($input['recurrence_pattern']) ? $input['recurrence_pattern'] : null,
                !empty($input['recurrence_end_date']) ? $input['recurrence_end_date'] : null,
                $user['id'],
                $taskType,
                $taskNumber,
                !empty($input['vendor_id']) ? (int)$input['vendor_id'] : null,
                !empty($input['vendor_location_id']) ? (int)$input['vendor_location_id'] : null,
                $procurementMode,
                !empty($input['requested_date']) ? $input['requested_date'] : null,
                !empty($input['scheduled_date']) ? $input['scheduled_date'] : null,
                $purchaseStatus,
                !empty($input['estimated_total']) ? (float)$input['estimated_total'] : null,
            ]);
            $taskId = (int)$db->lastInsertId();

            // Save items if provided (purchase tasks)
            if ($taskType === 'purchase' && !empty($input['items']) && is_array($input['items'])) {
                $itemStmt = $db->prepare("
                    INSERT INTO task_items (task_id, vendor_product_id, product_id, name, description,
                                            quantity, unit, estimated_unit_price, sort_order)
                    VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)
                ");
                foreach ($input['items'] as $i => $item) {
                    $itemStmt->execute([
                        $taskId,
                        !empty($item['vendor_product_id']) ? (int)$item['vendor_product_id'] : null,
                        !empty($item['product_id']) ? (int)$item['product_id'] : null,
                        trim($item['name'] ?? 'Item'),
                        trim($item['description'] ?? '') ?: null,
                        (float)($item['quantity'] ?? 1),
                        trim($item['unit'] ?? '') ?: null,
                        !empty($item['estimated_unit_price']) ? (float)$item['estimated_unit_price'] : null,
                        $i,
                    ]);
                }
            }

            $logLabel = $taskType === 'purchase' ? "Purchase task: {$taskNumber} — {$title}" : "Task: {$title}";
            logActivityExtended($user['id'], 'Task created', $logLabel, null, null,
                !empty($input['quote_id']) ? (int)$input['quote_id'] : null,
                !empty($input['invoice_id']) ? (int)$input['invoice_id'] : null,
                !empty($input['plan_id']) ? (int)$input['plan_id'] : null
            );

            echo json_encode(['success' => true, 'task_id' => $taskId, 'task_number' => $taskNumber]);
            break;

        case 'update':
            $taskId = requireTaskId($input);
            $oldTask = requireTask($db, $taskId);

            $title    = trim($input['title'] ?? $oldTask['title']);
            $desc     = array_key_exists('description', $input) ? trim($input['description']) : $oldTask['description'];
            $dueDate  = array_key_exists('due_date', $input) ? ($input['due_date'] ?: null) : $oldTask['due_date'];
            $dueTime  = array_key_exists('due_time', $input) ? ($input['due_time'] ?: null) : $oldTask['due_time'];
            $priority = in_array($input['priority'] ?? '', ['high', 'normal', 'low']) ? $input['priority'] : $oldTask['priority'];
            $status   = in_array($input['status'] ?? '', ['pending', 'in_progress', 'completed']) ? $input['status'] : $oldTask['status'];
            $assigned = array_key_exists('assigned_to', $input) ? ((int)$input['assigned_to'] ?: null) : $oldTask['assigned_to'];

            // Base fields
            $sql = "UPDATE tasks SET title = ?, description = ?, due_date = ?, due_time = ?,
                                     priority = ?, status = ?, assigned_to = ?";
            $params = [$title, $desc, $dueDate, $dueTime, $priority, $status, $assigned];

            // Purchase-specific fields
            if ($oldTask['task_type'] === 'purchase') {
                $sql .= ", vendor_id = ?, vendor_location_id = ?, procurement_mode = ?,
                           requested_date = ?, scheduled_date = ?, estimated_total = ?";
                $params[] = array_key_exists('vendor_id', $input) ? ((int)$input['vendor_id'] ?: null) : $oldTask['vendor_id'];
                $params[] = array_key_exists('vendor_location_id', $input) ? ((int)$input['vendor_location_id'] ?: null) : $oldTask['vendor_location_id'];
                $procMode = $input['procurement_mode'] ?? $oldTask['procurement_mode'];
                $params[] = in_array($procMode, ['on_route', 'asap', 'truck_kit']) ? $procMode : $oldTask['procurement_mode'];
                $params[] = array_key_exists('requested_date', $input) ? ($input['requested_date'] ?: null) : $oldTask['requested_date'];
                $params[] = array_key_exists('scheduled_date', $input) ? ($input['scheduled_date'] ?: null) : $oldTask['scheduled_date'];
                $params[] = array_key_exists('estimated_total', $input) ? ((float)$input['estimated_total'] ?: null) : $oldTask['estimated_total'];

                // If assigning for first time, update purchase_status
                if ($assigned && !$oldTask['assigned_to'] && $oldTask['purchase_status'] === 'requested') {
                    $sql .= ", purchase_status = 'assigned'";
                }
            }

            $sql .= " WHERE id = ?";
            $params[] = $taskId;

            $db->prepare($sql)->execute($params);

            // Track changes
            trackFieldChanges('task', $taskId, $oldTask, [
                'title' => $title, 'description' => $desc, 'due_date' => $dueDate,
                'priority' => $priority, 'status' => $status, 'assigned_to' => (string)$assigned,
            ], $user['id']);

            // If status changed to completed, set completed_at/by
            if ($status === 'completed' && $oldTask['status'] !== 'completed') {
                $db->prepare("UPDATE tasks SET completed_at = NOW(), completed_by = ? WHERE id = ?")->execute([$user['id'], $taskId]);
            }

            echo json_encode(['success' => true]);
            break;

        case 'complete':
            $taskId = requireTaskId($input);
            $old = $db->prepare("SELECT status FROM tasks WHERE id = ?");
            $old->execute([$taskId]);
            $oldStatus = $old->fetchColumn();

            $db->prepare("UPDATE tasks SET status = 'completed', completed_at = NOW(), completed_by = ? WHERE id = ?")->execute([$user['id'], $taskId]);
            trackFieldChange('task', $taskId, 'status', $oldStatus, 'completed', $user['id']);

            echo json_encode(['success' => true]);
            break;

        case 'reopen':
            $taskId = requireTaskId($input);
            $db->prepare("UPDATE tasks SET status = 'pending', completed_at = NULL, completed_by = NULL WHERE id = ?")->execute([$taskId]);
            trackFieldChange('task', $taskId, 'status', 'completed', 'pending', $user['id']);

            echo json_encode(['success' => true]);
            break;

        case 'delete':
            $taskId = requireTaskId($input);
            $db->prepare("DELETE FROM tasks WHERE id = ?")->execute([$taskId]);
            echo json_encode(['success' => true]);
            break;

        // ── Purchase Workflow Actions ────────────────────────────────────────

        case 'start_run':
            $taskId = requireTaskId($input);
            $task = requireTask($db, $taskId);
            if ($task['task_type'] !== 'purchase') {
                http_response_code(400);
                echo json_encode(['error' => 'Not a purchase task']);
                exit;
            }

            $db->prepare("
                UPDATE tasks SET purchase_status = 'en_route', started_at = NOW(),
                                 departure_lat = ?, departure_lng = ?,
                                 status = 'in_progress'
                WHERE id = ?
            ")->execute([
                !empty($input['lat']) ? (float)$input['lat'] : null,
                !empty($input['lng']) ? (float)$input['lng'] : null,
                $taskId,
            ]);

            logActivityExtended($user['id'], 'Purchase run started', "Task: {$task['task_number']}", null, null,
                null, null, $task['plan_id'] ? (int)$task['plan_id'] : null);

            echo json_encode(['success' => true, 'purchase_status' => 'en_route']);
            break;

        case 'arrive_vendor':
            $taskId = requireTaskId($input);
            $task = requireTask($db, $taskId);
            if ($task['task_type'] !== 'purchase') {
                http_response_code(400);
                echo json_encode(['error' => 'Not a purchase task']);
                exit;
            }

            // Calculate drive time from started_at to now
            $driveMinutes = null;
            if ($task['started_at']) {
                $start = new DateTime($task['started_at']);
                $now = new DateTime();
                $driveMinutes = (int)round(($now->getTimestamp() - $start->getTimestamp()) / 60);
            }

            $db->prepare("
                UPDATE tasks SET purchase_status = 'at_vendor', arrived_at = NOW(),
                                 arrival_lat = ?, arrival_lng = ?,
                                 drive_time_minutes = ?
                WHERE id = ?
            ")->execute([
                !empty($input['lat']) ? (float)$input['lat'] : null,
                !empty($input['lng']) ? (float)$input['lng'] : null,
                $driveMinutes,
                $taskId,
            ]);

            echo json_encode(['success' => true, 'purchase_status' => 'at_vendor', 'drive_time_minutes' => $driveMinutes]);
            break;

        case 'complete_purchase':
            $taskId = requireTaskId($input);
            $task = requireTask($db, $taskId);
            if ($task['task_type'] !== 'purchase') {
                http_response_code(400);
                echo json_encode(['error' => 'Not a purchase task']);
                exit;
            }

            // Calculate shop time from arrived_at to now
            $shopMinutes = null;
            if ($task['arrived_at']) {
                $arrived = new DateTime($task['arrived_at']);
                $now = new DateTime();
                $shopMinutes = (int)round(($now->getTimestamp() - $arrived->getTimestamp()) / 60);
            }

            $actualTotal = !empty($input['actual_total']) ? (float)$input['actual_total'] : null;

            $db->prepare("
                UPDATE tasks SET purchase_status = 'purchased', purchased_at = NOW(),
                                 shop_time_minutes = ?, actual_total = ?
                WHERE id = ?
            ")->execute([$shopMinutes, $actualTotal, $taskId]);

            // Mark all items as purchased if not individually tracked
            $db->prepare("UPDATE task_items SET is_purchased = 1 WHERE task_id = ? AND is_purchased = 0")->execute([$taskId]);

            echo json_encode(['success' => true, 'purchase_status' => 'purchased', 'shop_time_minutes' => $shopMinutes]);
            break;

        case 'deliver':
            $taskId = requireTaskId($input);
            $task = requireTask($db, $taskId);
            if ($task['task_type'] !== 'purchase') {
                http_response_code(400);
                echo json_encode(['error' => 'Not a purchase task']);
                exit;
            }

            // Calculate total time from started_at to now
            $totalMinutes = null;
            if ($task['started_at']) {
                $start = new DateTime($task['started_at']);
                $now = new DateTime();
                $totalMinutes = (int)round(($now->getTimestamp() - $start->getTimestamp()) / 60);
            }

            $db->prepare("
                UPDATE tasks SET purchase_status = 'delivered', delivered_at = NOW(),
                                 delivery_lat = ?, delivery_lng = ?,
                                 total_time_minutes = ?,
                                 status = 'completed', completed_at = NOW(), completed_by = ?
                WHERE id = ?
            ")->execute([
                !empty($input['lat']) ? (float)$input['lat'] : null,
                !empty($input['lng']) ? (float)$input['lng'] : null,
                $totalMinutes,
                $user['id'],
                $taskId,
            ]);

            logActivityExtended($user['id'], 'Purchase delivered', "Task: {$task['task_number']}", null, null,
                null, null, $task['plan_id'] ? (int)$task['plan_id'] : null);

            echo json_encode(['success' => true, 'purchase_status' => 'delivered', 'total_time_minutes' => $totalMinutes]);
            break;

        case 'verify':
            $taskId = requireTaskId($input);
            $task = requireTask($db, $taskId);
            if ($task['task_type'] !== 'purchase') {
                http_response_code(400);
                echo json_encode(['error' => 'Not a purchase task']);
                exit;
            }

            // Only admin/manager can verify
            if (!isAdmin() && ($user['role'] ?? '') !== 'manager') {
                http_response_code(403);
                echo json_encode(['error' => 'Only admin or manager can verify purchases']);
                exit;
            }

            $db->prepare("UPDATE tasks SET purchase_status = 'verified', verified_at = NOW() WHERE id = ?")->execute([$taskId]);

            logActivityExtended($user['id'], 'Purchase verified', "Task: {$task['task_number']}", null, null,
                null, null, $task['plan_id'] ? (int)$task['plan_id'] : null);

            echo json_encode(['success' => true, 'purchase_status' => 'verified']);
            break;

        case 'link_expense':
            $taskId = requireTaskId($input);
            $task = requireTask($db, $taskId);
            $expenseId = (int)($input['expense_id'] ?? 0);
            if (!$expenseId) {
                http_response_code(400);
                echo json_encode(['error' => 'expense_id required']);
                exit;
            }

            // Link both directions
            $db->prepare("UPDATE tasks SET expense_id = ? WHERE id = ?")->execute([$expenseId, $taskId]);
            $db->prepare("UPDATE expenses SET purchase_task_id = ? WHERE id = ?")->execute([$taskId, $expenseId]);

            // Also set actual_total from expense total if available
            $expTotal = $db->prepare("SELECT total FROM expenses WHERE id = ?");
            $expTotal->execute([$expenseId]);
            $total = $expTotal->fetchColumn();
            if ($total) {
                $db->prepare("UPDATE tasks SET actual_total = ? WHERE id = ?")->execute([$total, $taskId]);
            }

            echo json_encode(['success' => true]);
            break;

        case 'save_items':
            $taskId = requireTaskId($input);
            $task = requireTask($db, $taskId);
            $items = $input['items'] ?? [];
            if (!is_array($items)) {
                http_response_code(400);
                echo json_encode(['error' => 'items array required']);
                exit;
            }

            // Delete existing items and re-insert
            $db->prepare("DELETE FROM task_items WHERE task_id = ?")->execute([$taskId]);

            $estimatedTotal = 0;
            $itemStmt = $db->prepare("
                INSERT INTO task_items (task_id, vendor_product_id, product_id, name, description,
                                        quantity, unit, estimated_unit_price, actual_unit_price, actual_total,
                                        is_purchased, is_substituted, substitution_notes, sort_order)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
            ");
            foreach ($items as $i => $item) {
                $qty = (float)($item['quantity'] ?? 1);
                $estPrice = !empty($item['estimated_unit_price']) ? (float)$item['estimated_unit_price'] : null;
                $actPrice = !empty($item['actual_unit_price']) ? (float)$item['actual_unit_price'] : null;
                $actTotal = !empty($item['actual_total']) ? (float)$item['actual_total'] : ($actPrice ? $actPrice * $qty : null);

                if ($estPrice) {
                    $estimatedTotal += $estPrice * $qty;
                }

                $itemStmt->execute([
                    $taskId,
                    !empty($item['vendor_product_id']) ? (int)$item['vendor_product_id'] : null,
                    !empty($item['product_id']) ? (int)$item['product_id'] : null,
                    trim($item['name'] ?? 'Item'),
                    trim($item['description'] ?? '') ?: null,
                    $qty,
                    trim($item['unit'] ?? '') ?: null,
                    $estPrice,
                    $actPrice,
                    $actTotal,
                    !empty($item['is_purchased']) ? 1 : 0,
                    !empty($item['is_substituted']) ? 1 : 0,
                    trim($item['substitution_notes'] ?? '') ?: null,
                    $i,
                ]);
            }

            // Update estimated total on the task
            if ($estimatedTotal > 0) {
                $db->prepare("UPDATE tasks SET estimated_total = ? WHERE id = ?")->execute([$estimatedTotal, $taskId]);
            }

            echo json_encode(['success' => true, 'items_count' => count($items), 'estimated_total' => $estimatedTotal]);
            break;

        default:
            http_response_code(400);
            echo json_encode(['error' => 'Unknown action']);
    }
} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode(['error' => 'Server error']);
    error_log("Tasks API error: " . $e->getMessage());
}

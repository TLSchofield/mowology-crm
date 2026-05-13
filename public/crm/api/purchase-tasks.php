<?php
/**
 * Purchase Tasks API
 * /crm/api/purchase-tasks.php
 *
 * Handles status transitions and item toggles for purchase_tasks (procurement/vendor runs).
 * These are distinct from the CRM tasks table handled by tasks.php.
 *
 * GET  ?action=get&task_id=N         — fetch single task with items
 * POST action=update_status           — transition purchase_status
 * POST action=toggle_item             — toggle is_purchased on an item
 * POST action=create                  — create a purchase task (admin/manager)
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
    $taskId = (int)($_GET['task_id'] ?? 0);
    if ($taskId <= 0) ptApiError('Invalid task_id');

    $stmt = $db->prepare("
        SELECT pt.*, u.full_name AS assigned_to_name
        FROM purchase_tasks pt
        LEFT JOIN users u ON pt.assigned_to_id = u.id
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
    $remaining   = (int)$countStmt->fetchColumn();
    $taskStatus  = $item['purchase_status'];

    if ($remaining === 0 && $taskStatus !== 'purchased') {
        $db->prepare("UPDATE purchase_tasks SET purchase_status = 'purchased', updated_at = NOW() WHERE id = ?")
           ->execute([$item['task_id']]);
        $taskStatus = 'purchased';
    }

    ptApiOk(['item_id' => $itemId, 'is_purchased' => $newVal, 'task_status' => $taskStatus]);
}

// ── create ─────────────────────────────────────────────────────────────────────
if ($action === 'create') {
    if ($user['role'] !== 'admin' && $user['role'] !== 'manager') ptApiError('Admin or manager required', 403);
    if (empty($body['task_date'])) ptApiError('Missing task_date');
    if (empty($body['title']))     ptApiError('Missing title');

    $taskDate  = $body['task_date'];
    $title     = trim($body['title']);
    $vendor    = trim($body['vendor_name'] ?? '') ?: null;
    $location  = trim($body['location_address'] ?? '') ?: null;
    $locationL = trim($body['location_label'] ?? '') ?: null;
    $assignTo  = !empty($body['assigned_to_id']) ? (int)$body['assigned_to_id'] : null;
    $mode      = $body['procurement_mode'] ?? 'vendor_run';
    $priority  = $body['priority'] ?? 'normal';
    $total     = isset($body['estimated_total']) && $body['estimated_total'] !== '' ? (float)$body['estimated_total'] : null;
    $notes     = trim($body['notes'] ?? '') ?: null;

    $year = date('Y', strtotime($taskDate));
    $countStmt = $db->prepare("SELECT COUNT(*) FROM purchase_tasks WHERE YEAR(task_date) = ?");
    $countStmt->execute([$year]);
    $seq = (int)$countStmt->fetchColumn() + 1;
    $taskNumber = 'PT-' . $year . '-' . str_pad((string)$seq, 4, '0', STR_PAD_LEFT);

    $ins = $db->prepare("
        INSERT INTO purchase_tasks
            (task_number, title, task_date, vendor_name, location_address, location_label,
             procurement_mode, priority, estimated_total, assigned_to_id, notes, created_by_id)
        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
    ");
    $ins->execute([$taskNumber, $title, $taskDate, $vendor, $location, $locationL,
                   $mode, $priority, $total, $assignTo, $notes, $userId]);
    $newId = (int)$db->lastInsertId();

    $items = $body['items'] ?? [];
    if (is_array($items)) {
        $itemIns = $db->prepare("
            INSERT INTO purchase_task_items (task_id, description, quantity, unit, unit_price, sort_order)
            VALUES (?, ?, ?, ?, ?, ?)
        ");
        foreach ($items as $idx => $item) {
            if (empty($item['description'])) continue;
            $itemIns->execute([
                $newId,
                trim($item['description']),
                (float)($item['quantity'] ?? 1),
                $item['unit'] ?? null,
                isset($item['unit_price']) && $item['unit_price'] !== '' ? (float)$item['unit_price'] : null,
                (int)$idx,
            ]);
        }
    }

    ptApiOk(['task_id' => $newId, 'task_number' => $taskNumber]);
}

// ── update ─────────────────────────────────────────────────────────────────────
if ($action === 'update') {
    if ($user['role'] !== 'admin' && $user['role'] !== 'manager') ptApiError('Admin or manager required', 403);

    $taskId = (int)($body['task_id'] ?? 0);
    if ($taskId <= 0) ptApiError('Missing task_id');
    if (empty($body['task_date'])) ptApiError('Missing task_date');
    if (empty($body['title']))     ptApiError('Missing title');

    $taskDate  = $body['task_date'];
    $title     = trim($body['title']);
    $vendor    = trim($body['vendor_name'] ?? '') ?: null;
    $location  = trim($body['location_address'] ?? '') ?: null;
    $locationL = trim($body['location_label'] ?? '') ?: null;
    $assignTo  = !empty($body['assigned_to_id']) ? (int)$body['assigned_to_id'] : null;
    $mode      = $body['procurement_mode'] ?? 'vendor_run';
    $priority  = $body['priority'] ?? 'normal';
    $total     = isset($body['estimated_total']) && $body['estimated_total'] !== '' ? (float)$body['estimated_total'] : null;
    $notes     = trim($body['notes'] ?? '') ?: null;

    $db->prepare("
        UPDATE purchase_tasks SET
            title = ?, task_date = ?, vendor_name = ?, location_address = ?, location_label = ?,
            procurement_mode = ?, priority = ?, estimated_total = ?, assigned_to_id = ?, notes = ?,
            updated_at = NOW()
        WHERE id = ?
    ")->execute([$title, $taskDate, $vendor, $location, $locationL,
                 $mode, $priority, $total, $assignTo, $notes, $taskId]);

    // Replace items: delete existing, re-insert from payload
    $db->prepare("DELETE FROM purchase_task_items WHERE task_id = ?")->execute([$taskId]);
    $items = $body['items'] ?? [];
    if (is_array($items)) {
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

    ptApiOk(['task_id' => $taskId]);
}

ptApiError('Unknown action');

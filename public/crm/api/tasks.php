<?php
/**
 * Tasks CRUD API
 *
 * GET                        — list tasks (filters: status, assigned_to, priority, contact_id, quote_id, etc.)
 * POST ?action=create        — create task
 * POST ?action=update        — update task
 * POST ?action=complete      — mark task completed
 * POST ?action=reopen        — reopen a completed task
 * POST ?action=delete        — delete task
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

// ── GET: List tasks ──────────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'GET' && !$action) {
    $where  = [];
    $params = [];

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

    $whereClause = $where ? 'WHERE ' . implode(' AND ', $where) : '';
    $limit  = min((int)($_GET['limit'] ?? 50), 200);
    $offset = max((int)($_GET['offset'] ?? 0), 0);

    $stmt = $db->prepare("
        SELECT t.*,
               u_assigned.full_name AS assigned_to_name,
               u_created.full_name AS created_by_name,
               c.first_name AS contact_first, c.last_name AS contact_last,
               q.quote_number,
               jp.plan_number
        FROM tasks t
        LEFT JOIN users u_assigned ON t.assigned_to = u_assigned.id
        LEFT JOIN users u_created ON t.created_by = u_created.id
        LEFT JOIN contacts c ON t.contact_id = c.id
        LEFT JOIN quotes q ON t.quote_id = q.id
        LEFT JOIN job_plans jp ON t.plan_id = jp.id
        {$whereClause}
        ORDER BY
            CASE t.status WHEN 'in_progress' THEN 0 WHEN 'pending' THEN 1 ELSE 2 END,
            CASE t.priority WHEN 'high' THEN 0 WHEN 'normal' THEN 1 ELSE 2 END,
            t.due_date ASC,
            t.created_at DESC
        LIMIT ? OFFSET ?
    ");
    $params[] = $limit;
    $params[] = $offset;
    $stmt->execute($params);

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

            $stmt = $db->prepare("
                INSERT INTO tasks (title, description, due_date, due_time, priority, assigned_to,
                                   contact_id, company_id, property_id, quote_id, plan_id, invoice_id,
                                   is_recurring, recurrence_pattern, recurrence_end_date, created_by)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
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
            ]);
            $taskId = (int)$db->lastInsertId();

            logActivityExtended($user['id'], 'Task created', "Task: {$title}", null, null,
                !empty($input['quote_id']) ? (int)$input['quote_id'] : null,
                !empty($input['invoice_id']) ? (int)$input['invoice_id'] : null,
                !empty($input['plan_id']) ? (int)$input['plan_id'] : null
            );

            echo json_encode(['success' => true, 'task_id' => $taskId]);
            break;

        case 'update':
            $taskId = (int)($input['task_id'] ?? 0);
            if (!$taskId) {
                http_response_code(400);
                echo json_encode(['error' => 'task_id required']);
                exit;
            }

            // Fetch old values
            $old = $db->prepare("SELECT * FROM tasks WHERE id = ?");
            $old->execute([$taskId]);
            $oldTask = $old->fetch(PDO::FETCH_ASSOC);
            if (!$oldTask) {
                http_response_code(404);
                echo json_encode(['error' => 'Task not found']);
                exit;
            }

            $title    = trim($input['title'] ?? $oldTask['title']);
            $desc     = array_key_exists('description', $input) ? trim($input['description']) : $oldTask['description'];
            $dueDate  = array_key_exists('due_date', $input) ? ($input['due_date'] ?: null) : $oldTask['due_date'];
            $dueTime  = array_key_exists('due_time', $input) ? ($input['due_time'] ?: null) : $oldTask['due_time'];
            $priority = in_array($input['priority'] ?? '', ['high', 'normal', 'low']) ? $input['priority'] : $oldTask['priority'];
            $status   = in_array($input['status'] ?? '', ['pending', 'in_progress', 'completed']) ? $input['status'] : $oldTask['status'];
            $assigned = array_key_exists('assigned_to', $input) ? ((int)$input['assigned_to'] ?: null) : $oldTask['assigned_to'];

            $stmt = $db->prepare("
                UPDATE tasks SET title = ?, description = ?, due_date = ?, due_time = ?,
                                 priority = ?, status = ?, assigned_to = ?
                WHERE id = ?
            ");
            $stmt->execute([$title, $desc, $dueDate, $dueTime, $priority, $status, $assigned, $taskId]);

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
            $taskId = (int)($input['task_id'] ?? 0);
            if (!$taskId) {
                http_response_code(400);
                echo json_encode(['error' => 'task_id required']);
                exit;
            }

            $old = $db->prepare("SELECT status FROM tasks WHERE id = ?");
            $old->execute([$taskId]);
            $oldStatus = $old->fetchColumn();

            $db->prepare("UPDATE tasks SET status = 'completed', completed_at = NOW(), completed_by = ? WHERE id = ?")->execute([$user['id'], $taskId]);
            trackFieldChange('task', $taskId, 'status', $oldStatus, 'completed', $user['id']);

            echo json_encode(['success' => true]);
            break;

        case 'reopen':
            $taskId = (int)($input['task_id'] ?? 0);
            if (!$taskId) {
                http_response_code(400);
                echo json_encode(['error' => 'task_id required']);
                exit;
            }

            $db->prepare("UPDATE tasks SET status = 'pending', completed_at = NULL, completed_by = NULL WHERE id = ?")->execute([$taskId]);
            trackFieldChange('task', $taskId, 'status', 'completed', 'pending', $user['id']);

            echo json_encode(['success' => true]);
            break;

        case 'delete':
            $taskId = (int)($input['task_id'] ?? 0);
            if (!$taskId) {
                http_response_code(400);
                echo json_encode(['error' => 'task_id required']);
                exit;
            }

            $db->prepare("DELETE FROM tasks WHERE id = ?")->execute([$taskId]);
            echo json_encode(['success' => true]);
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

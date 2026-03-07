<?php
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
require_once CRM_INCLUDES . '/functions.php';

header('Content-Type: application/json; charset=utf-8');
requireLogin();
$user = getCurrentUser();
session_write_close();

$db     = getDB();
$userId = (int)$user['id'];
$action = $_GET['action'] ?? '';

try {
    switch ($action) {

        // ── Inbox ────────────────────────────────────────────────────────────
        case 'inbox': {
            $page    = max(1, (int)($_GET['page'] ?? 1));
            $perPage = 20;
            $offset  = ($page - 1) * $perPage;

            $countStmt = $db->prepare("SELECT COUNT(*) FROM messages WHERE to_user_id = ?");
            $countStmt->execute([$userId]);
            $total = (int)$countStmt->fetchColumn();

            $stmt = $db->prepare("
                SELECT m.id, m.subject, m.body, m.is_read, m.read_at, m.context_type, m.context_id, m.created_at,
                       u.full_name AS from_name, u.id AS from_user_id
                FROM messages m
                JOIN users u ON u.id = m.from_user_id
                WHERE m.to_user_id = ?
                ORDER BY m.is_read ASC, m.created_at DESC
                LIMIT {$perPage} OFFSET {$offset}
            ");
            $stmt->execute([$userId]);
            $messages = $stmt->fetchAll(PDO::FETCH_ASSOC);

            echo json_encode([
                'success'  => true,
                'messages' => $messages,
                'total'    => $total,
                'page'     => $page,
                'pages'    => (int)ceil($total / $perPage),
            ]);
            break;
        }

        // ── Sent ─────────────────────────────────────────────────────────────
        case 'sent': {
            $page    = max(1, (int)($_GET['page'] ?? 1));
            $perPage = 20;
            $offset  = ($page - 1) * $perPage;

            $countStmt = $db->prepare("SELECT COUNT(*) FROM messages WHERE from_user_id = ?");
            $countStmt->execute([$userId]);
            $total = (int)$countStmt->fetchColumn();

            $stmt = $db->prepare("
                SELECT m.id, m.subject, m.body, m.is_read, m.read_at, m.context_type, m.context_id, m.created_at,
                       u.full_name AS to_name, u.id AS to_user_id
                FROM messages m
                JOIN users u ON u.id = m.to_user_id
                WHERE m.from_user_id = ?
                ORDER BY m.created_at DESC
                LIMIT {$perPage} OFFSET {$offset}
            ");
            $stmt->execute([$userId]);
            $messages = $stmt->fetchAll(PDO::FETCH_ASSOC);

            echo json_encode([
                'success'  => true,
                'messages' => $messages,
                'total'    => $total,
                'page'     => $page,
                'pages'    => (int)ceil($total / $perPage),
            ]);
            break;
        }

        // ── Unread count ──────────────────────────────────────────────────────
        case 'unread-count': {
            $stmt = $db->prepare("SELECT COUNT(*) FROM messages WHERE to_user_id = ? AND is_read = 0");
            $stmt->execute([$userId]);
            echo json_encode(['success' => true, 'count' => (int)$stmt->fetchColumn()]);
            break;
        }

        // ── Compose ───────────────────────────────────────────────────────────
        case 'compose': {
            if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
                http_response_code(405);
                echo json_encode(['error' => 'POST required']);
                break;
            }

            $input = json_decode(file_get_contents('php://input'), true) ?? [];
            verifyCSRFToken($input['csrf_token'] ?? '');

            $toUserId    = (int)($input['to_user_id'] ?? 0);
            $subject     = substr(trim($input['subject'] ?? ''), 0, 200);
            $body        = trim($input['body'] ?? '');
            $contextType = in_array($input['context_type'] ?? '', ['none','job_plan','visit','contact'])
                ? ($input['context_type'] ?? 'none')
                : 'none';
            $contextId   = (int)($input['context_id'] ?? 0);

            if (!$toUserId || !$body) {
                http_response_code(400);
                echo json_encode(['error' => 'to_user_id and body are required']);
                break;
            }

            // Verify recipient exists
            $checkStmt = $db->prepare("SELECT id FROM users WHERE id = ? AND is_active = 1");
            $checkStmt->execute([$toUserId]);
            if (!$checkStmt->fetch()) {
                http_response_code(400);
                echo json_encode(['error' => 'Recipient not found']);
                break;
            }

            $db->prepare("
                INSERT INTO messages (from_user_id, to_user_id, subject, body, context_type, context_id, created_at)
                VALUES (?, ?, ?, ?, ?, ?, NOW())
            ")->execute([
                $userId,
                $toUserId,
                $subject ?: '(no subject)',
                $body,
                $contextType,
                $contextId ?: null,
            ]);

            echo json_encode(['success' => true, 'id' => (int)$db->lastInsertId()]);
            break;
        }

        // ── Mark read ─────────────────────────────────────────────────────────
        case 'mark-read': {
            if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
                http_response_code(405);
                echo json_encode(['error' => 'POST required']);
                break;
            }

            $input = json_decode(file_get_contents('php://input'), true) ?? [];
            verifyCSRFToken($input['csrf_token'] ?? '');

            $ids = array_filter(array_map('intval', (array)($input['ids'] ?? [])), fn($id) => $id > 0);
            if (empty($ids)) {
                http_response_code(400);
                echo json_encode(['error' => 'ids array required']);
                break;
            }

            $placeholders = implode(',', array_fill(0, count($ids), '?'));
            $params = array_merge(array_values($ids), [$userId]);
            $db->prepare("
                UPDATE messages
                SET is_read = 1, read_at = NOW()
                WHERE id IN ({$placeholders}) AND to_user_id = ? AND is_read = 0
            ")->execute($params);

            echo json_encode(['success' => true]);
            break;
        }

        // ── Team members list (for compose "To" dropdown) ─────────────────────
        case 'users': {
            $stmt = $db->query("
                SELECT id, full_name, role
                FROM users
                WHERE is_active = 1 AND id != {$userId}
                ORDER BY full_name ASC
            ");
            echo json_encode(['success' => true, 'users' => $stmt->fetchAll(PDO::FETCH_ASSOC)]);
            break;
        }

        default:
            http_response_code(400);
            echo json_encode(['error' => "Unknown action: {$action}"]);
    }

} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode(['error' => $e->getMessage()]);
}

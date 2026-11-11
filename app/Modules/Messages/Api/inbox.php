<?php
declare(strict_types=1);

/**
 * app/Modules/Messages/Api/inbox.php
 *
 * Mobile Messages API — JWT authenticated
 *
 * GET  /api/messages/inbox?action=inbox[&page=N]    — paginated inbox
 * GET  /api/messages/inbox?action=unread-count      — unread badge count
 * GET  /api/messages/inbox?action=users             — active users for compose To: list
 * POST /api/messages/inbox
 *   {"action":"mark-read","ids":[1,2,3]}
 *   {"action":"compose","to_user_id":N,"subject":"...","body":"...","context_type":"none","context_id":0}
 *
 * JWT replaces CSRF — no session or CSRF token required.
 */

if (!defined('APP_ROOT')) {
    $__dir = __DIR__;
    for ($__i = 0; $__i < 6; $__i++) {
        $__dir = dirname($__dir);
        if (is_file($__dir . '/app/Core/paths.php')) {
            require_once $__dir . '/app/Core/paths.php';
            break;
        }
    }
    unset($__dir, $__i);
}

header('Content-Type: application/json; charset=utf-8');
header('X-Content-Type-Options: nosniff');

require_once APP_ROOT . '/Core/config.php';
require_once APP_ROOT . '/Core/Auth/JwtAuth.php';

$jwtUser = requireJwt();
$userId  = (int)$jwtUser['id'];
$db      = getDB();

function msgOk(array $data): void
{
    echo json_encode(array_merge(['success' => true], $data));
    exit;
}

function msgErr(string $msg, int $code = 400): void
{
    http_response_code($code);
    echo json_encode(['success' => false, 'error' => $msg]);
    exit;
}

$method = $_SERVER['REQUEST_METHOD'];

// ── GET actions ───────────────────────────────────────────────────────────────

if ($method === 'GET') {
    $action = $_GET['action'] ?? 'inbox';

    switch ($action) {

        case 'inbox': {
            $page    = max(1, (int)($_GET['page'] ?? 1));
            $perPage = 20;
            $offset  = ($page - 1) * $perPage;

            $countStmt = $db->prepare("SELECT COUNT(*) FROM messages WHERE to_user_id = ?");
            $countStmt->execute([$userId]);
            $total = (int)$countStmt->fetchColumn();

            $stmt = $db->prepare("
                SELECT m.id, m.subject, m.body, m.is_read, m.read_at,
                       m.context_type, m.context_id, m.created_at,
                       u.full_name AS from_name, u.id AS from_user_id
                FROM messages m
                JOIN users u ON u.id = m.from_user_id
                WHERE m.to_user_id = ?
                ORDER BY m.is_read ASC, m.created_at DESC
                LIMIT {$perPage} OFFSET {$offset}
            ");
            $stmt->execute([$userId]);
            $messages = $stmt->fetchAll(PDO::FETCH_ASSOC);

            // Cast types for Swift Decodable
            foreach ($messages as &$msg) {
                $msg['id']          = (int)$msg['id'];
                $msg['is_read']     = (bool)(int)$msg['is_read'];
                $msg['from_user_id'] = (int)$msg['from_user_id'];
                $msg['context_id']  = $msg['context_id'] !== null ? (int)$msg['context_id'] : null;
            }
            unset($msg);

            msgOk([
                'messages' => $messages,
                'total'    => $total,
                'page'     => $page,
                'pages'    => (int)ceil($total / $perPage),
            ]);
        }

        case 'unread-count': {
            $stmt = $db->prepare("SELECT COUNT(*) FROM messages WHERE to_user_id = ? AND is_read = 0");
            $stmt->execute([$userId]);
            msgOk(['count' => (int)$stmt->fetchColumn()]);
        }

        case 'users': {
            $stmt = $db->prepare("
                SELECT id, full_name, role
                FROM users
                WHERE is_active = 1 AND id != ?
                ORDER BY full_name ASC
            ");
            $stmt->execute([$userId]);
            $users = $stmt->fetchAll(PDO::FETCH_ASSOC);
            foreach ($users as &$u) {
                $u['id'] = (int)$u['id'];
            }
            unset($u);
            msgOk(['users' => $users]);
        }

        default:
            msgErr('Unknown action. Use inbox, unread-count, or users');
    }
}

// ── POST actions ──────────────────────────────────────────────────────────────

if ($method !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'error' => 'Method not allowed']);
    exit;
}

$input  = json_decode(file_get_contents('php://input'), true) ?? [];
$action = $input['action'] ?? '';

// ── POST: mark-read ───────────────────────────────────────────────────────────

if ($action === 'mark-read') {
    $ids = array_filter(array_map('intval', (array)($input['ids'] ?? [])), fn($id) => $id > 0);
    if (empty($ids)) {
        msgErr('ids array required');
    }

    $placeholders = implode(',', array_fill(0, count($ids), '?'));
    $params = array_merge(array_values($ids), [$userId]);
    $db->prepare("
        UPDATE messages
        SET is_read = 1, read_at = NOW()
        WHERE id IN ({$placeholders}) AND to_user_id = ? AND is_read = 0
    ")->execute($params);

    msgOk([]);
}

// ── POST: compose ─────────────────────────────────────────────────────────────

if ($action === 'compose') {
    $toUserId    = (int)($input['to_user_id'] ?? 0);
    $subject     = substr(trim($input['subject'] ?? ''), 0, 200);
    $body        = trim($input['body'] ?? '');
    $contextType = in_array($input['context_type'] ?? '', ['none', 'job_plan', 'visit', 'contact'])
        ? ($input['context_type'] ?? 'none') : 'none';
    $contextId   = (int)($input['context_id'] ?? 0);

    if (!$toUserId || !$body) {
        msgErr('to_user_id and body are required');
    }

    // Verify recipient is active
    $checkStmt = $db->prepare("SELECT id FROM users WHERE id = ? AND is_active = 1");
    $checkStmt->execute([$toUserId]);
    if (!$checkStmt->fetch()) {
        msgErr('Recipient not found', 404);
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

    msgOk(['id' => (int)$db->lastInsertId()]);
}

msgErr('Unknown action. Use mark-read or compose');

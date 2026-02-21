<?php
/**
 * API Endpoint: Bulk Page Status Update
 *
 * POST /crm/api/bulk-status.php
 * Change status of multiple pages at once.
 *
 * Body: csrf_token, action (publish|unpublish|archive), page_ids[] (int[])
 */

declare(strict_types=1);

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
require_once CRM_INCLUDES . '/cms-functions.php';

header('Content-Type: application/json');

requireLogin();
$user = getCurrentUser();

if (!in_array($user['role'], ['admin', 'staff'])) {
    http_response_code(403);
    echo json_encode(['success' => false, 'error' => 'Access denied']);
    exit;
}

if (!verifyCSRFToken($_POST['csrf_token'] ?? '')) {
    http_response_code(403);
    echo json_encode(['success' => false, 'error' => 'CSRF token invalid']);
    exit;
}

$action  = $_POST['action'] ?? '';
$pageIds = array_map('intval', (array)($_POST['page_ids'] ?? []));
$pageIds = array_filter($pageIds);

$allowedActions = ['publish', 'unpublish', 'archive'];
if (!in_array($action, $allowedActions)) {
    echo json_encode(['success' => false, 'error' => 'Invalid action']);
    exit;
}
if (empty($pageIds)) {
    echo json_encode(['success' => false, 'error' => 'No pages selected']);
    exit;
}

// Map action → status
$statusMap = [
    'publish'   => 'published',
    'unpublish' => 'draft',
    'archive'   => 'archived',
];
$newStatus = $statusMap[$action];

try {
    $db = getDB();
    $placeholders = implode(',', array_fill(0, count($pageIds), '?'));
    $stmt = $db->prepare("
        UPDATE cms_pages
        SET status = ?, updated_at = NOW()
        WHERE id IN ($placeholders)
    ");
    $stmt->execute(array_merge([$newStatus], array_values($pageIds)));
    $affected = $stmt->rowCount();

    // Audit log
    cms_logCmsActivity(
        $user['id'],
        'page_updated',
        "Bulk {$action}: {$affected} page(s) → {$newStatus} (IDs: " . implode(', ', $pageIds) . ")",
        ['action' => $action, 'page_ids' => $pageIds, 'new_status' => $newStatus]
    );

    echo json_encode([
        'success'  => true,
        'affected' => $affected,
        'status'   => $newStatus,
        'message'  => "{$affected} page(s) set to {$newStatus}",
    ]);
} catch (PDOException $e) {
    error_log('bulk-status.php error: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => 'Database error']);
    exit;
}

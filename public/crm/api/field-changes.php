<?php
/**
 * Field Changes API
 *
 * GET ?entity_type=quote&entity_id=5  — returns change history
 * GET ?entity_type=all&limit=100      — returns recent changes across all entities (admin)
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
requireLogin();
$user = getCurrentUser();
session_write_close();

$db = getDB();

$entityType = $_GET['entity_type'] ?? '';
$entityId   = (int)($_GET['entity_id'] ?? 0);
$limit      = min((int)($_GET['limit'] ?? 50), 200);
$offset     = max((int)($_GET['offset'] ?? 0), 0);

try {
    if ($entityType === 'all') {
        // Admin-only: all recent changes
        if (!function_exists('userHasPermission') || !userHasPermission('settings.edit')) {
            http_response_code(403);
            echo json_encode(['error' => 'Insufficient permission']);
            exit;
        }
        $stmt = $db->prepare("
            SELECT fc.*, u.full_name AS changed_by_name
            FROM field_changes fc
            LEFT JOIN users u ON fc.changed_by = u.id
            ORDER BY fc.changed_at DESC
            LIMIT ? OFFSET ?
        ");
        $stmt->execute([$limit, $offset]);
    } else {
        if (!$entityType || !$entityId) {
            http_response_code(400);
            echo json_encode(['error' => 'entity_type and entity_id required']);
            exit;
        }
        $stmt = $db->prepare("
            SELECT fc.*, u.full_name AS changed_by_name
            FROM field_changes fc
            LEFT JOIN users u ON fc.changed_by = u.id
            WHERE fc.entity_type = ? AND fc.entity_id = ?
            ORDER BY fc.changed_at DESC
            LIMIT ? OFFSET ?
        ");
        $stmt->execute([$entityType, $entityId, $limit, $offset]);
    }

    $changes = $stmt->fetchAll(PDO::FETCH_ASSOC);
    echo json_encode(['success' => true, 'data' => $changes]);

} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode(['error' => 'Failed to load field changes']);
    error_log("field-changes API error: " . $e->getMessage());
}

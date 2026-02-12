<?php
/**
 * API Endpoint: Delete Media
 *
 * POST /crm/api/delete-media.php
 * Soft delete media asset (marks as deleted, doesn't remove file)
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

// Access control
if (!in_array($user['role'], ['admin', 'staff'])) {
    http_response_code(403);
    echo json_encode(['success' => false, 'error' => 'Access denied']);
    exit;
}

// Verify CSRF token
$input = json_decode(file_get_contents('php://input'), true);
if (!verifyCSRFToken($_SERVER['HTTP_X_CSRF_TOKEN'] ?? '')) {
    http_response_code(403);
    echo json_encode(['success' => false, 'error' => 'CSRF token invalid']);
    exit;
}

$mediaId = (int)($input['id'] ?? 0);
if (!$mediaId) {
    echo json_encode(['success' => false, 'error' => 'Invalid media ID']);
    exit;
}

try {
    // Get media info
    $db = getDB();
    $stmt = $db->prepare('SELECT * FROM media_assets WHERE id = ?');
    $stmt->execute([$mediaId]);
    $media = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$media) {
        http_response_code(404);
        echo json_encode(['success' => false, 'error' => 'Media not found']);
        exit;
    }

    // Delete record (file kept on disk for safety)
    $stmt = $db->prepare('DELETE FROM media_assets WHERE id = ?');
    $stmt->execute([$mediaId]);

    echo json_encode([
        'success' => true,
        'message' => 'Media deleted successfully',
    ]);
} catch (PDOException $e) {
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => 'Database error']);
    exit;
}

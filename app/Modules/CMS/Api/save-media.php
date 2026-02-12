<?php
/**
 * API Endpoint: Save Media
 *
 * POST /crm/api/save-media.php
 * Update media metadata (alt text, etc.)
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
if (!verifyCSRFToken($_POST['csrf_token'] ?? '')) {
    http_response_code(403);
    echo json_encode(['success' => false, 'error' => 'CSRF token invalid']);
    exit;
}

$mediaId = (int)($_POST['id'] ?? 0);
$altText = $_POST['alt_text'] ?? '';

if (!$mediaId) {
    echo json_encode(['success' => false, 'error' => 'Invalid media ID']);
    exit;
}

try {
    $db = getDB();

    // Verify media exists
    $stmt = $db->prepare('SELECT id FROM media_assets WHERE id = ?');
    $stmt->execute([$mediaId]);
    if (!$stmt->fetch(PDO::FETCH_ASSOC)) {
        http_response_code(404);
        echo json_encode(['success' => false, 'error' => 'Media not found']);
        exit;
    }

    // Update media
    $stmt = $db->prepare('
        UPDATE media_assets
        SET alt_text = ?, updated_at = NOW()
        WHERE id = ?
    ');
    $stmt->execute([$altText, $mediaId]);

    echo json_encode([
        'success' => true,
        'media_id' => $mediaId,
        'message' => 'Media updated successfully',
    ]);
} catch (PDOException $e) {
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => 'Database error']);
    exit;
}

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

$mediaId    = (int)($_POST['id'] ?? 0);
$altText    = trim($_POST['alt_text'] ?? '');
$caption    = trim($_POST['caption'] ?? '');
$description = trim($_POST['description'] ?? '');

if (!$mediaId) {
    echo json_encode(['success' => false, 'error' => 'Invalid media ID']);
    exit;
}

try {
    $db = getDB();

    // Verify media exists and get column list (caption/description may not exist yet)
    $stmt = $db->prepare('SELECT id FROM media_assets WHERE id = ?');
    $stmt->execute([$mediaId]);
    if (!$stmt->fetch(PDO::FETCH_ASSOC)) {
        http_response_code(404);
        echo json_encode(['success' => false, 'error' => 'Media not found']);
        exit;
    }

    // Check which optional columns exist (graceful degradation)
    $cols = [];
    try {
        $check = $db->query("SHOW COLUMNS FROM media_assets LIKE 'caption'");
        if ($check && $check->rowCount() > 0) $cols[] = 'caption';
    } catch (\Throwable $e) {
        error_log(sprintf('[%s] schema check for media_assets.caption failed — %s in %s:%d',
            basename(__FILE__), $e->getMessage(), $e->getFile(), $e->getLine()));
    }
    try {
        $check = $db->query("SHOW COLUMNS FROM media_assets LIKE 'description'");
        if ($check && $check->rowCount() > 0) $cols[] = 'description';
    } catch (\Throwable $e) {
        error_log(sprintf('[%s] schema check for media_assets.description failed — %s in %s:%d',
            basename(__FILE__), $e->getMessage(), $e->getFile(), $e->getLine()));
    }

    // Build update
    $setParts = ['alt_text = ?', 'updated_at = NOW()'];
    $params   = [$altText];

    if (in_array('caption', $cols)) {
        $setParts[] = 'caption = ?';
        $params[]   = $caption;
    }
    if (in_array('description', $cols)) {
        $setParts[] = 'description = ?';
        $params[]   = $description;
    }
    $params[] = $mediaId;

    $db->prepare('UPDATE media_assets SET ' . implode(', ', $setParts) . ' WHERE id = ?')
       ->execute($params);

    echo json_encode([
        'success'  => true,
        'media_id' => $mediaId,
        'message'  => 'Media updated successfully',
    ]);
} catch (PDOException $e) {
    error_log(sprintf('[%s] save-media DB error (media_id=%d) — %s in %s:%d',
        basename(__FILE__), $mediaId, $e->getMessage(), $e->getFile(), $e->getLine()));
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'error'   => 'Failed to save media metadata. Please try again or contact support if the problem persists.',
    ]);
    exit;
} catch (\Throwable $e) {
    error_log(sprintf('[%s] save-media unexpected error (media_id=%d) — %s in %s:%d',
        basename(__FILE__), $mediaId, $e->getMessage(), $e->getFile(), $e->getLine()));
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'error'   => 'Failed to save media metadata. Please try again or contact support if the problem persists.',
    ]);
    exit;
}

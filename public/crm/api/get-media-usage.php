<?php
/**
 * API Endpoint: Get Media Usage
 *
 * GET /crm/api/get-media-usage.php?id=123
 * Returns list of pages/blocks using a media asset
 */

declare(strict_types=1);

require_once dirname(__DIR__) . '/../loginAuth/auth.php';
require_once dirname(__DIR__) . '/includes/functions.php';
require_once dirname(__DIR__) . '/includes/cms-functions.php';

header('Content-Type: application/json');

requireLogin();
$user = getCurrentUser();

// Access control
if (!in_array($user['role'], ['admin', 'staff'])) {
    http_response_code(403);
    echo json_encode(['success' => false, 'error' => 'Access denied']);
    exit;
}

$mediaId = (int)($_GET['id'] ?? 0);
if (!$mediaId) {
    echo json_encode(['success' => false, 'error' => 'Invalid media ID']);
    exit;
}

try {
    $db = getDB();

    // Find blocks that reference this media
    $stmt = $db->prepare('
        SELECT DISTINCT cb.id, cb.page_id, cp.title, cp.slug
        FROM cms_blocks cb
        JOIN cms_pages cp ON cb.page_id = cp.id
        WHERE cb.config LIKE ?
        ORDER BY cp.title
    ');
    // Search for media_id in JSON config
    $stmt->execute(['%"media_id":' . $mediaId . '%']);
    $blocks = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $usage = [];
    foreach ($blocks as $block) {
        $usage[] = [
            'name' => $block['title'],
            'url' => '/crm/cms-page-editor.php?id=' . $block['page_id'],
            'type' => 'page',
        ];
    }

    echo json_encode([
        'success' => true,
        'usage' => $usage,
    ]);
} catch (PDOException $e) {
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => 'Database error']);
    exit;
}

<?php
/**
 * /app/Modules/Marketing/Api/apply-preview.php
 * AJAX Endpoint: Preview a recommendation before applying (creating draft page)
 *
 * GET /crm/api/seo/apply-preview.php?id=123
 * Response: {success: bool, content: {...}}
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

header('Content-Type: application/json');

require_once PUBLIC_ROOT . '/loginAuth/auth.php';
require_once CRM_INCLUDES . '/seo-functions.php';

requireLogin();
$user = getCurrentUser();

if (!$user || $user['role'] !== 'admin') {
    http_response_code(403);
    die(json_encode(['success' => false, 'message' => 'Admin access required']));
}

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    http_response_code(405);
    die(json_encode(['success' => false, 'message' => 'GET required']));
}

$recId = (int)($_GET['id'] ?? 0);
if (!$recId) {
    http_response_code(400);
    die(json_encode(['success' => false, 'message' => 'id required']));
}

$db = getDB();

try {
    // Get recommendation
    $recStmt = $db->prepare("
        SELECT id, query_text, suggested_slug, rec_type, priority_score, target_id, season_id
        FROM seo_recommendations
        WHERE id = ?
    ");
    $recStmt->execute([$recId]);
    $rec = $recStmt->fetch(PDO::FETCH_ASSOC);

    if (!$rec) {
        http_response_code(404);
        die(json_encode(['success' => false, 'message' => 'Recommendation not found']));
    }

    // Get target if applicable
    $target = null;
    if ($rec['target_id']) {
        $targetStmt = $db->prepare("SELECT * FROM seo_targets WHERE id = ?");
        $targetStmt->execute([$rec['target_id']]);
        $target = $targetStmt->fetch(PDO::FETCH_ASSOC);
    }

    // Generate content preview
    $seoContent = generateSEOContent($rec['query_text'], $rec['suggested_slug'], $target);
    $pageContent = generatePageContent($rec['query_text'], $target);
    $portolioImages = selectPortfolioImages($db, $target, $rec['query_text']);

    // Response
    http_response_code(200);
    die(json_encode([
        'success' => true,
        'content' => [
            'title' => $seoContent['title'],
            'meta_description' => $seoContent['meta_description'],
            'h1' => $pageContent['h1'],
            'slug' => $rec['suggested_slug'],
            'sections_count' => 3,
            'images_count' => count(array_slice($portolioImages, 0, 6)),
            'seo_score' => 75
        ]
    ]));

} catch (Throwable $e) {
    error_log("SEO Apply Preview Error: " . $e->getMessage());
    http_response_code(500);
    die(json_encode([
        'success' => false,
        'message' => 'Failed to generate preview: ' . $e->getMessage()
    ]));
}

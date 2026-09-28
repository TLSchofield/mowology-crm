<?php
/**
 * API: image URLs for an article (Content Engine photo picker).
 *
 * GET /crm/api/article-image.php?media_id=123
 * Returns JSON: { success, id, hero, inline, inline_webp, thumb, alt, caption, width, height }
 *   hero   — for the article header / OG image (og_image_path)
 *   inline — for <figure> in the body, with an optional webp twin
 *
 * Requires: marketing.view permission
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
if (!userHasPermission('marketing.view')) {
    http_response_code(403);
    echo json_encode(['success' => false, 'error' => 'marketing.view permission required']);
    exit;
}
require_once APP_ROOT . '/Modules/CMS/Services/ArticleService.php';

$mediaId = (int)($_GET['media_id'] ?? 0);
if ($mediaId <= 0) {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'media_id required']);
    exit;
}
try {
    $info = (new ArticleService(getDB()))->imageUrls($mediaId);
    if (!$info) {
        http_response_code(404);
        echo json_encode(['success' => false, 'error' => 'Media not found']);
        exit;
    }
    echo json_encode(array_merge(['success' => true], $info), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
} catch (Throwable $e) {
    error_log('article-image: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => 'Lookup failed']);
}

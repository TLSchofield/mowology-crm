<?php
/**
 * API: Generate SEO Article Draft
 *
 * POST /crm/api/marketing/generate-article.php
 *
 * Body (JSON):
 *   keyword        string  Target search query (required)
 *   city           string  City/area (required)
 *   service_type   string  e.g. "lawn aeration" (required)
 *   season         string  e.g. "spring" (optional)
 *   project_details string Real project notes for case study (optional)
 *   word_count     int     Target word count, default 1800 (optional)
 *
 * Returns JSON: { success, title, meta_description, body_html, faq_items,
 *                 schema_json, suggested_links, photo_prompts, word_count }
 *
 * Requires: marketing.edit permission
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

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    echo json_encode(['success' => false, 'error' => 'POST required']);
    exit;
}

$user = getCurrentUser();
if (!hasPermission($user, 'marketing.edit')) {
    http_response_code(403);
    echo json_encode(['success' => false, 'error' => 'marketing.edit permission required']);
    exit;
}

$body   = json_decode(file_get_contents('php://input'), true) ?? [];
$params = [
    'keyword'         => trim($body['keyword'] ?? ''),
    'city'            => trim($body['city'] ?? ''),
    'service_type'    => trim($body['service_type'] ?? ''),
    'season'          => trim($body['season'] ?? ''),
    'project_details' => trim($body['project_details'] ?? ''),
    'word_count'      => (int)($body['word_count'] ?? 1800),
];

if (!$params['keyword'] || !$params['city'] || !$params['service_type']) {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'keyword, city, and service_type are required']);
    exit;
}

try {
    require_once APP_ROOT . '/Modules/Marketing/Services/ArticleGeneratorService.php';
    $db      = getDB();
    $service = new ArticleGeneratorService($db);
    $result  = $service->generate($params);
    echo json_encode($result);
} catch (Throwable $e) {
    error_log('generate-article.php error: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => 'Article generation failed']);
}

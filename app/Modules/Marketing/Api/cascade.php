<?php
/**
 * API: Trigger Content Cascade
 *
 * POST /crm/api/marketing/cascade.php
 *
 * Body (JSON):
 *   article_id      int     CMS page ID (required)
 *   title           string  Article title (required)
 *   url             string  Full public URL (required)
 *   body_html       string  Full article HTML
 *   meta_description string
 *   channels        array   Channels to run: ['social','email','pdf_footer','email_signature']
 *
 * Returns JSON: { success, social_posts, email_campaign, pdf_promo, email_signature, errors }
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

$body = json_decode(file_get_contents('php://input'), true) ?? [];

$article = [
    'id'               => (int)($body['article_id'] ?? 0),
    'title'            => trim($body['title'] ?? ''),
    'url'              => trim($body['url'] ?? ''),
    'body_html'        => $body['body_html'] ?? '',
    'meta_description' => trim($body['meta_description'] ?? ''),
    'intro'            => trim($body['intro'] ?? ''),
];

if (!$article['id'] || !$article['title'] || !$article['url']) {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'article_id, title, and url are required']);
    exit;
}

$channels = is_array($body['channels'] ?? null)
    ? $body['channels']
    : ['social', 'email', 'pdf_footer', 'email_signature'];

// Sanitize channel list
$allowed  = ['social', 'email', 'pdf_footer', 'email_signature'];
$channels = array_values(array_intersect($channels, $allowed));

try {
    require_once APP_ROOT . '/Modules/Marketing/Services/ContentCascadeService.php';
    $db      = getDB();
    $service = new ContentCascadeService($db);
    $result  = $service->cascade($article, $channels, (int)$user['id']);
    echo json_encode($result);
} catch (Throwable $e) {
    error_log('cascade.php error: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => 'Cascade failed']);
}

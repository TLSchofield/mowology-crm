<?php
/**
 * API: Publish (or update) a Content Engine article as a CMS page under /blog/.
 *
 * POST /crm/api/publish-article.php
 *
 * Body (JSON):
 *   title            string  H1 (required)
 *   body_html        string  Article HTML (required)
 *   meta_description string
 *   slug             string  URL slug without "blog/" (optional; derived from title)
 *   author           string  Byline (optional)
 *   keyword, city, service_type, season   strings (optional; stored for schema/keywords)
 *   faq_items        array   [{question, answer}] (optional; emitted as FAQPage schema)
 *   status           string  'published' | 'draft' (default draft)
 *   page_id          int     update an existing article instead of creating one
 *
 * Returns JSON: { success, page_id, slug, url, status, edit_url }
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

// CMS function layer (cache invalidation, CMS_SITE_ID when the site resolver ran)
require_once PUBLIC_ROOT . '/crm/includes/cms-functions.php';
require_once APP_ROOT . '/Modules/CMS/Services/ArticleService.php';

$body = json_decode(file_get_contents('php://input'), true) ?? [];

$draft = [
    'page_id'          => (int)($body['page_id'] ?? 0),
    'title'            => trim((string)($body['title'] ?? '')),
    'body_html'        => (string)($body['body_html'] ?? ''),
    'meta_description' => trim((string)($body['meta_description'] ?? '')),
    'slug'             => trim((string)($body['slug'] ?? '')),
    'author'           => trim((string)($body['author'] ?? '')),
    'keyword'          => trim((string)($body['keyword'] ?? '')),
    'city'             => trim((string)($body['city'] ?? '')),
    'service_type'     => trim((string)($body['service_type'] ?? '')),
    'season'           => trim((string)($body['season'] ?? '')),
    'faq_items'        => is_array($body['faq_items'] ?? null) ? $body['faq_items'] : [],
    'status'           => ($body['status'] ?? 'draft') === 'published' ? 'published' : 'draft',
];

try {
    $service = new ArticleService(getDB());
    $result  = $service->save($draft, (int)$user['id']);

    if (function_exists('cms_logCmsActivity')) {
        cms_logCmsActivity(
            (int)$user['id'],
            $draft['page_id'] ? 'page_updated' : 'page_created',
            ($draft['page_id'] ? 'Updated' : 'Published') . " article #{$result['page_id']} \"{$draft['title']}\" ({$result['status']}) via Content Engine",
            ['page_id' => $result['page_id'], 'slug' => $result['slug'], 'status' => $result['status'], 'source' => 'content_engine']
        );
    }

    echo json_encode(array_merge($result, [
        'success'  => true,
        'edit_url' => '/crm/cms/cms-page-edit.php?id=' . $result['page_id'],
    ]));
} catch (InvalidArgumentException $e) {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
} catch (Throwable $e) {
    error_log('publish-article: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => 'Could not publish the article. Check the server log.']);
}

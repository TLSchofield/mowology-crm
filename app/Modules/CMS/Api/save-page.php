<?php
/**
 * Save CMS Page API Endpoint
 *
 * Handles POST requests to create/update pages
 *
 * POST /crm/api/save-page.php
 * {
 *   "id": 1,                    // null for create
 *   "slug": "about",
 *   "title": "About Us",
 *   "meta_title": "About Us | Mowology",
 *   "meta_description": "Learn about Mowology",
 *   "meta_keywords": "landscaping, about",
 *   "page_type": "about",
 *   "layout_template": "default",
 *   "status": "published",
 *   "noindex": 0
 * }
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

requireLogin();
$user = getCurrentUser();
requirePermission('marketing.edit');

// Verify CSRF token
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $token = $_POST['csrf_token'] ?? $_SERVER['HTTP_X_CSRF_TOKEN'] ?? '';
    if (!verifyCSRFToken($token)) {
        http_response_code(400);
        json_response(['success' => false, 'error' => 'Invalid CSRF token']);
    }
}

try {
    $pageId = !empty($_POST['id']) ? (int)$_POST['id'] : null;

    $title = trim($_POST['title'] ?? '');
    $slug  = trim($_POST['slug'] ?? '');

    // Auto-SEO: fill empty meta_title with "{Title} | Mowology Landscaping"
    $metaTitle = trim($_POST['meta_title'] ?? '');
    if ($metaTitle === '' && $title !== '') {
        $siteName = defined('SITE_NAME') ? SITE_NAME : 'Mowology Landscaping';
        $candidate = $title . ' | ' . $siteName;
        $metaTitle = mb_substr($candidate, 0, 60);
    }

    // Auto-SEO: fill empty meta_description from blocks if editing an existing page
    $metaDesc = trim($_POST['meta_description'] ?? '');
    if ($metaDesc === '' && $pageId) {
        $existingBlocks = cms_getBlocksByPageId($pageId, 0); // bypass cache
        foreach ($existingBlocks as $blk) {
            $cfg = $blk['config'] ?? [];
            // Try common text fields in order of preference
            $text = $cfg['subheadline'] ?? $cfg['description'] ?? $cfg['body'] ?? $cfg['text'] ?? $cfg['headline'] ?? '';
            if (is_string($text) && strlen(strip_tags($text)) > 30) {
                $metaDesc = mb_substr(strip_tags($text), 0, 155) . '…';
                break;
            }
        }
    }

    $pageData = [
        'slug' => $slug,
        'title' => $title,
        'meta_title' => $metaTitle,
        'meta_description' => $metaDesc,
        'meta_keywords' => $_POST['meta_keywords'] ?? '',
        'page_type' => $_POST['page_type'] ?? 'custom',
        'layout_template' => $_POST['layout_template'] ?? 'default',
        'status' => $_POST['status'] ?? 'draft',
        'noindex' => !empty($_POST['noindex']) ? 1 : 0,
    ];

    // Save page
    $savedPageId = cms_savePage($pageData, $pageId, $user['id']);

    // Create revision snapshot
    cms_createPageRevision(
        $savedPageId,
        $user['id'],
        $pageData['status'] === 'published' ? 'published' : 'draft',
        'Page ' . ($pageId ? 'updated' : 'created')
    );

    // Redirect to editor on success
    if (!empty($_POST['redirect'])) {
        header('Location: /crm/cms-page-editor.php?id=' . $savedPageId . '&status=success');
        exit;
    }

    json_response([
        'success' => true,
        'message' => 'Page saved successfully',
        'page_id' => $savedPageId,
        'edit_url' => '/crm/cms-page-editor.php?id=' . $savedPageId,
    ]);
} catch (Exception $e) {
    http_response_code(400);
    json_response([
        'success' => false,
        'error' => $e->getMessage(),
    ]);
}

/**
 * Send JSON response
 */
function json_response(array $data): void
{
    header('Content-Type: application/json');
    echo json_encode($data);
    exit;
}

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

require_once dirname(__DIR__) . '/../includes/bootstrap.php';
require_once dirname(__DIR__) . '/includes/cms-functions.php';

// Check auth
requireLogin();
$user = getCurrentUser();

if (!in_array($user['role'], ['admin', 'staff'])) {
    http_response_code(403);
    json_response(['success' => false, 'error' => 'Access denied']);
}

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

    $pageData = [
        'slug' => $_POST['slug'] ?? '',
        'title' => $_POST['title'] ?? '',
        'meta_title' => $_POST['meta_title'] ?? '',
        'meta_description' => $_POST['meta_description'] ?? '',
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

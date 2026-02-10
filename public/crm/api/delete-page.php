<?php
/**
 * Delete CMS Page API Endpoint
 *
 * Handles deletion of pages
 *
 * POST /crm/api/delete-page.php
 * { "id": 1 }
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
$token = $_POST['csrf_token'] ?? $_SERVER['HTTP_X_CSRF_TOKEN'] ?? '';
if (!verifyCSRFToken($token)) {
    http_response_code(400);
    json_response(['success' => false, 'error' => 'Invalid CSRF token']);
}

try {
    $pageId = (int)($_POST['id'] ?? $_GET['id'] ?? 0);

    if (!$pageId) {
        http_response_code(400);
        json_response(['success' => false, 'error' => 'Page ID required']);
    }

    // Verify page exists
    $page = cms_getPageById($pageId);
    if (!$page) {
        http_response_code(404);
        json_response(['success' => false, 'error' => 'Page not found']);
    }

    // Delete page (soft delete via archive)
    cms_deletePage($pageId, false);

    json_response([
        'success' => true,
        'message' => 'Page deleted successfully',
    ]);
} catch (Exception $e) {
    http_response_code(400);
    json_response([
        'success' => false,
        'error' => $e->getMessage(),
    ]);
}

function json_response(array $data): void
{
    header('Content-Type: application/json');
    echo json_encode($data);
    exit;
}

<?php
/**
 * Add Block to Page API Endpoint
 */

declare(strict_types=1);

require_once dirname(__DIR__) . '/../includes/bootstrap.php';
require_once dirname(__DIR__) . '/includes/cms-functions.php';

requireLogin();
$user = getCurrentUser();

if (!in_array($user['role'], ['admin', 'staff'])) {
    http_response_code(403);
    json_response(['success' => false, 'error' => 'Access denied']);
}

$token = $_POST['csrf_token'] ?? '';
if (!verifyCSRFToken($token)) {
    http_response_code(400);
    json_response(['success' => false, 'error' => 'Invalid CSRF token']);
}

try {
    $pageId = (int)($_POST['page_id'] ?? 0);
    $blockType = $_POST['block_type'] ?? '';

    if (!$pageId || !$blockType) {
        throw new Exception('Page ID and block type required');
    }

    // Verify page exists
    $page = cms_getPageById($pageId);
    if (!$page) {
        throw new Exception('Page not found');
    }

    // Get next position
    $blocks = cms_getBlocksByPageId($pageId);
    $position = count($blocks);

    // Get default config for block type
    $blockTemplate = cms_getBlockTemplate($blockType);
    $config = $blockTemplate['default_config'] ?? [];

    // Create block
    $blockId = cms_saveBlock($pageId, $blockType, $position, $config);

    json_response([
        'success' => true,
        'message' => 'Block added successfully',
        'block_id' => $blockId,
        'edit_url' => "/crm/cms-block-editor.php?block_id={$blockId}",
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

<?php
/**
 * API Endpoint: Reorder Blocks
 *
 * POST /crm/api/reorder-blocks.php
 * { "page_id": 1, "order": [3, 1, 2] }
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

header('Content-Type: application/json');

requireLogin();
$user = getCurrentUser();

if (!in_array($user['role'], ['admin', 'staff'])) {
    http_response_code(403);
    echo json_encode(['success' => false, 'error' => 'Access denied']);
    exit;
}

$token = $_SERVER['HTTP_X_CSRF_TOKEN'] ?? '';
if (!verifyCSRFToken($token)) {
    http_response_code(403);
    echo json_encode(['success' => false, 'error' => 'CSRF token invalid']);
    exit;
}

$input = json_decode(file_get_contents('php://input'), true);
$pageId = (int)($input['page_id'] ?? 0);
$order = $input['order'] ?? [];

if (!$pageId || !is_array($order) || empty($order)) {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'page_id and order[] required']);
    exit;
}

// Sanitize: all values must be positive integers
$order = array_map('intval', $order);
$order = array_filter($order, fn($id) => $id > 0);

try {
    cms_reorderBlocks($pageId, array_values($order));
    echo json_encode(['success' => true]);
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => 'Reorder failed']);
}

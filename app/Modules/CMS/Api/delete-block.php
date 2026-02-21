<?php
/**
 * API Endpoint: Delete Block
 *
 * POST /crm/api/delete-block.php
 * { "id": 1 }
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
$blockId = (int)($input['id'] ?? 0);

if (!$blockId) {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'Block ID required']);
    exit;
}

try {
    $block = cms_getBlockById($blockId);
    if (!$block) {
        http_response_code(404);
        echo json_encode(['success' => false, 'error' => 'Block not found']);
        exit;
    }

    cms_deleteBlock($blockId);

    echo json_encode(['success' => true, 'message' => 'Block deleted']);
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => 'Delete failed']);
}

<?php
declare(strict_types=1);

header('Content-Type: application/json; charset=utf-8');
if (!defined('APP_ROOT')) {
    $__dir = __DIR__;
    for ($__i = 0; $__i < 6; $__i++) {
        $__dir = dirname($__dir);
        if (is_file($__dir . '/app/Core/paths.php')) {
            require_once $__dir . '/app/Core/paths.php';
            break;
        }
    }
    unset($__dir, $__i);
}

try {
    require_once PUBLIC_ROOT . '/loginAuth/auth.php';
    require_once CRM_INCLUDES . '/functions.php';
    requireLogin();
    requirePermission('expenses.approve');
    if (!isAdmin()) {
        http_response_code(403);
        echo json_encode(['ok' => false, 'error' => 'Admin only']);
        exit;
    }

    $method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
    $input = $method === 'POST' ? (json_decode(file_get_contents('php://input'), true) ?: $_POST) : $_GET;
    if ($method === 'POST' && !verifyCSRFToken($input['csrf_token'] ?? '')) {
        http_response_code(403);
        echo json_encode(['ok' => false, 'error' => 'Invalid CSRF token']);
        exit;
    }
    $commit = $method === 'POST' && (string)($input['commit'] ?? '') === '1';
    $limit = max(1, min(200, (int)($input['limit'] ?? 100)));
    $allowed = ['draft', 'pending_approval', 'approved'];
    $status = isset($input['status']) ? (string)$input['status'] : '';
    if ($status !== '' && !in_array($status, $allowed, true)) {
        http_response_code(400);
        echo json_encode(['ok' => false, 'error' => 'Invalid status']);
        exit;
    }
    require_once APP_ROOT . '/Modules/Expenses/Services/LineItemBackfillService.php';
    $result = (new LineItemBackfillService(getDB()))->run($commit, $limit, $status !== '' ? [$status] : $allowed);
    echo json_encode(['ok' => true, 'commit' => $commit] + $result);
} catch (Throwable $e) {
    error_log('[line-item-backfill] ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => 'Line-item backfill failed']);
}

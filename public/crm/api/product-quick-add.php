<?php
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
require_once APP_ROOT . '/Modules/Products/Services/ProductQuickAddService.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

requireLogin();
$user = getCurrentUser();

if (!userHasPermission('billing.edit') && ($user['role'] ?? '') !== 'admin') {
    session_write_close();
    http_response_code(403);
    echo json_encode(['success' => false, 'error' => 'Billing permission required.']);
    exit;
}

$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';

try {
    $service = new ProductQuickAddService(getDB());
    if ($method === 'GET') {
        session_write_close();
        echo json_encode(['success' => true] + $service->formOptions());
        exit;
    }
    if ($method === 'POST') {
        if (!userHasPermission('products.edit') && ($user['role'] ?? '') !== 'admin') {
            session_write_close();
            http_response_code(403);
            echo json_encode(['success' => false, 'error' => 'You need permission to edit services.']);
            exit;
        }
        if (!verifyCSRFToken($_POST['csrf_token'] ?? '')) {
            session_write_close();
            http_response_code(403);
            echo json_encode(['success' => false, 'error' => 'Your session expired. Reload the page and try again.']);
            exit;
        }
        session_write_close();
        $result = $service->create($_POST, (int)($user['id'] ?? 0) ?: null);
        $response = ['success' => $result['ok'], 'message' => $result['message']];
        if (isset($result['item'])) $response['item'] = $result['item'];
        if (isset($result['duplicate'])) $response['duplicate'] = $result['duplicate'];
        if (!$result['ok'] && empty($result['duplicate'])) http_response_code(422);
        echo json_encode($response);
        exit;
    }

    session_write_close();
    http_response_code(405);
    echo json_encode(['success' => false, 'error' => 'Method not allowed.']);
} catch (Throwable $e) {
    session_write_close();
    error_log('product-quick-add.php: ' . $e->getMessage() . ' in ' . $e->getFile() . ':' . $e->getLine());
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => 'Could not save that service.']);
}

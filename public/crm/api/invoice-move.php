<?php
/**
 * Move an invoice to another job — JSON for the "Move to another job" modal on invoices/view.php and edit.php.
 * Logic: InvoiceMoveService (candidates / preview / move). Session auth + billing.edit.
 *
 * GET  ?mode=candidates&invoice_id=&q=                    jobs it could move to, with their unbilled completed visits
 * GET  ?mode=preview&invoice_id=&plan_id=&visit_id=       read-only check + from/to + recipients before/after
 * POST {mode:'move', invoice_id, plan_id, visit_id, csrf_token}   do it (JSON or form body)
 * `mode`, never `action` (the API router appends its own).
 */
declare(strict_types=1);
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

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
require_once APP_ROOT . '/Modules/Invoices/Services/InvoiceMoveService.php';

requireLogin();
$user   = getCurrentUser();
$userId = (int)($user['id'] ?? 0);

if (!userHasPermission('billing.edit') && ($user['role'] ?? '') !== 'admin') {
    http_response_code(403);
    echo json_encode(['ok' => false, 'error' => 'Billing permission required.']);
    exit;
}

$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
if ($method === 'POST') {
    $input = json_decode((string)file_get_contents('php://input'), true);
    if (!is_array($input)) {
        $input = $_POST;
    }
    if (!verifyCSRFToken((string)($input['csrf_token'] ?? ''))) {
        http_response_code(403);
        echo json_encode(['ok' => false, 'error' => 'Your session expired — reload the page and try again.']);
        exit;
    }
} elseif ($method === 'GET') {
    $input = $_GET;
} else {
    http_response_code(405);
    echo json_encode(['ok' => false, 'error' => 'GET or POST only.']);
    exit;
}
session_write_close();

$mode      = (string)($input['mode'] ?? '');
$invoiceId = (int)($input['invoice_id'] ?? 0);
$planId    = (int)($input['plan_id'] ?? 0);
$visitId   = (int)($input['visit_id'] ?? 0) ?: null;
if ($invoiceId < 1) {
    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => 'invoice_id is required.']);
    exit;
}

try {
    $svc = new InvoiceMoveService(getDB());
    if ($method === 'GET' && $mode === 'candidates') {
        echo json_encode(['ok' => true, 'jobs' => $svc->candidates($invoiceId, (string)($input['q'] ?? ''))]);
    } elseif ($method === 'GET' && $mode === 'preview') {
        echo json_encode($svc->preview($invoiceId, $planId, $visitId));
    } elseif ($method === 'POST' && $mode === 'move') {
        echo json_encode($svc->move($invoiceId, $planId, $visitId, $userId));
    } else {
        http_response_code(400);
        echo json_encode(['ok' => false, 'error' => 'Unknown mode.']);
    }
} catch (Throwable $e) {
    error_log('invoice-move.php: ' . $e->getMessage() . ' in ' . $e->getFile() . ':' . $e->getLine());
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => 'Something went wrong. Nothing was changed.']);
}

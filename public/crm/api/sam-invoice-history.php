<?php
/**
 * GET /crm/api/sam-invoice-history.php — Sam's "we did this here before" lines for a new invoice (read-only).
 *
 * Thin controller over SamInvoiceHistory (app/Modules/Sales/Services/SamInvoiceHistory.php).
 * Session auth + billing.edit. Feeds Sam's card on invoices/create.php (/crm/js/mw-sam-invoice.js).
 *
 *   ?property_id=1508&service=Cleanup  → { success, has, headline, primary{ref,date,subtotal,lines[],months_ago}, earlier[], caveat }
 *
 * No `action` parameter (the /api/ rewrite appends its own — see reference_api_router_action_param_collision).
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
require_once APP_ROOT . '/Modules/Sales/Services/SamInvoiceHistory.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

requireLogin();
$user = getCurrentUser();
session_write_close();

if (!userHasPermission('billing.edit') && ($user['role'] ?? '') !== 'admin') {
    http_response_code(403);
    echo json_encode(['success' => false, 'error' => 'Billing permission required.']);
    exit;
}
if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    http_response_code(405);
    echo json_encode(['success' => false, 'error' => 'GET only.']);
    exit;
}

$propertyId = (int)($_GET['property_id'] ?? 0);
if ($propertyId < 1) {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'property_id is required.']);
    exit;
}

try {
    $r = (new SamInvoiceHistory(getDB()))->forProperty($propertyId, mb_substr(trim((string)($_GET['service'] ?? '')), 0, 80, 'UTF-8'));
    echo json_encode(['success' => true] + $r, JSON_INVALID_UTF8_SUBSTITUTE | JSON_HEX_TAG | JSON_HEX_AMP) ?: json_encode(['success' => true, 'has' => false]);
} catch (Throwable $e) {
    error_log('sam-invoice-history.php: ' . $e->getMessage() . ' in ' . $e->getFile() . ':' . $e->getLine());
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => 'Sam could not look up earlier invoices.']);
}

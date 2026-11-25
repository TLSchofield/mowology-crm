<?php
/**
 * GET /crm/api/agreed-prices.php — the client's agreed service prices at a property (read-only).
 *
 * Thin controller over ClientAgreedPrices (app/Modules/Invoices/Services/ClientAgreedPrices.php).
 * Session auth + billing.edit. Feeds the invoice "Add from services" picker (MwServicePicker.setAgreed).
 *
 *   ?property_id=39[&company_id=7]  → { success, items[] }   items: name, unit_price, quantity, unit_type, label, …
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
require_once APP_ROOT . '/Modules/Invoices/Services/ClientAgreedPrices.php';

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
    $items = (new ClientAgreedPrices(getDB()))->forProperty($propertyId, (int)($_GET['company_id'] ?? 0) ?: null);
    echo json_encode(['success' => true, 'items' => $items]);
} catch (Throwable $e) {
    error_log('agreed-prices.php: ' . $e->getMessage() . ' in ' . $e->getFile() . ':' . $e->getLine());
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => 'Could not look up agreed prices.']);
}

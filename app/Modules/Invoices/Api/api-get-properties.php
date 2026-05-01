<?php
/**
 * API Endpoint: Get Properties for a Contact or Company
 *
 * GET /crm/invoices/api-get-properties.php?contact_id=X
 * GET /crm/invoices/api-get-properties.php?company_id=X
 *
 * Production note: properties link to contacts via site_contact_id.
 * owner_company_id / property_manager_id do NOT exist on production.
 *
 * Output: { success: bool, properties: array, count: int }
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
require_once PUBLIC_ROOT . '/crm/includes/functions.php';

requireLogin();

header('Content-Type: application/json');

$contactId = intval($_GET['contact_id'] ?? 0);
$companyId = intval($_GET['company_id'] ?? 0);

if (!$contactId && !$companyId) {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'contact_id or company_id required']);
    exit;
}

try {
    $db = getDB();

    if ($contactId) {
        $properties = getPropertiesForContact($contactId, $db);
    } else {
        $properties = getPropertiesForCompany($companyId, $db);
    }

    echo json_encode([
        'success'    => true,
        'properties' => $properties,
        'count'      => count($properties),
    ]);

} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'error'   => 'Server error loading properties',
    ]);
    error_log('api-get-properties error: ' . $e->getMessage());
}

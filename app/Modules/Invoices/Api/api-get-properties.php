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
require_once PUBLIC_ROOT . '/includes/functions.php';

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
        // Primary: look up properties by site_contact_id (production model)
        $stmt = $db->prepare("
            SELECT
                p.id,
                p.address,
                p.city,
                p.province,
                p.postal_code,
                p.property_type
            FROM properties p
            WHERE p.site_contact_id = ?
            ORDER BY p.city, p.address
        ");
        $stmt->execute([$contactId]);
    } else {
        // Company fallback: join via company_properties junction table
        $stmt = $db->prepare("
            SELECT
                p.id,
                p.address,
                p.city,
                p.province,
                p.postal_code,
                p.property_type
            FROM properties p
            JOIN company_properties cp ON cp.property_id = p.id
            WHERE cp.company_id = ?
            ORDER BY p.city, p.address
        ");
        $stmt->execute([$companyId]);
    }

    $properties = $stmt->fetchAll(PDO::FETCH_ASSOC);

    echo json_encode([
        'success'    => true,
        'properties' => $properties,
        'count'      => count($properties),
    ]);

} catch (Exception $e) {
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'error'   => 'Server error loading properties',
    ]);
    error_log('api-get-properties error: ' . $e->getMessage());
}

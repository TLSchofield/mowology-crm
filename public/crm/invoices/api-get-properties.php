<?php
/**
 * API Endpoint: Get Properties for Company
 *
 * GET /crm/invoices/api-get-properties.php?company_id=X
 * Returns properties owned or managed by the company.
 *
 * Output: { success: bool, properties: array, error?: string }
 */

declare(strict_types=1);

require_once dirname(__DIR__) . '/../loginAuth/auth.php';
require_once dirname(__DIR__) . '/../includes/functions.php';

// Require login
requireLogin();

$companyId = intval($_GET['company_id'] ?? 0);

if (!$companyId) {
    http_response_code(400);
    echo json_encode(['error' => 'company_id required']);
    exit;
}

try {
    $db = getDB();

    // Get properties where this company is the owner or manager
    $stmt = $db->prepare("
        SELECT
            p.id,
            p.address,
            p.city,
            p.property_type,
            CASE
                WHEN p.owner_company_id = ? THEN 'owner'
                WHEN p.property_manager_id = ? THEN 'manager'
                ELSE 'other'
            END as role
        FROM properties p
        WHERE p.owner_company_id = ? OR p.property_manager_id = ?
        ORDER BY p.city, p.address
    ");
    $stmt->execute([$companyId, $companyId, $companyId, $companyId]);
    $properties = $stmt->fetchAll(PDO::FETCH_ASSOC);

    http_response_code(200);
    header('Content-Type: application/json');
    echo json_encode([
        'success' => true,
        'properties' => $properties,
        'count' => count($properties)
    ]);

} catch (Exception $e) {
    http_response_code(500);
    header('Content-Type: application/json');
    echo json_encode([
        'success' => false,
        'error' => htmlspecialchars($e->getMessage())
    ]);
}
?>

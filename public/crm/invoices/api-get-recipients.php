<?php
/**
 * API Endpoint: Get Invoice Recipients
 *
 * AJAX endpoint that returns suggested invoice recipients based on property
 * and company relationships. Used in invoice creation form for recipient preview.
 *
 * POST /crm/invoices/api-get-recipients.php
 * Input: { property_id: int, company_id: int }
 * Output: { success: bool, recipients: array, error?: string }
 */

declare(strict_types=1);

require_once dirname(__DIR__) . '/../loginAuth/auth.php';
require_once dirname(__DIR__) . '/../includes/functions.php';
require_once dirname(__DIR__) . '/../includes/invoice-routing.php';

// Require JSON content type
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['error' => 'Method not allowed']);
    exit;
}

// Check for JSON content type
$contentType = $_SERVER['CONTENT_TYPE'] ?? '';
if (strpos($contentType, 'application/json') === false) {
    http_response_code(400);
    echo json_encode(['error' => 'Content-Type must be application/json']);
    exit;
}

// Require login
requireLogin();

// Parse JSON input
$json = json_decode(file_get_contents('php://input'), true);
if (!$json) {
    http_response_code(400);
    echo json_encode(['error' => 'Invalid JSON']);
    exit;
}

$propertyId = intval($json['property_id'] ?? 0);
$companyId = intval($json['company_id'] ?? 0);

// Validate input
if (!$propertyId || !$companyId) {
    http_response_code(400);
    echo json_encode(['error' => 'property_id and company_id required']);
    exit;
}

try {
    // Determine recipients
    $recipients = determineInvoiceRecipients($propertyId, $companyId);

    // Return JSON response
    http_response_code(200);
    header('Content-Type: application/json');
    echo json_encode([
        'success' => true,
        'recipients' => $recipients,
        'count' => count($recipients)
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

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
require_once CRM_INCLUDES . '/invoice-routing.php';

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
$companyId  = intval($json['company_id'] ?? 0);
$contactId  = intval($json['contact_id'] ?? 0);

// Validate input
if (!$propertyId) {
    http_response_code(400);
    echo json_encode(['error' => 'property_id required']);
    exit;
}

try {
    $recipients = [];

    if ($companyId) {
        // Standard path: company-based routing
        $recipients = determineInvoiceRecipients($propertyId, $companyId);
    }

    // Fallback: load the property's site contact directly (contacts-based plans)
    if (empty($recipients)) {
        $db = getDB();
        // Try property's site_contact_id, then the passed contact_id
        $fallbackStmt = $db->prepare("
            SELECT con.id as contact_id,
                   CONCAT(con.first_name, ' ', con.last_name) as contact_name,
                   con.email,
                   con.mobile,
                   con.receive_sms
            FROM properties p
            JOIN contacts con ON con.id = COALESCE(p.site_contact_id, ?)
            WHERE p.id = ?
            LIMIT 1
        ");
        $fallbackStmt->execute([$contactId ?: 0, $propertyId]);
        $contact = $fallbackStmt->fetch(PDO::FETCH_ASSOC);

        if ($contact && $contact['email']) {
            $recipients = [[
                'contact_id'   => $contact['contact_id'],
                'contact_name' => $contact['contact_name'],
                'contact_role' => 'primary_recipient',
                'email_address'=> $contact['email'],
                'receive_sms'  => (bool)$contact['receive_sms'],
                'phone'        => $contact['mobile'] ?? null,
            ]];
        }
    }

    // Determine recipients
    // (already set above)

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

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
$clientId   = intval($json['client_id'] ?? 0);

// Validate input: need at least one anchor to resolve recipients from.
if (!$propertyId && !$companyId && !$clientId) {
    http_response_code(400);
    echo json_encode(['error' => 'property_id, company_id, or client_id required']);
    exit;
}

try {
    $recipients = [];

    // ── Client/Account model path: recipients are the account's people ──
    // Pull from client_contacts so an account is invoiceable without a property
    // (e.g. a strata billed via its council member / property manager).
    if ($clientId) {
        $db = getDB();
        try {
            // Resolve from the account's own people AND, when the account is
            // managed by a firm (e.g. a strata managed by a PM company), the
            // managing firm's people too — so invoices route to the manager's
            // accounts contact. Manager contacts are listed first.
            $ccStmt = $db->prepare("
                SELECT con.id                                     AS contact_id,
                       CONCAT(con.first_name, ' ', con.last_name) AS contact_name,
                       con.email, con.mobile, con.receive_sms,
                       cc.role         AS contact_role,
                       cc.is_primary, cc.receives_invoices,
                       (cc.client_id <> ?) AS via_manager
                FROM client_contacts cc
                JOIN contacts con ON con.id = cc.contact_id
                WHERE cc.client_id = ?
                   OR cc.client_id = (SELECT managed_by_client_id FROM clients WHERE id = ?)
                ORDER BY via_manager DESC, cc.receives_invoices DESC, cc.is_primary DESC, con.first_name
            ");
            $ccStmt->execute([$clientId, $clientId, $clientId]);
            $seenContact = [];
            foreach ($ccStmt->fetchAll(PDO::FETCH_ASSOC) as $cc) {
                if (empty($cc['email'])) {
                    continue; // can't send an invoice without an email
                }
                if (isset($seenContact[(int)$cc['contact_id']])) {
                    continue; // a person linked to both account and manager — once
                }
                $seenContact[(int)$cc['contact_id']] = true;
                $recipients[] = [
                    'contact_id'    => (int)$cc['contact_id'],
                    'contact_name'  => $cc['contact_name'] . (!empty($cc['via_manager']) ? ' (Manager)' : ''),
                    'contact_role'  => $cc['contact_role'] ?: 'primary_recipient',
                    'email_address' => $cc['email'],
                    'receive_sms'   => (bool)$cc['receive_sms'],
                    'phone'         => $cc['mobile'] ?? null,
                    'preselect'     => (bool)$cc['receives_invoices'],
                ];
            }
        } catch (PDOException $e) {
            // clients/client_contacts absent (pre-Phase-1) — fall through to legacy.
        }
    }

    if (empty($recipients) && $companyId && $propertyId) {
        // Legacy path: company-based routing (needs a property).
        $recipients = determineInvoiceRecipients($propertyId, $companyId);
    }

    // Fallback: load the property's site contact directly (contacts-based plans)
    if (empty($recipients) && $propertyId) {
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

    // ── PM-MANAGED BILLING PRECEDENCE ──────────────────────────────────────
    // If the property is managed by a firm, invoices bill the MANAGEMENT
    // COMPANY's accounts contact (honoring its invoice_routing_method), NOT the
    // on-site/property contact. The management billing recipient becomes the
    // preselected primary; anything resolved above stays in the list but
    // unchecked (available as a CC). This is the documented intent of the
    // contract "PM-Managed Billing" screen and fixes the case where the form
    // defaulted to the property manager *person* instead of the firm's accounts.
    if ($propertyId) {
        $pmRecipients = resolveManagementBillingRecipient($propertyId);
        if (!empty($pmRecipients)) {
            $pmContactIds = array_column($pmRecipients, 'contact_id');
            $ccRecipients = [];
            foreach ($recipients as $r) {
                // Drop duplicates of the PM contacts; demote the rest to unchecked.
                if (in_array((int)($r['contact_id'] ?? 0), array_map('intval', $pmContactIds), true)) {
                    continue;
                }
                $r['preselect'] = false;
                $ccRecipients[] = $r;
            }
            $recipients = array_merge($pmRecipients, $ccRecipients);
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

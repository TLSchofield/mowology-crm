<?php
/**
 * Client Rebook API
 *
 * POST /customer/api/rebook.php
 *   token   - contacts.portal_token
 *   visit_id - job_visits.id (the visit to "repeat")
 *   notes   - optional free-text note from the customer
 *
 * Creates a new quote_request pre-populated from the prior visit's property
 * and service type. Admin sees it in the quote-requests pipeline and can
 * convert to a new quote/plan.
 */
declare(strict_types=1);

header('Content-Type: application/json; charset=utf-8');
header('X-Content-Type-Options: nosniff');

$__dir = __DIR__;
for ($__i = 0; $__i < 5; $__i++) {
    $__dir = dirname($__dir);
    if (is_file($__dir . '/app/Core/paths.php')) {
        require_once $__dir . '/app/Core/paths.php';
        break;
    }
}
unset($__dir, $__i);

require_once PUBLIC_ROOT . '/app_config/config.php';

try {
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        throw new Exception('POST required');
    }

    $token    = trim($_POST['token'] ?? '');
    $visitId  = (int)($_POST['visit_id'] ?? 0);
    $notes    = trim($_POST['notes'] ?? '');

    if ($token === '' || $visitId <= 0) {
        throw new Exception('Missing token or visit_id');
    }

    $db = getDB();

    // Resolve the visit → plan → property → contact, and verify ownership
    $stmt = $db->prepare("
        SELECT
            jv.id AS visit_id, jv.plan_id, jv.scheduled_date,
            jp.service_type, jp.title AS plan_title,
            pr.id AS property_id, pr.address AS property_address,
            c.id AS contact_id, c.first_name, c.last_name, c.email, c.phone
        FROM job_visits jv
        JOIN job_plans jp ON jp.id = jv.plan_id
        JOIN properties pr ON pr.id = jp.property_id
        JOIN contacts c ON c.id = pr.site_contact_id
        WHERE jv.id = ?
          AND c.portal_token = ?
        LIMIT 1
    ");
    $stmt->execute([$visitId, $token]);
    $visit = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$visit) {
        throw new Exception('Visit not found for this client');
    }

    // Deduplicate — if a quote_request for this contact+property+service already exists
    // in the last 7 days with status='new' or 'reviewing', reuse it.
    $dupStmt = $db->prepare("
        SELECT id FROM quote_requests
        WHERE contact_id = ? AND property_id = ?
          AND service_types LIKE ?
          AND status IN ('new', 'reviewing')
          AND created_at >= DATE_SUB(NOW(), INTERVAL 7 DAY)
        ORDER BY created_at DESC LIMIT 1
    ");
    $svcLike = '%' . ($visit['service_type'] ?? '') . '%';
    $dupStmt->execute([(int)$visit['contact_id'], (int)$visit['property_id'], $svcLike]);
    $dupRow = $dupStmt->fetch(PDO::FETCH_ASSOC);

    if ($dupRow) {
        echo json_encode([
            'success' => true,
            'duplicate' => true,
            'request_id' => (int)$dupRow['id'],
            'message' => 'You already have a pending request — we\'ll be in touch soon!',
        ]);
        exit;
    }

    // Build project_description
    $customerName   = trim(($visit['first_name'] ?? '') . ' ' . ($visit['last_name'] ?? ''));
    $serviceLabel   = $visit['plan_title'] ?: ($visit['service_type'] ?? 'service');
    $priorDateStr   = $visit['scheduled_date'] ? date('F j, Y', strtotime($visit['scheduled_date'])) : '';

    $description  = "REBOOK REQUEST — {$customerName} requested a repeat of a previous service.\n\n";
    $description .= "Prior visit: {$serviceLabel}";
    if ($priorDateStr) $description .= " (performed {$priorDateStr})";
    $description .= "\nProperty: " . ($visit['property_address'] ?? 'N/A');
    $description .= "\nVisit ID reference: #{$visitId}";
    if ($notes !== '') {
        $description .= "\n\nCustomer note:\n" . $notes;
    }

    // Insert quote_request
    $ipAddress = $_SERVER['REMOTE_ADDR'] ?? null;
    $userAgent = substr($_SERVER['HTTP_USER_AGENT'] ?? '', 0, 500);

    $insertStmt = $db->prepare("
        INSERT INTO quote_requests
            (contact_id, property_id, service_types, urgency,
             project_description, status, source, ip_address, user_agent, created_at)
        VALUES (?, ?, ?, ?, ?, 'new', 'portal_rebook', ?, ?, NOW())
    ");
    $insertStmt->execute([
        (int)$visit['contact_id'],
        (int)$visit['property_id'],
        $visit['service_type'] ?? 'general',
        'soon', // client already served once — they want it again soon
        $description,
        $ipAddress ? substr($ipAddress, 0, 45) : null,
        $userAgent ?: null,
    ]);
    $newRequestId = (int)$db->lastInsertId();

    // Fire-and-forget admin notification (optional, non-fatal)
    try {
        $messagingFile = PUBLIC_ROOT . '/crm/includes/messaging.php';
        if (!function_exists('sendCrmEmail') && is_file($messagingFile)) {
            require_once $messagingFile;
        }
        if (function_exists('sendCrmEmail')) {
            $adminEmail = defined('ADMIN_EMAIL') ? ADMIN_EMAIL : 'office@mowology.ca';
            $siteUrl    = defined('SITE_URL') ? SITE_URL : 'https://mowology.ca';
            $subject    = 'Client rebook request — ' . $customerName;
            $body = '<h2>New rebook request from ' . htmlspecialchars($customerName) . '</h2>
<p><strong>Service:</strong> ' . htmlspecialchars($serviceLabel) . '</p>
<p><strong>Property:</strong> ' . htmlspecialchars($visit['property_address'] ?? '') . '</p>
' . ($notes !== '' ? '<p><strong>Note:</strong><br>' . nl2br(htmlspecialchars($notes)) . '</p>' : '') . '
<p><a href="' . $siteUrl . '/crm/products/quote-requests.php">Review in CRM →</a></p>';
            @sendCrmEmail($adminEmail, $subject, $body, 'Mowology Admin');
        }
    } catch (Throwable $e) { /* non-fatal */ }

    echo json_encode([
        'success'    => true,
        'request_id' => $newRequestId,
        'message'    => 'Thanks! We\'ll be in touch soon to schedule this.',
    ]);

} catch (Throwable $e) {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
}

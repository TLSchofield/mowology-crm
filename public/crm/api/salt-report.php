<?php
/**
 * Salt / Winter Service Report API
 *
 * POST actions:
 *   generate      — Generate (or re-generate) the PDF for a visit
 *   email_pm      — Email the PDF to the property contact
 *   attach_invoice — Link the report to an invoice
 *
 * All actions require admin or manager role + CSRF token.
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
require_once CRM_INCLUDES . '/functions.php';
require_once APP_ROOT    . '/Services/CrmFunctions.php';

requireLogin();
$user = getCurrentUser();

header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'error' => 'POST required']);
    exit;
}

if (!in_array($user['role'] ?? '', ['admin', 'manager'], true)) {
    http_response_code(403);
    echo json_encode(['success' => false, 'error' => 'Admin or manager role required']);
    exit;
}

if (!verifyCSRFToken($_POST['csrf_token'] ?? '')) {
    http_response_code(403);
    echo json_encode(['success' => false, 'error' => 'Invalid CSRF token']);
    exit;
}

$action  = trim($_POST['action'] ?? '');
$visitId = (int)($_POST['visit_id'] ?? 0);

if ($visitId <= 0) {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'visit_id required']);
    exit;
}

$db = getDB();

// ── action: generate ─────────────────────────────────────────────────────────
if ($action === 'generate') {
    require_once APP_ROOT . '/Services/Salt/SaltReportPdfGenerator.php';

    $siteUrl    = defined('SITE_URL') ? SITE_URL : 'https://mowology.ca';
    $forceRegen = !empty($_POST['force']);
    try {
        $gen    = new SaltReportPdfGenerator($db, PUBLIC_ROOT, $siteUrl, (int)($user['id'] ?? 0));
        $result = $gen->generate($visitId, $forceRegen);
    } catch (\Throwable $e) {
        error_log('SaltReportPdfGenerator error: ' . $e->getMessage() . ' in ' . $e->getFile() . ':' . $e->getLine());
        $result = ['success' => false, 'error' => $e->getMessage(), 'error_file' => basename($e->getFile()), 'error_line' => $e->getLine()];
    }

    echo json_encode($result);
    exit;
}

// ── action: email_pm ─────────────────────────────────────────────────────────
if ($action === 'email_pm') {
    require_once APP_ROOT . '/Services/Messaging/EmailHelper.php';

    // Load report + contact details
    $stmt = $db->prepare("
        SELECT srr.pdf_path, srr.report_number,
               jv.scheduled_date,
               pr.address AS property_address, pr.city AS property_city,
               c.email AS contact_email,
               CONCAT(c.first_name, ' ', c.last_name) AS contact_name
        FROM salt_run_reports srr
        JOIN job_visits jv ON jv.id = srr.visit_id
        JOIN job_plans jp ON jp.id = jv.plan_id
        LEFT JOIN properties pr ON pr.id = jp.property_id
        LEFT JOIN contacts c ON c.id = pr.site_contact_id
        WHERE srr.visit_id = ?
    ");
    $stmt->execute([$visitId]);
    $report = $stmt->fetch(\PDO::FETCH_ASSOC);

    if (!$report) {
        echo json_encode(['success' => false, 'error' => 'Report not found — generate it first']);
        exit;
    }
    if (empty($report['pdf_path'])) {
        echo json_encode(['success' => false, 'error' => 'PDF not yet generated — run generate first']);
        exit;
    }
    if (empty($report['contact_email'])) {
        echo json_encode(['success' => false, 'error' => 'No email address on file for this property\'s contact']);
        exit;
    }

    $pdfAbs = (defined('STORAGE_ROOT') ? STORAGE_ROOT : APP_ROOT . '/Storage')
        . '/pdfs/salt-reports/' . basename($report['pdf_path']);

    if (!file_exists($pdfAbs)) {
        echo json_encode(['success' => false, 'error' => 'PDF file not found on disk — re-generate']);
        exit;
    }

    $serviceDate = date('F j, Y', strtotime($report['scheduled_date']));
    $propertyStr = trim($report['property_address'] . ', ' . $report['property_city']);
    $subject     = 'Winter Service Record — ' . $propertyStr . ' — ' . $serviceDate;

    $body = '<div style="font-family:Arial,sans-serif;max-width:600px;margin:0 auto;">';
    $body .= '<div style="background:#0D3B2E;padding:20px 24px;">';
    $body .= '<span style="color:#7FD858;font-size:20px;font-weight:bold;">Mowology</span>';
    $body .= '</div>';
    $body .= '<div style="padding:28px 24px;background:#fff;">';
    $body .= '<p style="color:#333;font-size:15px;">Dear ' . htmlspecialchars($report['contact_name'] ?: 'Property Manager') . ',</p>';
    $body .= '<p style="color:#333;margin-top:12px;">Please find attached your <strong>Winter Service Record</strong> for:</p>';
    $body .= '<ul style="color:#333;margin:12px 0;">';
    $body .= '<li><strong>Property:</strong> ' . htmlspecialchars($propertyStr) . '</li>';
    $body .= '<li><strong>Service Date:</strong> ' . htmlspecialchars($serviceDate) . '</li>';
    $body .= '<li><strong>Report No.:</strong> ' . htmlspecialchars($report['report_number']) . '</li>';
    $body .= '</ul>';
    $body .= '<p style="color:#555;font-size:13px;margin-top:16px;">This document contains the official weather decision record, GPS verification, photos, and service details for your records. It is available at any time through your client portal.</p>';
    $body .= '<p style="color:#555;font-size:13px;margin-top:12px;">If you have any questions, please contact us at <a href="tel:7788469273">(778) 846-9273</a>.</p>';
    $body .= '<p style="color:#333;margin-top:20px;">Thank you for choosing Mowology.</p>';
    $body .= '</div>';
    $body .= '<div style="background:#f5f5f5;padding:12px 24px;font-size:11px;color:#999;">';
    $body .= 'Mowology Landscaping &bull; Vancouver, BC &bull; <a href="https://mowology.ca">mowology.ca</a>';
    $body .= '</div></div>';

    $sent = sendCrmEmail($report['contact_email'], $subject, $body, $pdfAbs);

    if ($sent) {
        $db->prepare("
            UPDATE salt_run_reports
            SET pm_email_sent_at = NOW(), pm_email_recipient = ?, pm_email_status = 'sent'
            WHERE visit_id = ?
        ")->execute([$report['contact_email'], $visitId]);

        echo json_encode(['success' => true, 'sent_to' => $report['contact_email']]);
    } else {
        $db->prepare("
            UPDATE salt_run_reports SET pm_email_status = 'failed' WHERE visit_id = ?
        ")->execute([$visitId]);
        echo json_encode(['success' => false, 'error' => 'Email send failed — check server mail logs']);
    }
    exit;
}

// ── action: attach_invoice ────────────────────────────────────────────────────
if ($action === 'attach_invoice') {
    $invoiceId = (int)($_POST['invoice_id'] ?? 0);
    if ($invoiceId <= 0) {
        echo json_encode(['success' => false, 'error' => 'invoice_id required']);
        exit;
    }

    // Verify invoice exists
    $invStmt = $db->prepare("SELECT id FROM invoices WHERE id = ?");
    $invStmt->execute([$invoiceId]);
    if (!$invStmt->fetch()) {
        echo json_encode(['success' => false, 'error' => 'Invoice not found']);
        exit;
    }

    // Get report
    $repStmt = $db->prepare("SELECT id, pdf_path, report_number FROM salt_run_reports WHERE visit_id = ?");
    $repStmt->execute([$visitId]);
    $rep = $repStmt->fetch(\PDO::FETCH_ASSOC);

    if (!$rep || empty($rep['pdf_path'])) {
        echo json_encode(['success' => false, 'error' => 'No PDF found — generate the report first']);
        exit;
    }

    $serviceDate = '';
    $jvStmt = $db->prepare("SELECT scheduled_date FROM job_visits WHERE id = ?");
    $jvStmt->execute([$visitId]);
    $jv = $jvStmt->fetch(\PDO::FETCH_ASSOC);
    if ($jv) $serviceDate = ' — ' . date('M j, Y', strtotime($jv['scheduled_date']));

    // Avoid duplicate attachment for same invoice+report
    $dupCheck = $db->prepare("SELECT id FROM invoice_attachments WHERE invoice_id = ? AND document_type = 'salt_report' AND document_id = ?");
    $dupCheck->execute([$invoiceId, (int)$rep['id']]);
    if ($dupCheck->fetch()) {
        echo json_encode(['success' => true, 'invoice_id' => $invoiceId, 'report_number' => $rep['report_number'], 'note' => 'Already attached']);
        exit;
    }

    $db->prepare("
        INSERT INTO invoice_attachments (invoice_id, document_type, document_id, pdf_path, label, attached_by)
        VALUES (?, 'salt_report', ?, ?, ?, ?)
    ")->execute([
        $invoiceId,
        (int)$rep['id'],
        $rep['pdf_path'],
        'Winter Service Record' . $serviceDate . ' (' . $rep['report_number'] . ')',
        (int)($user['id'] ?? 0),
    ]);

    $db->prepare("
        UPDATE salt_run_reports SET invoice_id = ?, invoice_attached_at = NOW() WHERE visit_id = ?
    ")->execute([$invoiceId, $visitId]);

    echo json_encode(['success' => true, 'invoice_id' => $invoiceId, 'report_number' => $rep['report_number']]);
    exit;
}

http_response_code(400);
echo json_encode(['success' => false, 'error' => 'Unknown action: ' . $action]);

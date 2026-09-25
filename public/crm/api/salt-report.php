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

    // Load report + contact details + weather decision
    $stmt = $db->prepare("
        SELECT srr.pdf_path, srr.report_number,
               jv.scheduled_date,
               pr.address AS property_address, pr.city AS property_city,
               c.email AS contact_email,
               CONCAT(c.first_name, ' ', c.last_name) AS contact_name,
               swd.overnight_low_c, swd.trigger_threshold_c,
               swd.weather_condition, swd.data_source,
               swd.decision_at, swd.source_url
        FROM salt_run_reports srr
        JOIN job_visits jv ON jv.id = srr.visit_id
        JOIN job_plans jp ON jp.id = jv.plan_id
        LEFT JOIN properties pr ON pr.id = jp.property_id
        LEFT JOIN contacts c ON c.id = pr.site_contact_id
        LEFT JOIN salt_weather_decisions swd ON swd.visit_id = jv.id
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

    $body  = '<div style="font-family:Arial,sans-serif;max-width:600px;margin:0 auto;">';
    $body .= '<div style="background:#0D3B2E;padding:20px 24px;">';
    $body .= '<span style="color:#7FD858;font-size:20px;font-weight:bold;">Mowology</span>';
    $body .= '<span style="color:#a0c8b8;font-size:12px;margin-left:12px;">Winter Service Record</span>';
    $body .= '</div>';

    $body .= '<div style="padding:28px 24px;background:#fff;">';
    $body .= '<p style="color:#333;font-size:15px;">Dear ' . htmlspecialchars($report['contact_name'] ?: 'Property Manager') . ',</p>';
    $body .= '<p style="color:#333;margin-top:12px;">Please find attached your <strong>Winter Service Record</strong> for the following property. The PDF document includes GPS tracking records, photo evidence, and the full chain of custody.</p>';

    // Service summary table
    $body .= '<table style="width:100%;border-collapse:collapse;margin:18px 0;font-size:13px;">';
    $body .= '<tr><td style="padding:7px 10px;background:#f5f5f5;font-weight:bold;color:#444;width:160px;border:1px solid #e0e0e0;">Property</td><td style="padding:7px 10px;border:1px solid #e0e0e0;">' . htmlspecialchars($propertyStr) . '</td></tr>';
    $body .= '<tr><td style="padding:7px 10px;background:#f5f5f5;font-weight:bold;color:#444;border:1px solid #e0e0e0;">Service Date</td><td style="padding:7px 10px;border:1px solid #e0e0e0;">' . htmlspecialchars($serviceDate) . '</td></tr>';
    $body .= '<tr><td style="padding:7px 10px;background:#f5f5f5;font-weight:bold;color:#444;border:1px solid #e0e0e0;">Report No.</td><td style="padding:7px 10px;border:1px solid #e0e0e0;">' . htmlspecialchars($report['report_number']) . '</td></tr>';
    $body .= '</table>';

    // Weather snapshot
    if ($report['overnight_low_c'] !== null) {
        $tempLow   = htmlspecialchars($report['overnight_low_c']);
        $threshold = htmlspecialchars($report['trigger_threshold_c']);
        $condition = htmlspecialchars($report['weather_condition'] ?? '');
        $source    = htmlspecialchars($report['data_source'] ?? 'Environment Canada');
        $decidedAt = $report['decision_at'] ? htmlspecialchars(date('F j, Y \a\t g:i A', strtotime($report['decision_at']))) : '';

        $body .= '<div style="background:#E3F2FD;border:2px solid #1565C0;border-radius:6px;padding:16px 18px;margin:18px 0;">';
        $body .= '<div style="font-size:11px;letter-spacing:1px;text-transform:uppercase;color:#1565C0;font-weight:bold;margin-bottom:8px;">Official Weather Decision Record</div>';
        $body .= '<p style="font-size:13px;color:#0D3B2E;font-style:italic;border-left:3px solid #1565C0;padding-left:12px;margin:0 0 12px;">';
        $body .= 'An overnight low of ' . $tempLow . '&deg;C was forecast, meeting the &le;' . $threshold . '&deg;C service threshold. Winter service was authorized and performed.';
        $body .= '</p>';
        $body .= '<table style="width:100%;border-collapse:collapse;font-size:12px;">';
        $body .= '<tr><td style="padding:4px 8px;color:#555;width:150px;">Forecast overnight low</td><td style="padding:4px 8px;font-weight:bold;color:#333;">' . $tempLow . '&deg;C</td></tr>';
        $body .= '<tr><td style="padding:4px 8px;color:#555;">Service threshold</td><td style="padding:4px 8px;font-weight:bold;color:#333;">&le;' . $threshold . '&deg;C</td></tr>';
        if ($condition) {
            $body .= '<tr><td style="padding:4px 8px;color:#555;">Condition</td><td style="padding:4px 8px;color:#333;">' . $condition . '</td></tr>';
        }
        $body .= '<tr><td style="padding:4px 8px;color:#555;">Data source</td><td style="padding:4px 8px;color:#333;">' . $source . '</td></tr>';
        if ($decidedAt) {
            $body .= '<tr><td style="padding:4px 8px;color:#555;">Decision captured</td><td style="padding:4px 8px;color:#333;">' . $decidedAt . '</td></tr>';
        }
        $body .= '</table>';
        $body .= '<p style="font-size:10px;color:#555;margin:10px 0 0;">This weather data was captured automatically at the time of the service decision and is stored immutably in our records. The full Environment Canada API response is retained on file.</p>';
        $body .= '</div>';
    }

    $body .= '<p style="color:#555;font-size:13px;margin-top:4px;">The attached PDF is your official service record and may be used for insurance, strata, or liability documentation purposes.</p>';
    $body .= '<p style="color:#555;font-size:13px;margin-top:10px;">If you have any questions, please contact us at <a href="tel:7788469273" style="color:#2D8659;">(778) 846-9273</a>.</p>';
    $body .= '<p style="color:#333;margin-top:20px;">Thank you for choosing Mowology.</p>';
    $body .= '</div>';

    $body .= '<div style="background:#f5f5f5;padding:12px 24px;font-size:11px;color:#999;">';
    $body .= 'Mowology Landscaping &bull; Vancouver, BC &bull; <a href="https://mowology.ca" style="color:#2D8659;">mowology.ca</a>';
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

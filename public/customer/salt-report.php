<?php
/**
 * Customer Portal — Winter Service Report Viewer
 * Token-based (no login required).
 *
 * URL: /customer/salt-report.php?token=PORTALTOKEN&visit_id=42
 * Token verified against contacts.portal_token.
 * Streams the PDF inline.
 */

header('X-Content-Type-Options: nosniff');
header('X-Frame-Options: SAMEORIGIN');

require_once dirname(__DIR__) . '/app_config/config.php';

$db    = getDB();
$error = '';

$token   = trim($_GET['token'] ?? '');
$visitId = isset($_GET['visit_id']) ? (int)$_GET['visit_id'] : 0;

if (!$token || !$visitId) {
    $error = 'Invalid link. Please use the link from your client portal or email.';
}

if (!$error) {
    // Verify portal_token belongs to a contact who owns this visit's property
    $stmt = $db->prepare("
        SELECT c.id AS contact_id, srr.pdf_path, srr.report_number,
               jv.scheduled_date,
               pr.address AS property_address, pr.city AS property_city
        FROM contacts c
        JOIN properties pr ON pr.site_contact_id = c.id
        JOIN job_plans jp ON jp.property_id = pr.id
        JOIN job_visits jv ON jv.plan_id = jp.id
        JOIN salt_run_reports srr ON srr.visit_id = jv.id
        WHERE c.portal_token = ?
          AND jv.id = ?
          AND srr.portal_available_at IS NOT NULL
          AND srr.pdf_path IS NOT NULL
        LIMIT 1
    ");
    $stmt->execute([$token, $visitId]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$row) {
        $error = 'This report is not available or the link has expired. Please return to your portal.';
    }
}

if ($error) {
    http_response_code(403);
    header('Content-Type: text/html; charset=utf-8');
    echo '<!DOCTYPE html><html><head><meta charset="utf-8"><title>Not Available</title>';
    echo '<style>body{font-family:Arial,sans-serif;text-align:center;padding:60px;color:#333;}';
    echo '.msg{max-width:480px;margin:0 auto;background:#fff4e5;border:1px solid #f0a500;';
    echo 'border-radius:8px;padding:30px;}</style></head><body>';
    echo '<div class="msg"><h2>Report Not Available</h2>';
    echo '<p>' . htmlspecialchars($error) . '</p>';
    echo '<p style="margin-top:20px;"><a href="/customer/portal.php?token=' . urlencode($token) . '">Return to Portal</a></p>';
    echo '</div></body></html>';
    exit;
}

// Resolve absolute PDF path
$pdfRelative = $row['pdf_path']; // e.g. storage/pdfs/salt-reports/salt_42_v1.pdf
$pdfAbs = (defined('STORAGE_ROOT') ? STORAGE_ROOT : dirname(__DIR__) . '/app/Storage')
    . '/pdfs/salt-reports/' . basename($pdfRelative);

if (!file_exists($pdfAbs)) {
    http_response_code(404);
    echo '<p>PDF not found on disk. Please contact Mowology to regenerate the document.</p>';
    exit;
}

// Stream PDF inline
$filename   = 'winter-service-' . date('Y-m-d', strtotime($row['scheduled_date'])) . '.pdf';
$filesize   = filesize($pdfAbs);

header('Content-Type: application/pdf');
header('Content-Disposition: inline; filename="' . $filename . '"');
header('Content-Length: ' . $filesize);
header('Cache-Control: private, max-age=3600');
header('X-Report-Number: ' . ($row['report_number'] ?? ''));

readfile($pdfAbs);
exit;

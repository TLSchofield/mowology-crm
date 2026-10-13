<?php
/**
 * CRM Document Download / Preview
 *
 * Streams a PDF stored under STORAGE_ROOT/pdfs/{invoices,quotes,salt-reports,trip-reports,pow,zone-report}
 * to the browser (inline). Nothing else (StoredPdfPath).
 * Accepts paths of the form "storage/pdfs/<subdir>/<file>.pdf" as stored
 * in salt_run_reports.pdf_path, job_visits.pdf_path, etc.
 *
 * GET  /crm/documents/download.php?path=storage/pdfs/salt-reports/salt_42_v1.pdf
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
requireLogin();

$user = getCurrentUser();
// Stored PDFs are invoices, salt reports and visit records — office documents (Fable review
// 2026-10-12: any 'employee' login could read every one by path). Same gate as billing pages.
requirePermission('billing.view');

$rawPath = trim($_GET['path'] ?? '');
if (!$rawPath) {
    http_response_code(400);
    exit('No path specified.');
}

// Fable #8 (2026-10-12): only PDFs in the document folders — never anything else in storage
// (token caches, schema snapshots, supplier/company documents, backups).
require_once APP_ROOT . '/Core/StoredPdfPath.php';
$realAbs = StoredPdfPath::resolve($rawPath, STORAGE_ROOT);
if ($realAbs === null) {
    http_response_code(404);
    exit('File not found.');
}

$mimeType = 'application/pdf';

$filename = basename($realAbs);

header('Content-Type: ' . $mimeType);
header('Content-Disposition: inline; filename="' . addslashes($filename) . '"');
header('Content-Length: ' . filesize($realAbs));
header('Cache-Control: private, max-age=3600');
header('X-Content-Type-Options: nosniff');

readfile($realAbs);
exit;

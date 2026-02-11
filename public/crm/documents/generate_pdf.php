<?php
/**
 * PDF Document Generator Endpoint
 *
 * GET:  ?type=quote&id=X&action=download  — stream cached/generated PDF
 * POST: ?type=quote&id=X&action=generate  — regenerate PDF (CSRF protected)
 *
 * Supports type=quote or type=invoice.
 */
require_once dirname(__DIR__) . '/../loginAuth/auth.php';
require_once dirname(__DIR__) . '/includes/functions.php';

requireLogin();
$user = getCurrentUser();

$type = $_GET['type'] ?? '';
$id = intval($_GET['id'] ?? 0);
$action = $_REQUEST['action'] ?? 'download';

// Validate inputs
if (!in_array($type, ['quote', 'invoice'])) {
    http_response_code(400);
    die('Invalid document type.');
}
if (!$id) {
    http_response_code(400);
    die('Missing document ID.');
}

// Load PDF dependencies (this endpoint is PDF-specific)
require_once dirname(__DIR__) . '/includes/pdf_bootstrap.php';
require_once dirname(__DIR__) . '/includes/PdfGenerator.php';

$generator = new PdfGenerator();

// --- REGENERATE ---
if ($action === 'generate') {
    if ($_SERVER['REQUEST_METHOD'] !== 'POST' || !verifyCSRFToken($_POST['csrf_token'] ?? '')) {
        http_response_code(403);
        die('Invalid request.');
    }

    $result = ($type === 'quote')
        ? $generator->generateQuotePdf($id)
        : $generator->generateInvoicePdf($id);

    if ($result['success']) {
        logActivityExtended(
            $user['id'],
            'PDF generated',
            ucfirst($type) . " PDF v{$result['version']} generated",
            null, null,
            $type === 'quote' ? $id : null,
            $type === 'invoice' ? $id : null
        );

        $returnUrl = ($type === 'quote')
            ? "../quotes/view.php?id={$id}&pdf_generated=1"
            : "../invoices/view.php?id={$id}&pdf_generated=1";

        header("Location: {$returnUrl}");
        exit;
    } else {
        http_response_code(500);
        die('PDF generation failed: ' . htmlspecialchars($result['error'] ?? 'Unknown error'));
    }
}

// --- DOWNLOAD ---
if ($action === 'download') {
    $path = $generator->getPdfPath($type, $id);

    if (!$path) {
        // Auto-generate if no cached PDF exists
        $result = ($type === 'quote')
            ? $generator->generateQuotePdf($id)
            : $generator->generateInvoicePdf($id);

        if (!$result['success']) {
            http_response_code(500);
            die('Could not generate PDF: ' . htmlspecialchars($result['error'] ?? 'Unknown error'));
        }

        $path = $result['path'];
        $filename = $result['filename'];
    } else {
        $filename = basename($path);
    }

    logActivityExtended(
        $user['id'],
        'PDF downloaded',
        ucfirst($type) . " PDF downloaded",
        null, null,
        $type === 'quote' ? $id : null,
        $type === 'invoice' ? $id : null
    );

    $generator->streamPdf($path, $filename);
}

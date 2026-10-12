<?php
/**
 * Customer-side Quote PDF download (Fable review 2026-10-12: the signing page linked the CRM endpoint, which sends clients to a login screen).
 * Token-based (no login required). Streams the cached PDF, regenerating it if missing.
 *
 * URL:
 *   GET /customer/api/quote-pdf.php?token=ABC            → download (attachment)
 *   GET /customer/api/quote-pdf.php?token=ABC&inline=1   → open in browser (print-friendly)
 */

header('X-Content-Type-Options: nosniff');
header('X-Frame-Options: SAMEORIGIN');

// Boot the app so we get PROJECT_ROOT / APP_ROOT / PUBLIC_ROOT etc.
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

require_once APP_ROOT . '/Core/config.php';

$token = trim($_GET['token'] ?? '');
$inline = !empty($_GET['inline']);

if ($token === '') {
    http_response_code(400);
    die('Missing token.');
}

try {
    $db = getDB();

    // Validate token and load quote id/number
    $stmt = $db->prepare("
        SELECT id, quote_number
        FROM quotes
        WHERE access_token = ?
          AND token_expires_at > NOW()
        LIMIT 1
    ");
    $stmt->execute([$token]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$row) {
        http_response_code(404);
        die('This quote link is invalid or has expired.');
    }

    $quoteId     = (int)$row['id'];
    $quoteNumber = $row['quote_number'] ?: ('QUO-' . $quoteId);

    // Load PDF generator
    require_once APP_ROOT . '/Services/Pdf/pdf_bootstrap.php';
    require_once APP_ROOT . '/Services/Pdf/PdfGenerator.php';

    $pdfGen = new PdfGenerator();

    // Try cached PDF first, otherwise generate fresh
    $path = $pdfGen->getPdfPath('quote', $quoteId);
    if (!$path) {
        $result = $pdfGen->generateQuotePdf($quoteId);
        if (empty($result['success'])) {
            error_log("Customer PDF: generateQuotePdf failed for quote {$quoteId}: " . ($result['error'] ?? 'unknown'));
            http_response_code(500);
            die('Sorry — we could not produce the quote PDF right now. Please contact Mowology at (778) 846-9273.');
        }
        $path = $result['path'];
    }

    if (!$path || !is_readable($path)) {
        error_log("Customer PDF: file missing/unreadable at {$path} for quote {$quoteId}");
        http_response_code(500);
        die('Sorry — the quote PDF file is unavailable. Please contact Mowology at (778) 846-9273.');
    }

    $downloadName = preg_replace('/[^A-Za-z0-9_\-]/', '', $quoteNumber) . '.pdf';
    $disposition  = $inline ? 'inline' : 'attachment';

    header('Content-Type: application/pdf');
    header('Content-Disposition: ' . $disposition . '; filename="' . $downloadName . '"');
    header('Content-Length: ' . filesize($path));
    header('Cache-Control: private, max-age=0, must-revalidate');
    header('Pragma: public');

    readfile($path);
    exit;

} catch (Throwable $e) {
    error_log("Customer PDF endpoint error: " . $e->getMessage());
    http_response_code(500);
    die('An unexpected error occurred. Please contact Mowology at (778) 846-9273.');
}

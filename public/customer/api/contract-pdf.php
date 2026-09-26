<?php
/**
 * Customer-side Contract PDF download / print.
 * Token-based (no login required), using the signature token the client already
 * has from their signing link.
 *
 * URL:
 *   GET /customer/api/contract-pdf.php?token=ABC            → download (attachment)
 *   GET /customer/api/contract-pdf.php?token=ABC&inline=1   → open in browser (print-friendly)
 *
 * Always regenerates rather than serving a cached file. A contract PDF is cheap
 * and deterministic, and the one thing that must never happen is handing back a
 * stale copy whose terms differ from the version the client is being asked to
 * sign. Mirrors invoice-pdf.php otherwise.
 */

header('X-Content-Type-Options: nosniff');
header('X-Frame-Options: SAMEORIGIN');

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

$token  = trim($_GET['token'] ?? '');
$inline = !empty($_GET['inline']);

if ($token === '') {
    http_response_code(400);
    die('Missing token.');
}

try {
    $db = getDB();

    // The signature token is the client's credential. Expired tokens still
    // resolve here on purpose: once someone has signed, the link stops being
    // usable for signing but they should not lose access to their own copy.
    $stmt = $db->prepare("
        SELECT cs.contract_id, cs.contract_version, c.contract_number
        FROM contract_signatures cs
        JOIN contracts c ON c.id = cs.contract_id
        WHERE cs.signature_token = ?
        LIMIT 1
    ");
    $stmt->execute([$token]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$row) {
        http_response_code(404);
        die('This contract link is invalid.');
    }

    $contractId     = (int)$row['contract_id'];
    $contractNumber = $row['contract_number'] ?: ('CTR-' . $contractId);

    require_once APP_ROOT . '/Services/Pdf/pdf_bootstrap.php';
    require_once APP_ROOT . '/Services/Pdf/PdfGenerator.php';

    $result = (new PdfGenerator())->generateContractPdf($contractId, (int)$row['contract_version']);
    if (empty($result['success']) || empty($result['path']) || !is_readable($result['path'])) {
        error_log("Customer PDF: generateContractPdf failed for contract {$contractId}: " . ($result['error'] ?? 'unknown'));
        http_response_code(500);
        die('Sorry — we could not produce the contract PDF right now. Please contact us at (778) 846-9273.');
    }

    $downloadName = preg_replace('/[^A-Za-z0-9_\-]/', '', $contractNumber) . '.pdf';
    $disposition  = $inline ? 'inline' : 'attachment';

    header('Content-Type: application/pdf');
    header('Content-Disposition: ' . $disposition . '; filename="' . $downloadName . '"');
    header('Content-Length: ' . filesize($result['path']));
    header('Cache-Control: private, max-age=0, must-revalidate');
    header('Pragma: public');

    readfile($result['path']);
    exit;

} catch (Throwable $e) {
    error_log('Customer contract PDF error: ' . $e->getMessage());
    http_response_code(500);
    die('Sorry — something went wrong producing the contract PDF.');
}

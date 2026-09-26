<?php
/**
 * Contract PDF — staff download / print.
 *
 * GET /crm/contracts/pdf.php?id=12            → download
 * GET /crm/contracts/pdf.php?id=12&inline=1   → open in the browser to print
 * GET /crm/contracts/pdf.php?id=12&version=1  → a specific sealed version's terms
 *
 * Regenerated on every request: deterministic, cheap, and never at risk of
 * handing back a stale copy whose terms differ from the sealed version.
 */

require_once dirname(__DIR__) . '/../loginAuth/auth.php';
require_once dirname(__DIR__) . '/includes/functions.php';
requireLogin();
requirePermission('jobs.view');
$user = getCurrentUser();

$contractId = (int)($_GET['id'] ?? 0);
$version    = isset($_GET['version']) ? (int)$_GET['version'] : null;
$inline     = !empty($_GET['inline']);

if ($contractId <= 0) {
    http_response_code(400);
    die('Missing contract id.');
}

try {
    $db   = getDB();
    $stmt = $db->prepare("SELECT contract_number FROM contracts WHERE id = ?");
    $stmt->execute([$contractId]);
    $number = $stmt->fetchColumn();
    if ($number === false) {
        http_response_code(404);
        die('Contract not found.');
    }

    require_once APP_ROOT . '/Services/Pdf/pdf_bootstrap.php';
    require_once APP_ROOT . '/Services/Pdf/PdfGenerator.php';

    $result = (new PdfGenerator())->generateContractPdf($contractId, $version);
    if (empty($result['success']) || empty($result['path']) || !is_readable($result['path'])) {
        error_log("Contract PDF failed for {$contractId}: " . ($result['error'] ?? 'unknown'));
        http_response_code(500);
        die('Could not produce the contract PDF — see the server log.');
    }

    $downloadName = preg_replace('/[^A-Za-z0-9_\-]/', '', (string)($number ?: 'CTR-' . $contractId)) . '.pdf';

    header('Content-Type: application/pdf');
    header('Content-Disposition: ' . ($inline ? 'inline' : 'attachment') . '; filename="' . $downloadName . '"');
    header('Content-Length: ' . filesize($result['path']));
    header('Cache-Control: private, max-age=0, must-revalidate');

    readfile($result['path']);
    exit;

} catch (Throwable $e) {
    error_log('Contract PDF error: ' . $e->getMessage());
    http_response_code(500);
    die('Something went wrong producing the contract PDF.');
}

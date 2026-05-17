<?php
/**
 * CRM Document Download / Preview
 *
 * Streams a PDF stored under STORAGE_ROOT to the browser (inline).
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
if (!in_array($user['role'] ?? '', ['admin', 'manager', 'employee'], true)) {
    http_response_code(403);
    exit('Forbidden');
}

$rawPath = trim($_GET['path'] ?? '');
if (!$rawPath) {
    http_response_code(400);
    exit('No path specified.');
}

// Block path traversal
if (strpos($rawPath, '..') !== false || strpos($rawPath, "\0") !== false) {
    http_response_code(400);
    exit('Invalid path.');
}

// Map "storage/pdfs/<subdir>/<file>" → STORAGE_ROOT . "/pdfs/<subdir>/<file>"
// The stored pdf_path uses "storage/" as the STORAGE_ROOT marker.
if (strncmp($rawPath, 'storage/', 8) === 0) {
    $relativePart = substr($rawPath, 8); // "pdfs/salt-reports/salt_42_v1.pdf"
    $absPath = rtrim(STORAGE_ROOT, '/') . '/' . $relativePart;
} else {
    // Fallback: treat as relative to PUBLIC_ROOT
    $absPath = rtrim(PUBLIC_ROOT, '/') . '/' . ltrim($rawPath, '/');
}

// Ensure the resolved path stays within STORAGE_ROOT
$realStorage = realpath(STORAGE_ROOT);
$realAbs     = realpath($absPath);

if ($realAbs === false || $realStorage === false || strncmp($realAbs, $realStorage, strlen($realStorage)) !== 0) {
    http_response_code(403);
    exit('Access denied.');
}

if (!is_file($realAbs)) {
    http_response_code(404);
    exit('File not found.');
}

$ext      = strtolower(pathinfo($realAbs, PATHINFO_EXTENSION));
$mimeMap  = ['pdf' => 'application/pdf', 'jpg' => 'image/jpeg', 'jpeg' => 'image/jpeg', 'png' => 'image/png'];
$mimeType = $mimeMap[$ext] ?? 'application/octet-stream';

$filename = basename($realAbs);

header('Content-Type: ' . $mimeType);
header('Content-Disposition: inline; filename="' . addslashes($filename) . '"');
header('Content-Length: ' . filesize($realAbs));
header('Cache-Control: private, max-age=3600');
header('X-Content-Type-Options: nosniff');

readfile($realAbs);
exit;

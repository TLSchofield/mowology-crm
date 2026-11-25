<?php
/**
 * API: Serve Receipt Image (Auth-Gated)
 *
 * Proxies receipt files so they are never directly accessible via public URL.
 * Only authenticated users with expenses.view permission can retrieve images, and only
 * the office (admin/manager/expenses.approve) or the receipt's own creator (2026-10-12).
 *
 * GET /crm/api/serve-receipt.php?id=<media_id>
 *   or
 * GET /crm/api/serve-receipt.php?path=<web_path>  (legacy, internal use only)
 *
 * Returns the raw image bytes with correct Content-Type.
 * Returns 403 if not authenticated, 404 if file not found.
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
require_once APP_ROOT . '/Core/Uploads.php';

// Must be logged in
requireLogin();
session_write_close();

// Must have permission to view expenses
requirePermission('expenses.view');

$db = getDB();
$absolutePath = null;
$mimeType = 'image/jpeg';

// --- Resolve file by media_id (preferred) ---
if (!empty($_GET['id'])) {
    $mediaId = (int)$_GET['id'];

    // SELECT * so this keeps working whether or not the archival columns (migration 1066)
    // have been applied yet — absent columns simply read as null below (not-archived).
    $stmt = $db->prepare('SELECT * FROM media_assets WHERE id = ? LIMIT 1');
    $stmt->execute([$mediaId]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$row) {
        http_response_code(404);
        exit('Receipt not found');
    }

    // The office sees every receipt; anyone else only their own (Fable review 2026-10-12).
    require_once APP_ROOT . '/Modules/Expenses/Services/ExpenseService.php';
    if (!ExpenseService::currentUserCanSeeReceiptMedia($db, $mediaId, $row)) {
        http_response_code(404);
        exit('Receipt not found');
    }

    $mimeType = $row['mime_type'] ?: 'image/jpeg';

    // --- Archived receipt handling (Receipt Archival & Export) ---
    // Once archived, the full-resolution original has been emailed off-server and removed
    // from the web disk to reclaim space. We keep only a small thumbnail. Serve that.
    if (!empty($row['archived_at']) && !empty($row['original_removed'])) {
        if (!empty($row['thumb_path'])) {
            $absolutePath = PUBLIC_ROOT . $row['thumb_path'];
            $mimeType = 'image/jpeg'; // thumbnails are always JPEG
        } else {
            // No thumbnail kept — the full-res lives only in the emailed ZIP archive.
            http_response_code(410); // Gone
            exit('This receipt image has been archived off-server. The full-resolution copy is in the emailed receipt archive ZIP (see MANIFEST.csv).');
        }
    } else {
        // Build absolute path from web path
        // file_path is web-root relative: /uploads/receipts/... or /_media/original/...
        $absolutePath = PUBLIC_ROOT . $row['file_path'];
    }

} else {
    http_response_code(400);
    exit('Missing receipt ID');
}

// --- Safety: ensure the resolved path stays within uploads/ or _media/ ---
$allowedPrefixes = [
    realpath(uploadsDir('receipts')),
    realpath(PUBLIC_ROOT . '/_media'),
];

$realPath = realpath($absolutePath);
$allowed = false;
if ($realPath) {
    foreach ($allowedPrefixes as $prefix) {
        if ($prefix && strpos($realPath, rtrim($prefix, '/') . '/') === 0) {   // folder boundary: receipts/ never matches receipts-x/ (Codex 2026-10-23)
            $allowed = true;
            break;
        }
    }
}

if (!$allowed || !$realPath || !is_file($realPath)) {
    http_response_code(404);
    exit('File not found');
}

// --- ?thumb=1: a small cached JPEG for lists (ReceiptThumb, 2026-10-14); the original if one can't be made ---
$cacheFor = 3600;
if (!empty($_GET['thumb']) && empty($row['original_removed'])) {
    require_once APP_ROOT . '/Services/Receipts/ReceiptThumb.php';
    $thumb = ReceiptThumb::ensure(PUBLIC_ROOT, (int)$row['id'], $realPath, $mimeType);
    if ($thumb !== null) {
        $realPath = $thumb;
        $mimeType = 'image/jpeg';
        $cacheFor = 86400;   // a thumbnail is re-made on disk when its photo changes; a day of browser caching
    }
}

// --- Serve the file ---
$fileSize = filesize($realPath);

header('Content-Type: ' . $mimeType);
header('Content-Length: ' . $fileSize);
header('Cache-Control: private, max-age=' . $cacheFor);
header('X-Content-Type-Options: nosniff');
if (stripos($mimeType, 'html') !== false) {
    // An emailed receipt (the email itself, ReceiptInboxService::ingestEmailBody): shown as sent, but
    // sandboxed — no scripts, no forms, no same-origin access, nothing fetched but images and styles.
    header("Content-Security-Policy: sandbox; default-src 'none'; img-src https: data:; style-src 'unsafe-inline'; font-src https: data:");
    header('Referrer-Policy: no-referrer');
} elseif (stripos($mimeType, 'pdf') !== false) {
    // A PDF receipt shown beside the editor form (2026-10-20). public/.htaccess no longer adds the page policy to
    // this URL: its object-src 'none' made Chrome refuse to draw a PDF in a frame. This policy lets the viewer
    // draw it and nothing else: no scripts, nothing fetched, framed only by our own pages. (No `sandbox`: it blocks
    // the PDF viewer.)
    header("Content-Security-Policy: default-src 'none'; object-src 'self'; style-src 'unsafe-inline'; frame-ancestors 'self'");
} else {
    // Images and anything else: the page policy used to cover these; a served file needs far less. Sandboxed,
    // so even a file that is not the image it claims to be cannot run anything.
    header("Content-Security-Policy: sandbox; default-src 'none'; img-src 'self' data:; frame-ancestors 'self'");
}
// No Content-Disposition: inline so it displays in browser <img> tags

readfile($realPath);
exit;

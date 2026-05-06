<?php
/**
 * API: Serve Receipt Image (Auth-Gated)
 *
 * Proxies receipt files so they are never directly accessible via public URL.
 * Only authenticated users with expenses.view permission can retrieve images.
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

    $stmt = $db->prepare('
        SELECT ma.file_path, ma.mime_type, ma.stored_filename
        FROM media_assets ma
        WHERE ma.id = ?
        LIMIT 1
    ');
    $stmt->execute([$mediaId]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$row) {
        http_response_code(404);
        exit('Receipt not found');
    }

    // Build absolute path from web path
    // file_path is web-root relative: /uploads/receipts/... or /_media/original/...
    $absolutePath = PUBLIC_ROOT . $row['file_path'];
    $mimeType = $row['mime_type'] ?: 'image/jpeg';

} else {
    http_response_code(400);
    exit('Missing receipt ID');
}

// --- Safety: ensure the resolved path stays within uploads/ or _media/ ---
$allowedPrefixes = [
    realpath(PUBLIC_ROOT . '/uploads/receipts'),
    realpath(PUBLIC_ROOT . '/_media'),
];

$realPath = realpath($absolutePath);
$allowed = false;
if ($realPath) {
    foreach ($allowedPrefixes as $prefix) {
        if ($prefix && strpos($realPath, $prefix) === 0) {
            $allowed = true;
            break;
        }
    }
}

if (!$allowed || !$realPath || !is_file($realPath)) {
    http_response_code(404);
    exit('File not found');
}

// --- Serve the file ---
$fileSize = filesize($realPath);

header('Content-Type: ' . $mimeType);
header('Content-Length: ' . $fileSize);
header('Cache-Control: private, max-age=3600');
header('X-Content-Type-Options: nosniff');
// No Content-Disposition: inline so it displays in browser <img> tags

readfile($realPath);
exit;

<?php
/**
 * API: Serve Receipt Image (signature-gated — no session or Bearer header needed)
 *
 * GET /api/expenses/receipt-image?m=<media_id>&e=<expiry_ts>&s=<hmac>
 *
 * Why this exists: the iOS app renders receipt images with SwiftUI `AsyncImage`, which
 * cannot attach an Authorization header — so a Bearer-gated endpoint is unusable there,
 * and the raw /uploads/receipts path is `Require all denied` (403). Instead the already
 * JWT-authenticated expense-list.php mints a short-lived HMAC-signed URL that this
 * endpoint validates. The signature IS the grant (it can only be produced by a caller
 * holding BLUEMOON_JWT_SECRET), so no session/token is required to stream the bytes.
 *
 * Mirrors /crm/api/serve-receipt.php's media_assets lookup + safe-path + streaming.
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

require_once APP_ROOT . '/Core/Auth/JwtAuth.php';
require_once PUBLIC_ROOT . '/loginAuth/auth.php';

$mediaId = isset($_GET['m']) ? (int)$_GET['m'] : 0;
$expiry  = isset($_GET['e']) ? (int)$_GET['e'] : 0;
$sig     = isset($_GET['s']) ? (string)$_GET['s'] : '';

if ($mediaId <= 0 || $expiry <= 0 || $sig === '') {
    http_response_code(400);
    exit('Bad request');
}
if ($expiry < time()) {
    http_response_code(403);
    exit('Link expired');
}

$expected = hash_hmac('sha256', $mediaId . '.' . $expiry, jwtSecret());
if (!hash_equals($expected, $sig)) {
    http_response_code(403);
    exit('Invalid signature');
}

try {
    $db = getDB();

    // SELECT * so this keeps working whether or not the archival columns (archived_at /
    // thumb_path / original_removed) have been migrated onto this environment — absent
    // columns simply read as null below (treated as not-archived).
    $stmt = $db->prepare('SELECT * FROM media_assets WHERE id = ? LIMIT 1');
    $stmt->execute([$mediaId]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$row) {
        http_response_code(404);
        exit('Receipt not found');
    }

    $mimeType     = !empty($row['mime_type']) ? $row['mime_type'] : 'image/jpeg';
    $absolutePath = null;

    // Archived receipts (Receipt Archival & Export) keep only a small thumbnail on disk.
    if (!empty($row['archived_at']) && !empty($row['original_removed'])) {
        if (!empty($row['thumb_path'])) {
            $absolutePath = PUBLIC_ROOT . $row['thumb_path'];
            $mimeType     = 'image/jpeg';
        } else {
            http_response_code(410);
            exit('This receipt image has been archived off-server.');
        }
    } else {
        // file_path is web-root relative: /uploads/receipts/... or /_media/original/...
        $absolutePath = PUBLIC_ROOT . ($row['file_path'] ?? '');
    }

    // Safety: the resolved path must stay within uploads/receipts or _media.
    $allowedPrefixes = [
        realpath(PUBLIC_ROOT . '/uploads/receipts'),
        realpath(PUBLIC_ROOT . '/_media'),
    ];
    $realPath = realpath($absolutePath);
    $allowed  = false;
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

    header('Content-Type: ' . $mimeType);
    header('Content-Length: ' . filesize($realPath));
    header('Cache-Control: private, max-age=3600');
    header('X-Content-Type-Options: nosniff');
    readfile($realPath);
    exit;

} catch (Throwable $e) {
    http_response_code(500);
    exit('Server error');
}

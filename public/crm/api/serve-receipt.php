<?php
/**
 * API: Serve Receipt Image (Auth-Gated + Signed-URL)
 *
 * Proxies receipt files so they are never directly accessible via public URL.
 * Three auth modes (any one is sufficient):
 *   1. Signed URL  — ?id=N&exp=TS&sig=HMAC  (used by iOS AsyncImage; expires in 1h)
 *   2. JWT Bearer  — Authorization: Bearer <jwt>  (mobile API consumers)
 *   3. Web session — requireLogin() + requirePermission('expenses.view')
 *
 * Returns the raw image bytes with correct Content-Type.
 * Returns 401/403 if not authenticated, 404 if file not found.
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

// MUST load config first so jwtSecret() sees the same BLUEMOON_JWT_SECRET /
// DB_PASS the signer used. Without it, jwtSecret() falls back to a different
// hash and all signed URLs return 403.
require_once APP_ROOT . '/Core/config.php';
require_once APP_ROOT . '/Core/Auth/JwtAuth.php';
require_once APP_ROOT . '/Services/Receipts/ReceiptUrlSigner.php';

// --- Resolve media id ---
$mediaId = isset($_GET['id']) ? (int)$_GET['id'] : 0;
if ($mediaId <= 0) {
    http_response_code(400);
    exit('Missing receipt ID');
}

// --- Try auth modes in order: signed URL → JWT → session ---
$authed = false;

// 1. Signed URL (no session/JWT needed)
if (isset($_GET['exp'], $_GET['sig'])) {
    $exp = (int)$_GET['exp'];
    $sig = (string)$_GET['sig'];
    if (verifyReceiptUrlSignature($mediaId, $exp, $sig)) {
        $authed = true;
    } else {
        http_response_code(403);
        exit('Invalid or expired signature');
    }
}

// 2. JWT Bearer (mobile clients)
if (!$authed) {
    $jwtUser = getJwtUser();
    if ($jwtUser !== null) {
        $authed = true;
    }
}

// 3. Session-based (web)
if (!$authed) {
    require_once PUBLIC_ROOT . '/loginAuth/auth.php';
    require_once CRM_INCLUDES . '/functions.php';
    requireLogin();
    requirePermission('expenses.view');
    $authed = true;
}

$db = getDB();

// --- Look up the file ---
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

$absolutePath = PUBLIC_ROOT . $row['file_path'];
$mimeType     = $row['mime_type'] ?: 'image/jpeg';

// --- Safety: ensure the resolved path stays within uploads/ or _media/ ---
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

// --- Serve the file ---
$fileSize = filesize($realPath);

header('Content-Type: ' . $mimeType);
header('Content-Length: ' . $fileSize);
header('Cache-Control: private, max-age=3600');
header('X-Content-Type-Options: nosniff');

readfile($realPath);
exit;

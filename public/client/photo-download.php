<?php
/**
 * Client Photo Download — Token-based ZIP download
 *
 * GET ?token={64-char-hex}&visit={visit_id}
 *
 * Same token validation as proof.php.
 * Bundles all photos for the visit into a ZIP (max 20 photos / 50 MB).
 */
declare(strict_types=1);

header('X-Content-Type-Options: nosniff');
header('Cache-Control: no-store');

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

if (!function_exists('getDB')) {
    require_once PUBLIC_ROOT . '/app_config/config.php';
}

function dlError(string $msg, int $code = 400): never {
    http_response_code($code);
    header('Content-Type: text/plain');
    echo $msg;
    exit;
}

$db       = getDB();
$rawToken = trim($_GET['token'] ?? '');
$visitId  = isset($_GET['visit']) ? (int)$_GET['visit'] : 0;

if (!$rawToken || strlen($rawToken) !== 64 || $visitId < 1) {
    dlError('Invalid download link.');
}

// ── Validate token ─────────────────────────────────────────────────────────
$stmtT = $db->prepare("SELECT token_hash, expires_at, revoked_at FROM visit_share_tokens WHERE visit_id = ?");
$stmtT->execute([$visitId]);
$shareRow = $stmtT->fetch(PDO::FETCH_ASSOC);

if (!$shareRow)                                                          dlError('Gallery link not valid.');
if ($shareRow['revoked_at'] !== null)                                    dlError('Gallery link has been revoked.');
if ($shareRow['expires_at'] !== null && strtotime($shareRow['expires_at']) < time()) dlError('Gallery link has expired.');
if (!hash_equals($shareRow['token_hash'], hash('sha256', $rawToken)))    dlError('Gallery link is invalid.');

// ── Load photos (max 20, order: before → after → additional → other) ────────
$stmtP = $db->prepare("
    SELECT id, photo_type, filename, caption
    FROM visit_photos
    WHERE visit_id = ? AND deleted_at IS NULL
    ORDER BY
        FIELD(photo_type,'before','after','additional','during','issue','other'),
        sort_order ASC, uploaded_at ASC
    LIMIT 20
");
$stmtP->execute([$visitId]);
$photos = $stmtP->fetchAll(PDO::FETCH_ASSOC);

if (!$photos) {
    dlError('No photos available for download.');
}

if (!class_exists('ZipArchive')) {
    dlError('ZIP downloads are not supported on this server.', 500);
}

// ── Build ZIP ──────────────────────────────────────────────────────────────
$uploadsDir = PUBLIC_ROOT . '/uploads/photos/';
$tmpFile    = tempnam(sys_get_temp_dir(), 'mwdl_');

$zip = new ZipArchive();
if ($zip->open($tmpFile, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
    dlError('Failed to create archive.', 500);
}

$maxBytes   = 50 * 1024 * 1024; // 50 MB
$bytesAdded = 0;
$added      = 0;

foreach ($photos as $i => $ph) {
    $filePath = $uploadsDir . basename($ph['filename']); // basename prevents path traversal
    if (!is_file($filePath)) continue;

    $size = filesize($filePath);
    if ($bytesAdded + $size > $maxBytes) break;

    // Name in ZIP: 01_before_filename.jpg
    $ext     = pathinfo($ph['filename'], PATHINFO_EXTENSION);
    $zipName = sprintf('%02d_%s.%s', $added + 1, $ph['photo_type'], $ext);
    $zip->addFile($filePath, $zipName);
    $bytesAdded += $size;
    $added++;
}

$zip->close();

if ($added === 0) {
    unlink($tmpFile);
    dlError('No photo files found on disk.', 404);
}

// ── Stream response ────────────────────────────────────────────────────────
$dlName = 'mowology-visit-' . $visitId . '-photos.zip';
header('Content-Type: application/zip');
header('Content-Disposition: attachment; filename="' . $dlName . '"');
header('Content-Length: ' . filesize($tmpFile));

readfile($tmpFile);
unlink($tmpFile);
exit;

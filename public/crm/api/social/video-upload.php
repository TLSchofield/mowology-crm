<?php
/**
 * Social Video Upload
 * POST /crm/api/social/video-upload.php
 *
 * Accepts a MediaRecorder video blob from the animation builder,
 * stores it to /uploads/social-videos/, and creates a media_assets record.
 *
 * Request (multipart/form-data):
 *   video          — Blob (video/webm or video/mp4)
 *   animation_type — string (wipe|split|zoom|fade|reveal|blinds)
 *   csrf_token     — string
 *
 * Response 200: { "success": true, "media_id": N, "url": "/uploads/social-videos/..." }
 * Response 4xx: { "error": "..." }
 */

declare(strict_types=1);

// ── Bootstrap ────────────────────────────────────────────────────────────────
if (!defined('APP_ROOT')) {
    $__dir = __DIR__;
    for ($__i = 0; $__i < 6; $__i++) {
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

header('Content-Type: application/json; charset=utf-8');

requireLogin();
$user = getCurrentUser();

// CSRF must be verified before session_write_close
$csrfToken = trim($_POST['csrf_token'] ?? '');
if (!verifyCSRFToken($csrfToken)) {
    http_response_code(403);
    echo json_encode(['error' => 'Invalid CSRF token']);
    exit;
}

session_write_close();
requirePermission('marketing.edit');

// ── Method guard ─────────────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['error' => 'Method not allowed']);
    exit;
}

// ── Validate upload ──────────────────────────────────────────────────────────
if (empty($_FILES['video']) || $_FILES['video']['error'] !== UPLOAD_ERR_OK) {
    $code = $_FILES['video']['error'] ?? -1;
    http_response_code(400);
    echo json_encode(['error' => 'No video received (code ' . $code . ')']);
    exit;
}

$tmpPath  = $_FILES['video']['tmp_name'];
$origName = $_FILES['video']['name'] ?? 'animation.webm';
$mimeType = $_FILES['video']['type'] ?? '';

// Validate MIME — only allow video blobs from MediaRecorder
$allowedMimes = ['video/webm', 'video/mp4', 'video/webm;codecs=vp8', 'video/webm;codecs=vp9'];
// Strip codec suffix for comparison
$baseMime = explode(';', $mimeType)[0];
if (!in_array($baseMime, ['video/webm', 'video/mp4'], true)) {
    // Fall back to magic bytes check
    $fh = fopen($tmpPath, 'rb');
    $magic = fread($fh, 12);
    fclose($fh);
    // WebM magic: 0x1A45DFA3
    if (strncmp($magic, "\x1a\x45\xdf\xa3", 4) === 0) {
        $baseMime = 'video/webm';
    } else {
        http_response_code(415);
        echo json_encode(['error' => 'Only video/webm or video/mp4 accepted']);
        exit;
    }
}

$ext = $baseMime === 'video/mp4' ? 'mp4' : 'webm';

// Max size: 200 MB
if ($_FILES['video']['size'] > 200 * 1024 * 1024) {
    http_response_code(413);
    echo json_encode(['error' => 'Video file too large (max 200 MB)']);
    exit;
}

$animType = preg_replace('/[^a-z]/', '', strtolower($_POST['animation_type'] ?? 'wipe'));
$validAnims = ['wipe', 'split', 'zoom', 'fade', 'reveal', 'blinds'];
if (!in_array($animType, $validAnims, true)) $animType = 'wipe';

// ── Storage path ─────────────────────────────────────────────────────────────
// Resolve public root — same as PUBLIC_ROOT but we need the filesystem path
$uploadDir = defined('PUBLIC_ROOT') ? rtrim(PUBLIC_ROOT, '/') . '/uploads/social-videos/' : '/home/mowology/public_html/uploads/social-videos/';
if (!is_dir($uploadDir)) {
    mkdir($uploadDir, 0755, true);
}

$slug     = 'anim_' . date('Ymd_His') . '_' . bin2hex(random_bytes(4));
$filename = $slug . '.' . $ext;
$destPath = $uploadDir . $filename;
$filePath = 'uploads/social-videos/' . $filename;   // relative for DB
$webUrl   = '/uploads/social-videos/' . $filename;  // web-root relative

if (!move_uploaded_file($tmpPath, $destPath)) {
    http_response_code(500);
    echo json_encode(['error' => 'Failed to store video file']);
    exit;
}

// ── Insert media_assets record ────────────────────────────────────────────────
try {
    $db = getDB();

    $ins = $db->prepare("
        INSERT INTO media_assets
            (original_filename, stored_filename, file_path, file_type, mime_type,
             alt_text, created_by, upload_date)
        VALUES (?, ?, ?, 'video', ?, ?, ?, NOW())
    ");
    $ins->execute([
        $origName,
        $filename,
        $filePath,
        $baseMime,
        'Before/After animation (' . $animType . ')',
        (int)$user['id'],
    ]);
    $mediaId = (int)$db->lastInsertId();

} catch (Throwable $e) {
    error_log('[social/video-upload] DB error: ' . $e->getMessage());
    // Clean up orphaned file
    @unlink($destPath);
    http_response_code(500);
    echo json_encode(['error' => 'Database error — please try again']);
    exit;
}

echo json_encode([
    'success'   => true,
    'media_id'  => $mediaId,
    'url'       => $webUrl,
    'mime_type' => $baseMime,
]);

<?php
/**
 * Social image crop endpoint.
 *
 * POST multipart/form-data:
 *   crop_image        — cropped JPEG blob (from canvas.toBlob)
 *   original_media_id — ID of the source media_assets row (optional, for reference)
 *   csrf_token        — CSRF token
 *
 * Returns JSON:
 *   {success, media_id, file_path, url, width, height}
 *
 * The cropped image is saved as a new media_assets row in /uploads/cms/.
 * Original is untouched.
 */
declare(strict_types=1);

require_once dirname(dirname(dirname(__DIR__))) . '/loginAuth/auth.php';
require_once dirname(dirname(dirname(__DIR__))) . '/includes/functions.php';

header('Content-Type: application/json');

requireLogin();
requirePermission('photos.upload');
$user = getCurrentUser();

try {
    // CSRF
    if (!verifyCSRFToken($_POST['csrf_token'] ?? '')) {
        http_response_code(403);
        echo json_encode(['success' => false, 'error' => 'CSRF token invalid']);
        exit;
    }

    // File check
    if (empty($_FILES['crop_image']) || $_FILES['crop_image']['error'] !== UPLOAD_ERR_OK) {
        $code = $_FILES['crop_image']['error'] ?? -1;
        echo json_encode(['success' => false, 'error' => 'No file received (code ' . $code . ')']);
        exit;
    }

    $file = $_FILES['crop_image'];

    // Validate mime type via actual file content (not client header)
    $mime = mime_content_type($file['tmp_name']);
    $allowedMimes = ['image/jpeg', 'image/png', 'image/webp'];
    if (!in_array($mime, $allowedMimes, true)) {
        echo json_encode(['success' => false, 'error' => 'Invalid file type: ' . $mime]);
        exit;
    }

    // Size cap: 8 MB (crops can be large if source was high-res)
    if ($file['size'] > 8 * 1024 * 1024) {
        echo json_encode(['success' => false, 'error' => 'Cropped image exceeds 8 MB']);
        exit;
    }

    // Determine extension from mime
    $extMap = ['image/jpeg' => 'jpg', 'image/png' => 'png', 'image/webp' => 'webp'];
    $ext = $extMap[$mime] ?? 'jpg';

    // Storage directory — same as regular media uploads
    // __DIR__ = …/crm/api/social  → 3x dirname = public_html root
    $uploadDir = dirname(dirname(dirname(__DIR__))) . '/uploads/cms/';
    if (!is_dir($uploadDir) && !mkdir($uploadDir, 0755, true)) {
        echo json_encode(['success' => false, 'error' => 'Upload directory not writable']);
        exit;
    }

    // Unique filename with crop- prefix so they're identifiable
    $storedName = 'crop-' . uniqid('', true) . '.' . $ext;
    $filePath   = $uploadDir . $storedName;
    $webPath    = '/uploads/cms/' . $storedName;

    if (!move_uploaded_file($file['tmp_name'], $filePath)) {
        echo json_encode(['success' => false, 'error' => 'Failed to save cropped file']);
        exit;
    }

    // Read dimensions
    $imgInfo = @getimagesize($filePath);
    $width   = $imgInfo ? (int)$imgInfo[0] : 0;
    $height  = $imgInfo ? (int)$imgInfo[1] : 0;

    // Build a descriptive original_filename
    $origId   = (int)($_POST['original_media_id'] ?? 0);
    $origName = $origId ? ('crop-of-' . $origId . '.' . $ext) : ('crop-' . date('Y-m-d') . '.' . $ext);

    // Insert into media_assets
    $db = getDB();
    $db->prepare("
        INSERT INTO media_assets
            (original_filename, stored_filename, file_path, file_type, mime_type,
             file_size, image_width, image_height, alt_text, created_by)
        VALUES (?, ?, ?, 'image', ?, ?, ?, ?, '', ?)
    ")->execute([
        $origName,
        $storedName,
        $webPath,
        $mime,
        $file['size'],
        $width ?: null,
        $height ?: null,
        $user['id'],
    ]);

    $mediaId = (int)$db->lastInsertId();

    // Audit
    $db->prepare("
        INSERT INTO social_audit_log (user_id, action, entity_type, entity_id, detail, ip_address, created_at)
        VALUES (?, 'media_cropped', 'media', ?, ?, ?, NOW())
    ")->execute([
        $user['id'],
        $mediaId,
        'Cropped from media_id ' . $origId . ' — ' . $width . 'x' . $height,
        $_SERVER['REMOTE_ADDR'] ?? null,
    ]);

    echo json_encode([
        'success'   => true,
        'media_id'  => $mediaId,
        'file_path' => $webPath,
        'url'       => $webPath,
        'width'     => $width,
        'height'    => $height,
    ]);

} catch (\Throwable $e) {
    error_log('crop.php error: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => 'Server error: ' . $e->getMessage()]);
}

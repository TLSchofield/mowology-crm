<?php
/**
 * /crm/media/upload.php
 * AJAX handler for staff photo uploads
 * Accepts file + metadata, stores original, queues for optimization
 */

declare(strict_types=1);
require_once dirname(__DIR__) . '/../loginAuth/auth.php';
require_once dirname(__DIR__) . '/includes/functions.php';
require_once dirname(__DIR__) . '/includes/portfolio-functions.php';

requireLogin();
$user = getCurrentUser();

// Only allow authenticated users
if (!$user) {
    http_response_code(401);
    die(json_encode(['success' => false, 'message' => 'Unauthorized']));
}

// Only POST allowed
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    die(json_encode(['success' => false, 'message' => 'Method not allowed']));
}

// Validate CSRF
$csrfToken = $_POST['csrf_token'] ?? '';
if (!verifyCSRFToken($csrfToken)) {
    http_response_code(403);
    die(json_encode(['success' => false, 'message' => 'CSRF token invalid']));
}

// Check file was uploaded
if (empty($_FILES['photo'])) {
    http_response_code(400);
    die(json_encode(['success' => false, 'message' => 'No file uploaded']));
}

$file = $_FILES['photo'];
$visitId = !empty($_POST['visit_id']) ? (int)$_POST['visit_id'] : null;
$propertyId = !empty($_POST['property_id']) ? (int)$_POST['property_id'] : null;
$serviceType = trim($_POST['service_type'] ?? '');
$area = trim($_POST['area'] ?? '');
$tags = !empty($_POST['tags']) ? explode(',', $_POST['tags']) : [];
$title = trim($_POST['title'] ?? '');
$caption = trim($_POST['caption'] ?? '');

// Trim tags
$tags = array_filter(array_map('trim', $tags));

// File validation
$allowed_mimes = ['image/jpeg', 'image/png', 'image/webp'];
$allowed_exts = ['jpg', 'jpeg', 'png', 'webp'];

// Check MIME type
if (!in_array($file['type'], $allowed_mimes)) {
    http_response_code(400);
    die(json_encode(['success' => false, 'message' => 'Invalid file type. Only JPEG, PNG, WebP allowed.']));
}

// Check extension
$ext = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
if (!in_array($ext, $allowed_exts)) {
    http_response_code(400);
    die(json_encode(['success' => false, 'message' => 'Invalid file extension']));
}

// Check file size (10MB max)
if ($file['size'] > 10 * 1024 * 1024) {
    http_response_code(413);
    die(json_encode(['success' => false, 'message' => 'File too large (max 10MB)']));
}

// Check upload error
if ($file['error'] !== UPLOAD_ERR_OK) {
    http_response_code(400);
    die(json_encode(['success' => false, 'message' => 'Upload error: ' . $file['error']]));
}

try {
    // Ensure upload directory exists
    $uploadDir = __DIR__ . '/../../uploads/media/original';
    if (!is_dir($uploadDir)) {
        if (!mkdir($uploadDir, 0755, true)) {
            throw new Exception('Cannot create upload directory');
        }
    }

    // Verify MIME type with finfo
    $finfo = finfo_open(FILEINFO_MIME_TYPE);
    $mime = finfo_file($finfo, $file['tmp_name']);
    finfo_close($finfo);

    if (!in_array($mime, $allowed_mimes)) {
        throw new Exception('File MIME type verification failed');
    }

    // Generate unique filename
    $filename = 'media_' . time() . '_' . bin2hex(random_bytes(4)) . '.' . $ext;
    $filepath = $uploadDir . '/' . $filename;

    // Move uploaded file
    if (!move_uploaded_file($file['tmp_name'], $filepath)) {
        throw new Exception('Cannot move uploaded file');
    }

    // Get image dimensions and EXIF
    $imgInfo = getimagesize($filepath);
    if (!$imgInfo) {
        @unlink($filepath);
        throw new Exception('Cannot read image');
    }

    list($width, $height) = $imgInfo;
    $exif = extractExifData($filepath);
    $takenAt = $exif['taken_at'];

    // Get file size
    $fileSize = filesize($filepath);

    // Create media file record
    $relativePath = '/uploads/media/original/' . $filename;
    $mediaId = createMediaFile(
        $user['id'],
        $visitId,
        $propertyId,
        $relativePath,
        null,  // web_path (set after processing)
        null,  // thumb_path (set after processing)
        $mime,
        $fileSize,
        $width,
        $height,
        $takenAt
    );

    if (!$mediaId) {
        @unlink($filepath);
        throw new Exception('Cannot create media record');
    }

    // Add metadata
    setMediaMetadata(
        $mediaId,
        $title ?: null,
        null,  // alt_text
        $caption ?: null,
        $tags ?: null,
        $serviceType ?: null,
        $area ?: null,
        null,  // before_after_group_id
        false  // is_before
    );

    // Return success response
    die(json_encode([
        'success' => true,
        'message' => 'Photo uploaded successfully',
        'media_id' => $mediaId,
        'filename' => $filename,
        'width' => $width,
        'height' => $height,
        'taken_at' => $takenAt
    ]));

} catch (Throwable $e) {
    error_log("Media upload error: " . $e->getMessage());
    http_response_code(500);
    die(json_encode([
        'success' => false,
        'message' => 'Upload error: ' . $e->getMessage()
    ]));
}

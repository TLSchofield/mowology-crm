<?php
/**
 * API Endpoint: Upload Media
 *
 * POST /crm/api/upload-media.php
 * Handles file upload, validation, storage, and database registration
 */

declare(strict_types=1);

require_once dirname(__DIR__) . '/../includes/bootstrap.php';
require_once dirname(__DIR__) . '/includes/cms-functions.php';

header('Content-Type: application/json');

requireLogin();
$user = getCurrentUser();

// Access control
if (!in_array($user['role'], ['admin', 'staff'])) {
    http_response_code(403);
    echo json_encode(['success' => false, 'error' => 'Access denied']);
    exit;
}

// Verify CSRF token
if (!verifyCSRFToken($_POST['csrf_token'] ?? '')) {
    http_response_code(403);
    echo json_encode(['success' => false, 'error' => 'CSRF token invalid']);
    exit;
}

// Validate file upload
if (empty($_FILES['media_file'])) {
    echo json_encode(['success' => false, 'error' => 'No file uploaded']);
    exit;
}

$file = $_FILES['media_file'];
$altText = $_POST['alt_text'] ?? '';

// File validation
$maxSizes = [
    'image' => 5 * 1024 * 1024,        // 5MB
    'video' => 50 * 1024 * 1024,       // 50MB
    'document' => 10 * 1024 * 1024,    // 10MB
];

$allowedMimes = [
    'image' => ['image/jpeg', 'image/png', 'image/gif', 'image/webp'],
    'video' => ['video/mp4', 'video/webm'],
    'document' => ['application/pdf', 'application/msword', 'application/vnd.openxmlformats-officedocument.wordprocessingml.document'],
];

// Determine media type
$mimeType = mime_content_type($file['tmp_name']);
$mediaType = null;
foreach ($allowedMimes as $type => $mimes) {
    if (in_array($mimeType, $mimes)) {
        $mediaType = $type;
        break;
    }
}

if (!$mediaType) {
    echo json_encode(['success' => false, 'error' => 'File type not allowed']);
    exit;
}

if ($file['size'] > $maxSizes[$mediaType]) {
    echo json_encode(['success' => false, 'error' => 'File exceeds maximum size for ' . $mediaType . 's']);
    exit;
}

// Generate unique filename
$uploadDir = dirname(__DIR__) . '/../uploads/cms/';
if (!is_dir($uploadDir)) {
    mkdir($uploadDir, 0755, true);
}

$ext = pathinfo($file['name'], PATHINFO_EXTENSION);
$uniqueName = uniqid('media-') . '.' . $ext;
$filePath = $uploadDir . $uniqueName;
$webPath = '/uploads/cms/' . $uniqueName;

// Move uploaded file
if (!move_uploaded_file($file['tmp_name'], $filePath)) {
    echo json_encode(['success' => false, 'error' => 'Failed to save file']);
    exit;
}

// Register in database
try {
    $db = getDB();
    $stmt = $db->prepare('
        INSERT INTO media_assets (filename, file_path, file_size, media_type, alt_text, uploaded_by, uploaded_at)
        VALUES (?, ?, ?, ?, ?, ?, NOW())
    ');
    $stmt->execute([
        $file['name'],
        $webPath,
        $file['size'],
        $mediaType,
        $altText,
        $user['id'],
    ]);

    $mediaId = $db->lastInsertId();

    echo json_encode([
        'success' => true,
        'media_id' => $mediaId,
        'file_path' => $webPath,
        'filename' => $file['name'],
        'message' => 'Media uploaded successfully',
    ]);
} catch (PDOException $e) {
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => 'Database error: ' . $e->getMessage()]);
    exit;
}

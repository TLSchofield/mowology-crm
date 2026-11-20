<?php
/**
 * API Endpoint: Upload Company Logo
 *
 * POST /crm/api/upload-logo.php
 * Handles logo file upload, validation, and storage.
 * Returns the web-accessible path to the uploaded logo.
 */

declare(strict_types=1);

require_once dirname(__DIR__) . '/../loginAuth/auth.php';
require_once dirname(__DIR__) . '/includes/functions.php';
require_once APP_ROOT . '/Core/Uploads.php';

header('Content-Type: application/json');

requireLogin();
$user = getCurrentUser();
session_write_close();
requirePermission('settings.edit');

// Verify CSRF token
if (!verifyCSRFToken($_POST['csrf_token'] ?? '')) {
    http_response_code(403);
    echo json_encode(['success' => false, 'error' => 'CSRF token invalid']);
    exit;
}
session_write_close();

// Validate file upload
if (empty($_FILES['file'])) {
    echo json_encode(['success' => false, 'error' => 'No file uploaded']);
    exit;
}

$file = $_FILES['file'];

// Check for upload errors
if ($file['error'] !== UPLOAD_ERR_OK) {
    echo json_encode(['success' => false, 'error' => 'Upload error code: ' . $file['error']]);
    exit;
}

// Validate type from the CONTENT (finfo), never the name or the browser's type. SVG is refused
// (Fable review 2026-10-12): an SVG can carry script, and the old sanitiser had bypasses — an
// SVG logo must be exported to PNG first. The stored extension comes from the detected type.
$allowed  = ['image/png' => 'png', 'image/jpeg' => 'jpg'];
$mimeType = (string)(new finfo(FILEINFO_MIME_TYPE))->file($file['tmp_name']);
if (!isset($allowed[$mimeType]) || @getimagesize($file['tmp_name']) === false) {
    echo json_encode(['success' => false, 'error' => $mimeType === 'image/svg+xml'
        ? 'SVG logos are not accepted — please export it as a PNG.'
        : 'Only PNG and JPG files are allowed.']);
    exit;
}

// Validate file size (2 MB max)
$maxSize = 2 * 1024 * 1024;
if ($file['size'] > $maxSize) {
    echo json_encode(['success' => false, 'error' => 'File is too large. Maximum size is 2 MB.']);
    exit;
}

// Determine upload directory
$uploadDir = uploadsDir('') . '/';
if (!is_dir($uploadDir)) {
    mkdir($uploadDir, 0755, true);
}

$storedName = 'logo-' . time() . '.' . $allowed[$mimeType];
$filePath = $uploadDir . $storedName;
$webPath = uploadsUrl('') . '/' . $storedName;

if (!move_uploaded_file($file['tmp_name'], $filePath)) {
    echo json_encode(['success' => false, 'error' => 'Failed to save file.']);
    exit;
}

echo json_encode([
    'success' => true,
    'path' => $webPath,
    'filename' => $storedName
]);

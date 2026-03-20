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

// Validate MIME type
$allowedMimes = ['image/png', 'image/jpeg', 'image/svg+xml'];
$mimeType = mime_content_type($file['tmp_name']);
if (!in_array($mimeType, $allowedMimes)) {
    echo json_encode(['success' => false, 'error' => 'Only PNG, JPG, and SVG files are allowed.']);
    exit;
}

// Validate file size (2 MB max)
$maxSize = 2 * 1024 * 1024;
if ($file['size'] > $maxSize) {
    echo json_encode(['success' => false, 'error' => 'File is too large. Maximum size is 2 MB.']);
    exit;
}

// Determine upload directory
$uploadDir = dirname(__DIR__) . '/../uploads/';
if (!is_dir($uploadDir)) {
    mkdir($uploadDir, 0755, true);
}

// Generate filename
$ext = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
if (!in_array($ext, ['png', 'jpg', 'jpeg', 'svg'])) {
    $ext = 'png'; // fallback
}
$storedName = 'logo-' . time() . '.' . $ext;
$filePath = $uploadDir . $storedName;
$webPath = '/uploads/' . $storedName;

// Move the uploaded file
if (!move_uploaded_file($file['tmp_name'], $filePath)) {
    echo json_encode(['success' => false, 'error' => 'Failed to save file.']);
    exit;
}

echo json_encode([
    'success' => true,
    'path' => $webPath,
    'filename' => $storedName
]);

<?php
/**
 * Portfolio Image Upload Handler
 * Handles file uploads for portfolio projects
 */
require_once dirname(__DIR__) . '/../loginAuth/auth.php';

requireLogin();
$user = getCurrentUser();

// JSON response helper
function json_response($success, $message = '', $data = []) {
    header('Content-Type: application/json');
    echo json_encode(array_merge(
        ['success' => $success, 'message' => $message],
        $data
    ));
    exit;
}

// Verify required fields
if (!isset($_FILES['image']) || $_FILES['image']['error'] !== UPLOAD_ERR_OK) {
    json_response(false, 'No image uploaded or upload error occurred');
}

$file = $_FILES['image'];

// Validate file type
$allowedTypes = ['image/jpeg', 'image/png', 'image/gif', 'image/webp'];
if (!in_array($file['type'], $allowedTypes)) {
    json_response(false, 'Invalid file type. Only JPEG, PNG, GIF, and WebP are allowed');
}

// Validate file size (5MB max)
$maxSize = 5 * 1024 * 1024; // 5MB
if ($file['size'] > $maxSize) {
    json_response(false, 'File too large. Maximum size is 5MB');
}

// Create upload directory if it doesn't exist
$uploadDir = __DIR__ . '/../../assets/img/projects/';
if (!is_dir($uploadDir)) {
    if (!mkdir($uploadDir, 0755, true)) {
        json_response(false, 'Failed to create upload directory');
    }
}

// Generate unique filename
$fileExtension = pathinfo($file['name'], PATHINFO_EXTENSION);
$filename = 'project_' . time() . '_' . bin2hex(random_bytes(4)) . '.' . $fileExtension;
$filepath = $uploadDir . $filename;

// Move uploaded file
if (!move_uploaded_file($file['tmp_name'], $filepath)) {
    json_response(false, 'Failed to save uploaded file');
}

// Return success with relative URL path
$relativePath = '/assets/img/projects/' . $filename;

json_response(true, 'Image uploaded successfully', [
    'filepath' => $relativePath,
    'filename' => $filename
]);

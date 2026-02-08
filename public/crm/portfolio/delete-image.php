<?php
/**
 * Portfolio Image Delete Handler
 * Deletes an image file and clears the database reference
 */
require_once dirname(__DIR__) . '/../loginAuth/auth.php';
require_once dirname(__DIR__) . '/includes/functions.php';

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
$projectId = intval($_POST['project_id'] ?? 0);
$imageField = $_POST['image_field'] ?? '';

if (!$projectId || !in_array($imageField, ['before_image_path', 'after_image_path'])) {
    json_response(false, 'Invalid project ID or image field');
}

// Get the project to retrieve the image path
$project = getPortfolioProject($projectId);
if (!$project) {
    json_response(false, 'Project not found');
}

$imagePath = $project[$imageField];
if (!$imagePath) {
    json_response(false, 'No image to delete');
}

// Construct full file path - convert URL path to filesystem path
// Convert /assets/img/projects/filename.jpg to /path/to/public/assets/img/projects/filename.jpg
$filePath = __DIR__ . '/../../' . ltrim($imagePath, '/');

// Security check: ensure the file is in the projects directory
$realPath = realpath($filePath);
$projectsDir = realpath(__DIR__ . '/../../assets/img/projects/');
if (!$realPath || strpos($realPath, $projectsDir) !== 0) {
    json_response(false, 'Invalid file path');
}

// Delete the file from filesystem
if (file_exists($filePath)) {
    if (!unlink($filePath)) {
        json_response(false, 'Failed to delete image file from server');
    }
}

// Clear the image path in the database
$updateData = [$imageField => null];
if (!updatePortfolioProject($projectId, $updateData)) {
    json_response(false, 'Failed to update database');
}

json_response(true, 'Image deleted successfully');

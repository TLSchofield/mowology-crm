<?php
/**
 * Portfolio Image Delete Handler
 * Deletes an image file, its media system variants, and clears the database reference
 */
require_once dirname(__DIR__) . '/../loginAuth/auth.php';
require_once dirname(__DIR__) . '/includes/functions.php';

requireLogin();
$user = getCurrentUser();

// Load media processor for cleanup
require_once dirname(__DIR__) . '/includes/media-processor.php';

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

// Determine if this is a media system file or legacy file
$isMediaSystem = (strpos($imagePath, '/uploads/cms/') !== false);

if ($isMediaSystem) {
    // Media system file — clean up via media_assets + variants
    $filename = basename($imagePath);
    $db = getDB();

    // Find the media_assets record
    $stmt = $db->prepare('SELECT id FROM media_assets WHERE stored_filename = ? LIMIT 1');
    $stmt->execute([$filename]);
    $mediaRecord = $stmt->fetch();

    if ($mediaRecord) {
        $mediaId = $mediaRecord['id'];

        // Delete variant files from disk
        $vstmt = $db->prepare('SELECT file_path FROM media_variants WHERE media_id = ?');
        $vstmt->execute([$mediaId]);
        $variants = $vstmt->fetchAll();
        foreach ($variants as $variant) {
            $variantFile = __DIR__ . '/../../' . ltrim($variant['file_path'], '/');
            if (file_exists($variantFile)) {
                @unlink($variantFile);
            }
        }

        // Also clean up processor-generated files (pattern-based)
        $fullPath = __DIR__ . '/../../' . ltrim($imagePath, '/');
        if (file_exists($fullPath)) {
            mp_cleanupMediaVariants($mediaId, $fullPath);
        }

        // Delete DB records (variants cascade via FK, but be explicit)
        $db->prepare('DELETE FROM media_variants WHERE media_id = ?')->execute([$mediaId]);
        $db->prepare('DELETE FROM media_links WHERE media_id = ?')->execute([$mediaId]);
        $db->prepare('DELETE FROM media_assets WHERE id = ?')->execute([$mediaId]);
    }

    // Delete the original file
    $fullPath = __DIR__ . '/../../' . ltrim($imagePath, '/');
    if (file_exists($fullPath)) {
        @unlink($fullPath);
    }
} else {
    // Legacy file in /assets/img/projects/
    $filePath = __DIR__ . '/../../' . ltrim($imagePath, '/');

    // Security check: ensure the file is in the projects directory
    $realPath = realpath($filePath);
    $projectsDir = realpath(__DIR__ . '/../../assets/img/projects/');
    if ($realPath && $projectsDir && strpos($realPath, $projectsDir) !== 0) {
        json_response(false, 'Invalid file path');
    }

    if (file_exists($filePath)) {
        if (!unlink($filePath)) {
            json_response(false, 'Failed to delete image file from server');
        }
    }
}

// Clear the image path in the database
$updateData = [$imageField => null];
if (!updatePortfolioProject($projectId, $updateData)) {
    json_response(false, 'Failed to update database');
}

json_response(true, 'Image deleted successfully');

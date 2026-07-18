<?php
/**
 * Portfolio Gallery Image Upload Handler
 * Routes through the centralized media system for automatic optimization
 * (WebP generation, responsive sizes, database tracking)
 */
require_once dirname(__DIR__) . '/../loginAuth/auth.php';
require_once dirname(__DIR__) . '/includes/functions.php';

requireLogin();
$user = getCurrentUser();

// Load media processor
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
if (!isset($_FILES['image']) || $_FILES['image']['error'] !== UPLOAD_ERR_OK) {
    json_response(false, 'No image uploaded or upload error occurred');
}

$file = $_FILES['image'];

// Validate file type using actual content, not client-reported type
$mimeType = mime_content_type($file['tmp_name']);
$allowedTypes = ['image/jpeg', 'image/png', 'image/gif', 'image/webp'];
if (!in_array($mimeType, $allowedTypes)) {
    json_response(false, 'Invalid file type. Only JPEG, PNG, GIF, and WebP are allowed');
}

// Validate file size (5MB max)
$maxSize = 5 * 1024 * 1024;
if ($file['size'] > $maxSize) {
    json_response(false, 'File too large. Maximum size is 5MB');
}

// Upload to centralized media directory
$uploadDir = __DIR__ . '/../../uploads/cms/';
if (!is_dir($uploadDir)) {
    if (!mkdir($uploadDir, 0755, true)) {
        json_response(false, 'Failed to create upload directory');
    }
}

// Generate unique filename — derive the extension from the validated MIME type,
// never from the client-supplied filename (prevents extension-spoofing / polyglot uploads).
$extByMime = ['image/jpeg' => 'jpg', 'image/png' => 'png', 'image/gif' => 'gif', 'image/webp' => 'webp'];
$ext = $extByMime[$mimeType];
$storedName = 'portfolio-gallery-' . uniqid() . '.' . $ext;
$filePath = $uploadDir . $storedName;
$webPath = '/uploads/cms/' . $storedName;

// Move uploaded file
if (!move_uploaded_file($file['tmp_name'], $filePath)) {
    json_response(false, 'Failed to save uploaded file');
}

// Get image dimensions
$imgWidth = null;
$imgHeight = null;
if (function_exists('getimagesize')) {
    $imgInfo = @getimagesize($filePath);
    if ($imgInfo) {
        $imgWidth = $imgInfo[0];
        $imgHeight = $imgInfo[1];
    }
}

// Register in media_assets database
$mediaId = null;
try {
    $db = getDB();
    $stmt = $db->prepare('
        INSERT INTO media_assets
            (original_filename, stored_filename, file_path, file_type, mime_type, file_size, image_width, image_height, alt_text, context_type, created_by)
        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
    ');
    $altText = mp_suggestAltText($file['name'], 'landscaping');
    $stmt->execute([
        $file['name'],
        $storedName,
        $webPath,
        'image',
        $mimeType,
        $file['size'],
        $imgWidth,
        $imgHeight,
        $altText,
        'portfolio',
        $user['id'],
    ]);
    $mediaId = (int) $db->lastInsertId();
} catch (PDOException $e) {
    error_log('Portfolio gallery upload - DB registration failed: ' . $e->getMessage());
}

// Run media processor for responsive sizes + WebP
$processingResult = null;
if ($mediaId && function_exists('mp_processUploadedImage')) {
    try {
        $processingResult = mp_processUploadedImage($filePath, $mediaId);

        // Store variant metadata in media_variants table
        if ($processingResult && !empty($processingResult['variants'])) {
            foreach ($processingResult['variants'] as $variantKey => $variant) {
                $variantFormat = (strpos($variantKey, 'webp') !== false) ? 'webp' : 'jpeg';
                $variantType = str_replace('_webp', '', $variantKey);
                $variantWebPath = '/uploads/cms/' . basename($variant['path']);

                try {
                    $vstmt = $db->prepare('
                        INSERT INTO media_variants
                            (media_id, variant_type, format, width, height, file_path, file_size, quality)
                        VALUES (?, ?, ?, ?, ?, ?, ?, ?)
                    ');
                    $vstmt->execute([
                        $mediaId,
                        $variantType,
                        $variantFormat,
                        (int) ($variant['width'] ?? 0),
                        (int) ($variant['height'] ?? 0),
                        $variantWebPath,
                        $variant['size'] ?? null,
                        MEDIA_PROCESSOR_QUALITY,
                    ]);
                } catch (PDOException $e) {
                    error_log('Portfolio gallery upload - variant registration failed: ' . $e->getMessage());
                }
            }
        }
    } catch (Exception $e) {
        error_log('Portfolio gallery upload - image processing failed: ' . $e->getMessage());
    }
}

$responseData = [
    'filepath' => $webPath,
    'filename' => $storedName,
];

if ($mediaId) {
    $responseData['media_id'] = $mediaId;
}

if ($processingResult && !empty($processingResult['variants'])) {
    $responseData['optimized'] = true;
    $responseData['variants_count'] = count($processingResult['variants']);
}

json_response(true, 'Gallery image uploaded and optimized', $responseData);

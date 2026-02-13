<?php
/**
 * Unified Media Upload Service
 *
 * Handles file validation, UUID generation, SHA-256 dedup, EXIF GPS extraction,
 * auto-orientation, and storage in the /_media/ directory tree.
 *
 * @package Mowology CRM
 * @subpackage Media
 */

declare(strict_types=1);

// Allowed MIME types → extensions
const MEDIA_ALLOWED_MIMES = [
    'image/jpeg' => 'jpg',
    'image/png'  => 'png',
    'image/gif'  => 'gif',
    'image/webp' => 'webp',
];

const MEDIA_MAX_IMAGE_SIZE = 15 * 1024 * 1024; // 15 MB

/**
 * Upload a single file to the unified media system.
 *
 * @param array  $file         $_FILES entry for one file
 * @param int    $userId       Uploader user ID
 * @param string $contextType  Primary context (cms, job_visit, quote_visit, marketing_general, etc.)
 * @param array|null $browserGps  ['lat' => float, 'lng' => float, 'accuracy' => float] from navigator.geolocation
 * @param string|null $altText  Alt text for accessibility
 * @param string|null $caption  Caption/description
 * @return array ['success' => bool, 'media_id' => int, 'uuid' => string, 'file_path' => string, 'thumb_url' => string|null, 'errors' => string[]]
 */
function mediaUploadFile(
    array $file,
    int $userId,
    string $contextType = 'cms',
    ?array $browserGps = null,
    ?string $altText = null,
    ?string $caption = null
): array {
    $errors = [];

    // --- Validate upload error code ---
    if ($file['error'] !== UPLOAD_ERR_OK) {
        return ['success' => false, 'errors' => ['Upload failed: error code ' . $file['error']]];
    }

    // --- Validate MIME via finfo (not $_FILES['type'] which is user-spoofable) ---
    $finfo = new finfo(FILEINFO_MIME_TYPE);
    $mimeType = $finfo->file($file['tmp_name']);
    if (!isset(MEDIA_ALLOWED_MIMES[$mimeType])) {
        return ['success' => false, 'errors' => ['File type not allowed: ' . $mimeType]];
    }

    // --- Validate size ---
    if ($file['size'] > MEDIA_MAX_IMAGE_SIZE) {
        return ['success' => false, 'errors' => ['File too large (max ' . round(MEDIA_MAX_IMAGE_SIZE / 1024 / 1024) . 'MB)']];
    }

    // --- Validate it's actually a valid image ---
    $imgInfo = @getimagesize($file['tmp_name']);
    if (!$imgInfo) {
        return ['success' => false, 'errors' => ['Invalid image file']];
    }

    $originalWidth = $imgInfo[0];
    $originalHeight = $imgInfo[1];

    // --- Generate UUID and SHA-256 ---
    $uuid = generateMediaUuid();
    $sha256 = hash_file('sha256', $file['tmp_name']);

    // --- Dedup check ---
    $db = getDB();
    $dupStmt = $db->prepare('SELECT id, uuid, file_path FROM media_assets WHERE sha256 = ? LIMIT 1');
    $dupStmt->execute([$sha256]);
    $existing = $dupStmt->fetch(PDO::FETCH_ASSOC);

    if ($existing) {
        // Return existing media instead of storing a duplicate
        return [
            'success' => true,
            'media_id' => (int)$existing['id'],
            'uuid' => $existing['uuid'],
            'file_path' => $existing['file_path'],
            'thumb_url' => null,
            'is_duplicate' => true,
            'errors' => [],
        ];
    }

    // --- Extract EXIF data ---
    $exifData = mediaExtractExif($file['tmp_name']);
    $capturedAt = $exifData['captured_at'] ?? null;
    $exifOrientation = $exifData['orientation'] ?? 1;

    // --- Determine GPS source ---
    $gpsLat = null;
    $gpsLng = null;
    $gpsAccuracy = null;
    $gpsSource = 'none';

    if (!empty($exifData['gps_lat']) && !empty($exifData['gps_lng'])) {
        $gpsLat = $exifData['gps_lat'];
        $gpsLng = $exifData['gps_lng'];
        $gpsSource = 'exif';
    } elseif ($browserGps && !empty($browserGps['lat']) && !empty($browserGps['lng'])) {
        $gpsLat = (float)$browserGps['lat'];
        $gpsLng = (float)$browserGps['lng'];
        $gpsAccuracy = isset($browserGps['accuracy']) ? (float)$browserGps['accuracy'] : null;
        $gpsSource = 'browser';
    }

    // --- Determine storage path ---
    $ext = MEDIA_ALLOWED_MIMES[$mimeType];
    $yearMonth = date('Y/m');
    $originalDir = MEDIA_ROOT . '/original/' . $yearMonth;
    if (!is_dir($originalDir)) {
        mkdir($originalDir, 0755, true);
    }
    $storedFilename = $uuid . '.' . $ext;
    $absolutePath = $originalDir . '/' . $storedFilename;
    $webPath = '/_media/original/' . $yearMonth . '/' . $storedFilename;

    // --- Move uploaded file ---
    if (!move_uploaded_file($file['tmp_name'], $absolutePath)) {
        return ['success' => false, 'errors' => ['Failed to save file to storage']];
    }

    // --- Auto-orient based on EXIF ---
    if ($exifOrientation > 1 && extension_loaded('gd')) {
        mediaAutoOrient($absolutePath, $exifOrientation, $mimeType);
        // Re-read dimensions after orientation fix
        $reInfo = @getimagesize($absolutePath);
        if ($reInfo) {
            $originalWidth = $reInfo[0];
            $originalHeight = $reInfo[1];
        }
    }

    // --- Insert into database ---
    try {
        $stmt = $db->prepare('
            INSERT INTO media_assets
                (uuid, original_filename, stored_filename, file_path, file_type, mime_type, file_size,
                 image_width, image_height, alt_text, caption, created_by,
                 sha256, context_type, captured_at, gps_lat, gps_lng, gps_accuracy_m, gps_source,
                 original_bytes, status)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
        ');
        $stmt->execute([
            $uuid,
            basename($file['name'] ?? 'unknown'),
            $storedFilename,
            $webPath,
            'image',
            $mimeType,
            filesize($absolutePath),
            $originalWidth,
            $originalHeight,
            $altText,
            $caption,
            $userId,
            $sha256,
            $contextType,
            $capturedAt,
            $gpsLat,
            $gpsLng,
            $gpsAccuracy,
            $gpsSource,
            $file['size'],
            'processing',
        ]);

        $mediaId = (int)$db->lastInsertId();

        return [
            'success' => true,
            'media_id' => $mediaId,
            'uuid' => $uuid,
            'file_path' => $webPath,
            'thumb_url' => null,
            'is_duplicate' => false,
            'original_width' => $originalWidth,
            'original_height' => $originalHeight,
            'errors' => [],
        ];
    } catch (PDOException $e) {
        // Clean up the uploaded file on DB error
        @unlink($absolutePath);
        error_log('Media upload DB error: ' . $e->getMessage());
        return ['success' => false, 'errors' => ['Database error during upload']];
    }
}

/**
 * Create a media_links junction record.
 */
function createMediaLink(int $mediaId, string $contextType, int $contextId, ?string $category, ?string $visibility, int $userId): bool
{
    try {
        $db = getDB();
        $stmt = $db->prepare('
            INSERT INTO media_links (media_id, context_type, context_id, category, visibility, linked_by)
            VALUES (?, ?, ?, ?, ?, ?)
        ');
        return $stmt->execute([$mediaId, $contextType, $contextId, $category, $visibility ?: 'internal', $userId]);
    } catch (PDOException $e) {
        error_log('createMediaLink error: ' . $e->getMessage());
        return false;
    }
}

/**
 * Get all media linked to a specific context.
 */
function getMediaForContext(string $contextType, int $contextId): array
{
    $db = getDB();
    $stmt = $db->prepare('
        SELECT ma.*, ml.category, ml.visibility, ml.sort_order, ml.notes AS link_notes
        FROM media_assets ma
        JOIN media_links ml ON ml.media_id = ma.id
        WHERE ml.context_type = ? AND ml.context_id = ?
        ORDER BY ml.sort_order ASC, ma.created_at DESC
    ');
    $stmt->execute([$contextType, $contextId]);
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

/**
 * Generate a UUID v4 string.
 */
function generateMediaUuid(): string
{
    $data = random_bytes(16);
    $data[6] = chr(ord($data[6]) & 0x0f | 0x40); // version 4
    $data[8] = chr(ord($data[8]) & 0x3f | 0x80); // variant 1
    return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($data), 4));
}

/**
 * Extract EXIF data from an image file.
 *
 * @return array ['captured_at' => string|null, 'orientation' => int, 'gps_lat' => float|null, 'gps_lng' => float|null]
 */
function mediaExtractExif(string $filePath): array
{
    $result = [
        'captured_at' => null,
        'orientation' => 1,
        'gps_lat' => null,
        'gps_lng' => null,
    ];

    if (!function_exists('exif_read_data')) {
        return $result;
    }

    $exif = @exif_read_data($filePath, 'ANY_TAG', true);
    if (!$exif) {
        return $result;
    }

    // Capture date
    $dateFields = ['DateTimeOriginal', 'DateTimeDigitized', 'DateTime'];
    foreach ($dateFields as $field) {
        $val = $exif['EXIF'][$field] ?? $exif['IFD0'][$field] ?? null;
        if ($val) {
            // EXIF format: "2026:02:12 14:30:00" → MySQL format
            $result['captured_at'] = str_replace(':', '-', substr($val, 0, 10)) . substr($val, 10);
            break;
        }
    }

    // Orientation
    $result['orientation'] = (int)($exif['IFD0']['Orientation'] ?? $exif['EXIF']['Orientation'] ?? 1);

    // GPS coordinates
    if (!empty($exif['GPS']['GPSLatitude']) && !empty($exif['GPS']['GPSLongitude'])) {
        $result['gps_lat'] = mediaGpsToDecimal(
            $exif['GPS']['GPSLatitude'],
            $exif['GPS']['GPSLatitudeRef'] ?? 'N'
        );
        $result['gps_lng'] = mediaGpsToDecimal(
            $exif['GPS']['GPSLongitude'],
            $exif['GPS']['GPSLongitudeRef'] ?? 'W'
        );
    }

    return $result;
}

/**
 * Convert EXIF GPS DMS array to decimal degrees.
 */
function mediaGpsToDecimal(array $dms, string $ref): float
{
    $deg = mediaEvalRational($dms[0]);
    $min = mediaEvalRational($dms[1]);
    $sec = mediaEvalRational($dms[2]);
    $decimal = $deg + ($min / 60.0) + ($sec / 3600.0);
    return in_array($ref, ['S', 'W']) ? -$decimal : $decimal;
}

/**
 * Evaluate an EXIF rational number string (e.g., "49/1").
 */
function mediaEvalRational(string $rational): float
{
    $parts = explode('/', $rational);
    if (count($parts) === 2 && (float)$parts[1] !== 0.0) {
        return (float)$parts[0] / (float)$parts[1];
    }
    return (float)$parts[0];
}

/**
 * Auto-orient an image based on EXIF orientation tag using GD.
 */
function mediaAutoOrient(string $filePath, int $orientation, string $mimeType): bool
{
    $image = null;
    switch ($mimeType) {
        case 'image/jpeg':
            $image = @imagecreatefromjpeg($filePath);
            break;
        case 'image/png':
            $image = @imagecreatefrompng($filePath);
            break;
        case 'image/webp':
            $image = @imagecreatefromwebp($filePath);
            break;
        default:
            return false;
    }
    if (!$image) return false;

    $rotated = null;
    switch ($orientation) {
        case 2: // horizontal flip
            imageflip($image, IMG_FLIP_HORIZONTAL);
            $rotated = $image;
            break;
        case 3: // 180 rotate
            $rotated = imagerotate($image, 180, 0);
            break;
        case 4: // vertical flip
            imageflip($image, IMG_FLIP_VERTICAL);
            $rotated = $image;
            break;
        case 5: // rotate 270 + flip
            $rotated = imagerotate($image, 270, 0);
            if ($rotated) imageflip($rotated, IMG_FLIP_HORIZONTAL);
            break;
        case 6: // rotate 270
            $rotated = imagerotate($image, 270, 0);
            break;
        case 7: // rotate 90 + flip
            $rotated = imagerotate($image, 90, 0);
            if ($rotated) imageflip($rotated, IMG_FLIP_HORIZONTAL);
            break;
        case 8: // rotate 90
            $rotated = imagerotate($image, 90, 0);
            break;
        default:
            imagedestroy($image);
            return false;
    }

    if (!$rotated) {
        imagedestroy($image);
        return false;
    }

    // Save back in original format
    $success = false;
    switch ($mimeType) {
        case 'image/jpeg':
            $success = imagejpeg($rotated, $filePath, 95);
            break;
        case 'image/png':
            $success = imagepng($rotated, $filePath, 6);
            break;
        case 'image/webp':
            $success = imagewebp($rotated, $filePath, 90);
            break;
    }

    if ($rotated !== $image) {
        imagedestroy($image);
    }
    imagedestroy($rotated);

    return $success;
}

/**
 * Normalize the $_FILES array for multi-file uploads.
 * PHP sends multi-file uploads in an inconvenient structure; this normalizes it.
 */
function normalizeFilesArray(array $files): array
{
    // Single file upload (not array of names)
    if (!isset($files['name']) || !is_array($files['name'])) {
        return isset($files['name']) ? [$files] : [];
    }

    $normalized = [];
    $count = count($files['name']);
    for ($i = 0; $i < $count; $i++) {
        if ($files['error'][$i] === UPLOAD_ERR_NO_FILE) {
            continue; // Skip empty slots
        }
        $normalized[] = [
            'name'     => $files['name'][$i],
            'type'     => $files['type'][$i],
            'tmp_name' => $files['tmp_name'][$i],
            'error'    => $files['error'][$i],
            'size'     => $files['size'][$i],
        ];
    }
    return $normalized;
}

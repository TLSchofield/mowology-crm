<?php
/**
 * Media Variant Generator
 *
 * Generates responsive image variants (JPEG, WebP, AVIF) at multiple widths
 * and square thumbnails for the unified media system.
 *
 * Target widths: 320, 480, 640, 768, 1024, 1280, 1600, 1920
 * Never upscales. Strips metadata. Uses GD (Imagick as fallback).
 *
 * @package Mowology CRM
 * @subpackage Media
 */

declare(strict_types=1);

const VARIANT_WIDTHS = [320, 480, 640, 768, 1024, 1280, 1600, 1920];
const VARIANT_JPEG_QUALITY = 85;
const VARIANT_WEBP_QUALITY = 82;
const VARIANT_AVIF_QUALITY = 60;
const VARIANT_THUMB_SIZE = 300; // Square thumbnail dimension

/**
 * Generate all responsive variants + square thumbnails for a media asset.
 *
 * @param int    $mediaId      media_assets.id
 * @param string $originalPath Absolute path to the original file
 * @return array Summary of generated variants
 */
function generateMediaVariants(int $mediaId, string $originalPath): array
{
    if (!file_exists($originalPath)) {
        error_log("generateMediaVariants: source not found: $originalPath");
        return ['success' => false, 'error' => 'Source file not found'];
    }

    $imgInfo = @getimagesize($originalPath);
    if (!$imgInfo) {
        error_log("generateMediaVariants: invalid image: $originalPath");
        return ['success' => false, 'error' => 'Invalid image'];
    }

    $origW = $imgInfo[0];
    $origH = $imgInfo[1];

    // Get media record for UUID and date
    $db = getDB();
    $stmt = $db->prepare('SELECT uuid, created_at FROM media_assets WHERE id = ?');
    $stmt->execute([$mediaId]);
    $media = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$media) {
        return ['success' => false, 'error' => 'Media record not found'];
    }

    $uuid = $media['uuid'];
    $yearMonth = date('Y/m', strtotime($media['created_at']));

    $variantsDir = MEDIA_ROOT . '/variants/' . $yearMonth;
    $thumbsDir = MEDIA_ROOT . '/thumbs/' . $yearMonth;

    if (!is_dir($variantsDir)) mkdir($variantsDir, 0755, true);
    if (!is_dir($thumbsDir)) mkdir($thumbsDir, 0755, true);

    $generated = [];
    $totalBytes = 0;

    // --- Responsive variants at each target width ---
    foreach (VARIANT_WIDTHS as $targetW) {
        if ($targetW >= $origW) continue; // Never upscale

        $targetH = (int)round($origH * ($targetW / $origW));

        // JPEG variant
        $jpegFile = "{$uuid}_{$targetW}w.jpg";
        $jpegPath = $variantsDir . '/' . $jpegFile;
        $jpegWeb = '/_media/variants/' . $yearMonth . '/' . $jpegFile;
        if (variantResize($originalPath, $jpegPath, $targetW, $targetH, 'jpeg', VARIANT_JPEG_QUALITY)) {
            $size = filesize($jpegPath);
            variantInsertRecord($db, $mediaId, 'responsive', 'jpeg', $targetW, $targetH, $jpegWeb, $size, VARIANT_JPEG_QUALITY);
            $generated[] = $jpegWeb;
            $totalBytes += $size;
        }

        // WebP variant
        $webpFile = "{$uuid}_{$targetW}w.webp";
        $webpPath = $variantsDir . '/' . $webpFile;
        $webpWeb = '/_media/variants/' . $yearMonth . '/' . $webpFile;
        if (variantResize($originalPath, $webpPath, $targetW, $targetH, 'webp', VARIANT_WEBP_QUALITY)) {
            $size = filesize($webpPath);
            variantInsertRecord($db, $mediaId, 'responsive', 'webp', $targetW, $targetH, $webpWeb, $size, VARIANT_WEBP_QUALITY);
            $generated[] = $webpWeb;
            $totalBytes += $size;
        }

        // AVIF variant (only if GD supports it — PHP 8.1+ with libavif)
        if (function_exists('imageavif')) {
            $avifFile = "{$uuid}_{$targetW}w.avif";
            $avifPath = $variantsDir . '/' . $avifFile;
            $avifWeb = '/_media/variants/' . $yearMonth . '/' . $avifFile;
            if (variantResize($originalPath, $avifPath, $targetW, $targetH, 'avif', VARIANT_AVIF_QUALITY)) {
                $size = filesize($avifPath);
                variantInsertRecord($db, $mediaId, 'responsive', 'avif', $targetW, $targetH, $avifWeb, $size, VARIANT_AVIF_QUALITY);
                $generated[] = $avifWeb;
                $totalBytes += $size;
            }
        }
    }

    // --- Square thumbnail (center crop) ---
    $thumbJpg = $thumbsDir . '/' . $uuid . '_sq' . VARIANT_THUMB_SIZE . '.jpg';
    $thumbJpgWeb = '/_media/thumbs/' . $yearMonth . '/' . $uuid . '_sq' . VARIANT_THUMB_SIZE . '.jpg';
    if (variantCropSquare($originalPath, $thumbJpg, VARIANT_THUMB_SIZE, 'jpeg')) {
        $size = filesize($thumbJpg);
        variantInsertRecord($db, $mediaId, 'thumb_square', 'jpeg', VARIANT_THUMB_SIZE, VARIANT_THUMB_SIZE, $thumbJpgWeb, $size, VARIANT_JPEG_QUALITY);
        $generated[] = $thumbJpgWeb;
        $totalBytes += $size;
    }

    $thumbWebp = $thumbsDir . '/' . $uuid . '_sq' . VARIANT_THUMB_SIZE . '.webp';
    $thumbWebpWeb = '/_media/thumbs/' . $yearMonth . '/' . $uuid . '_sq' . VARIANT_THUMB_SIZE . '.webp';
    if (variantCropSquare($originalPath, $thumbWebp, VARIANT_THUMB_SIZE, 'webp')) {
        $size = filesize($thumbWebp);
        variantInsertRecord($db, $mediaId, 'thumb_square', 'webp', VARIANT_THUMB_SIZE, VARIANT_THUMB_SIZE, $thumbWebpWeb, $size, VARIANT_WEBP_QUALITY);
        $generated[] = $thumbWebpWeb;
        $totalBytes += $size;
    }

    // --- Update media_assets status to ready ---
    $db->prepare('UPDATE media_assets SET status = ? WHERE id = ?')->execute(['ready', $mediaId]);

    return [
        'success' => true,
        'variants_count' => count($generated),
        'total_bytes' => $totalBytes,
        'thumb_url' => $thumbJpgWeb ?? null,
        'variants' => $generated,
    ];
}

/**
 * Resize an image and save in the specified format.
 */
function variantResize(string $source, string $dest, int $width, int $height, string $format, int $quality): bool
{
    if (!extension_loaded('gd')) {
        return false;
    }

    try {
        $srcInfo = @getimagesize($source);
        if (!$srcInfo) return false;

        $image = variantLoadImage($source, $srcInfo[2]);
        if (!$image) return false;

        // Create resized canvas
        $resized = imagecreatetruecolor($width, $height);
        if (!$resized) {
            imagedestroy($image);
            return false;
        }

        // Preserve alpha for PNG/WebP
        if (in_array($srcInfo[2], [IMAGETYPE_PNG, IMAGETYPE_WEBP])) {
            imagealphablending($resized, false);
            imagesavealpha($resized, true);
        }

        imagecopyresampled($resized, $image, 0, 0, 0, 0, $width, $height, $srcInfo[0], $srcInfo[1]);
        imagedestroy($image);

        $success = variantSaveImage($resized, $dest, $format, $quality);
        imagedestroy($resized);

        return $success;
    } catch (Exception $e) {
        error_log("variantResize error: " . $e->getMessage());
        return false;
    }
}

/**
 * Crop an image to a square (center crop) and save.
 */
function variantCropSquare(string $source, string $dest, int $size, string $format = 'jpeg'): bool
{
    if (!extension_loaded('gd')) {
        return false;
    }

    try {
        $srcInfo = @getimagesize($source);
        if (!$srcInfo) return false;

        $image = variantLoadImage($source, $srcInfo[2]);
        if (!$image) return false;

        $srcW = $srcInfo[0];
        $srcH = $srcInfo[1];

        // Calculate center crop area (square)
        $cropSize = min($srcW, $srcH);
        $cropX = (int)(($srcW - $cropSize) / 2);
        $cropY = (int)(($srcH - $cropSize) / 2);

        // Create square canvas
        $thumb = imagecreatetruecolor($size, $size);
        if (!$thumb) {
            imagedestroy($image);
            return false;
        }

        imagecopyresampled($thumb, $image, 0, 0, $cropX, $cropY, $size, $size, $cropSize, $cropSize);
        imagedestroy($image);

        $quality = ($format === 'webp') ? VARIANT_WEBP_QUALITY : VARIANT_JPEG_QUALITY;
        $success = variantSaveImage($thumb, $dest, $format, $quality);
        imagedestroy($thumb);

        return $success;
    } catch (Exception $e) {
        error_log("variantCropSquare error: " . $e->getMessage());
        return false;
    }
}

/**
 * Load an image from file using GD, based on image type.
 * @return resource|false
 */
function variantLoadImage(string $path, int $imageType)
{
    switch ($imageType) {
        case IMAGETYPE_JPEG:
            return @imagecreatefromjpeg($path);
        case IMAGETYPE_PNG:
            return @imagecreatefrompng($path);
        case IMAGETYPE_GIF:
            return @imagecreatefromgif($path);
        case IMAGETYPE_WEBP:
            return @imagecreatefromwebp($path);
        default:
            return false;
    }
}

/**
 * Save a GD image resource in the specified format.
 * @param resource $image
 */
function variantSaveImage($image, string $dest, string $format, int $quality): bool
{
    switch ($format) {
        case 'jpeg':
        case 'jpg':
            return imagejpeg($image, $dest, $quality);
        case 'webp':
            return imagewebp($image, $dest, $quality);
        case 'avif':
            if (function_exists('imageavif')) {
                return imageavif($image, $dest, $quality);
            }
            return false;
        case 'png':
            // PNG uses compression level 0-9 (not quality percentage)
            return imagepng($image, $dest, 6);
        default:
            return false;
    }
}

/**
 * Insert a variant record into the media_variants table.
 */
function variantInsertRecord(PDO $db, int $mediaId, string $variantType, string $format, int $width, int $height, string $filePath, int $fileSize, int $quality): void
{
    try {
        $stmt = $db->prepare('
            INSERT INTO media_variants (media_id, variant_type, format, width, height, file_path, file_size, quality)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?)
            ON DUPLICATE KEY UPDATE file_path = VALUES(file_path), file_size = VALUES(file_size), quality = VALUES(quality)
        ');
        $stmt->execute([$mediaId, $variantType, $format, $width, $height, $filePath, $fileSize, $quality]);
    } catch (PDOException $e) {
        error_log("variantInsertRecord error: " . $e->getMessage());
    }
}

/**
 * Get the square thumbnail URL for a media asset.
 */
function getMediaThumbUrl(int $mediaId): ?string
{
    $db = getDB();
    $stmt = $db->prepare('
        SELECT file_path FROM media_variants
        WHERE media_id = ? AND variant_type = ? AND format = ?
        LIMIT 1
    ');
    $stmt->execute([$mediaId, 'thumb_square', 'jpeg']);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    return $row ? $row['file_path'] : null;
}

/**
 * Get variant summary for a media asset.
 */
function getMediaVariantSummary(int $mediaId): array
{
    $db = getDB();
    $stmt = $db->prepare('
        SELECT format, COUNT(*) as count, SUM(file_size) as total_bytes
        FROM media_variants
        WHERE media_id = ?
        GROUP BY format
    ');
    $stmt->execute([$mediaId]);
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

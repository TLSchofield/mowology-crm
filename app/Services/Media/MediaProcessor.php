<?php
/**
 * Media Processor - Image Optimization Pipeline
 *
 * Automatically generates WebP variants and responsive sizes for uploaded images.
 * Integrates with media upload workflow.
 *
 * @package Mowology CRM
 * @subpackage CMS Media
 *
 * Migrated from: public/crm/includes/media-processor.php
 */

declare(strict_types=1);

// ============================================================================
// IMAGE PROCESSING CONFIGURATION
// ============================================================================

const MEDIA_PROCESSOR_SIZES = [
    'thumb' => 200,      // Thumbnail for media picker
    'small' => 640,      // Mobile width
    'medium' => 1024,    // Tablet width
    'large' => 1440,     // Desktop width
    'xlarge' => 1920,    // Large desktop
];

const MEDIA_PROCESSOR_QUALITY = 85;  // JPEG/WebP quality (0-100)
const MEDIA_PROCESSOR_MAX_SIZE = 5242880;  // 5 MB max for images

/**
 * Process uploaded image: generate WebP + responsive sizes
 *
 * @param string $sourcePath Path to original uploaded file
 * @param int $mediaId Database media ID (for naming)
 * @return array Metadata with paths to all variants
 * @throws Exception If processing fails
 */
function mp_processUploadedImage(string $sourcePath, int $mediaId): array
{
    if (!file_exists($sourcePath)) {
        throw new Exception("Source file not found: $sourcePath");
    }

    // Get image info
    $info = @getimagesize($sourcePath);
    if (!$info) {
        throw new Exception("Invalid image file");
    }

    list($originalWidth, $originalHeight) = $info;
    $mimeType = $info['mime'] ?? '';

    if (!in_array($mimeType, ['image/jpeg', 'image/png', 'image/webp', 'image/gif'])) {
        throw new Exception("Unsupported image type: $mimeType");
    }

    $uploadDir = dirname($sourcePath);
    $filename = basename($sourcePath);
    $nameWithoutExt = pathinfo($filename, PATHINFO_FILENAME);

    $variants = [];
    $sizes = [];

    // Generate responsive sizes
    foreach (MEDIA_PROCESSOR_SIZES as $sizeKey => $width) {
        // Skip if original is smaller
        if ($width > $originalWidth) {
            continue;
        }

        // Calculate proportional height
        $height = (int)($originalHeight * ($width / $originalWidth));

        // Generate sized image
        $sizedPath = "$uploadDir/{$nameWithoutExt}_{$width}w.jpg";
        if (mp_resizeImage($sourcePath, $sizedPath, $width, $height)) {
            $variants[$sizeKey] = [
                'path' => $sizedPath,
                'width' => $width,
                'height' => $height,
                'size' => filesize($sizedPath),
            ];
            $sizes[$width] = "/{$nameWithoutExt}_{$width}w.jpg";
        }

        // Generate WebP variant
        $webpPath = "$uploadDir/{$nameWithoutExt}_{$width}w.webp";
        if (mp_generateWebP($sourcePath, $webpPath, $width, $height)) {
            $variants["{$sizeKey}_webp"] = [
                'path' => $webpPath,
                'width' => $width,
                'height' => $height,
                'format' => 'webp',
                'size' => filesize($webpPath),
            ];
        }
    }

    // Generate thumbnail
    $thumbPath = "$uploadDir/{$nameWithoutExt}_thumb.jpg";
    if (mp_resizeImage($sourcePath, $thumbPath, MEDIA_PROCESSOR_SIZES['thumb'],
        (int)(MEDIA_PROCESSOR_SIZES['thumb'] * ($originalHeight / $originalWidth)))) {
        $variants['thumb'] = [
            'path' => $thumbPath,
            'width' => MEDIA_PROCESSOR_SIZES['thumb'],
        ];
    }

    return [
        'success' => true,
        'original_width' => $originalWidth,
        'original_height' => $originalHeight,
        'variants' => $variants,
        'sizes_json' => json_encode($sizes),
        'total_size' => array_sum(array_map(fn($v) => $v['size'] ?? 0, $variants)),
    ];
}

/**
 * Resize image to specified dimensions
 *
 * @param string $source Source image path
 * @param string $dest Destination path
 * @param int $width Target width
 * @param int $height Target height
 * @return bool Success
 */
function mp_resizeImage(string $source, string $dest, int $width, int $height): bool
{
    try {
        // Try using GD library
        if (extension_loaded('gd')) {
            return mp_resizeImageGD($source, $dest, $width, $height);
        }

        // Fallback: try ImageMagick
        if (function_exists('exec') && shell_exec('which convert')) {
            return mp_resizeImageMagick($source, $dest, $width, $height);
        }

        // No image processing available
        return false;
    } catch (Exception $e) {
        error_log("Image resize failed: " . $e->getMessage());
        return false;
    }
}

/**
 * Resize image using GD library
 *
 * @param string $source Source path
 * @param string $dest Destination path
 * @param int $width Target width
 * @param int $height Target height
 * @return bool Success
 */
function mp_resizeImageGD(string $source, string $dest, int $width, int $height): bool
{
    try {
        $info = @getimagesize($source);
        if (!$info) {
            return false;
        }

        // Load source image based on type
        $image = null;
        switch ($info[2]) {
            case IMAGETYPE_JPEG:
                $image = @imagecreatefromjpeg($source);
                break;
            case IMAGETYPE_PNG:
                $image = @imagecreatefrompng($source);
                break;
            case IMAGETYPE_GIF:
                $image = @imagecreatefromgif($source);
                break;
            case IMAGETYPE_WEBP:
                $image = @imagecreatefromwebp($source);
                break;
            default:
                return false;
        }

        if (!$image) {
            return false;
        }

        // Create resized image
        $resized = imagecreatetruecolor($width, $height);
        if (!$resized) {
            imagedestroy($image);
            return false;
        }

        // Preserve transparency for PNG
        if ($info[2] === IMAGETYPE_PNG) {
            imagealphablending($resized, false);
            imagesavealpha($resized, true);
        }

        // Resample
        imagecopyresampled($resized, $image, 0, 0, 0, 0, $width, $height, $info[0], $info[1]);

        // Save as JPEG
        $success = imagejpeg($resized, $dest, MEDIA_PROCESSOR_QUALITY);

        imagedestroy($image);
        imagedestroy($resized);

        return $success;
    } catch (Exception $e) {
        error_log("GD resize error: " . $e->getMessage());
        return false;
    }
}

/**
 * Resize image using ImageMagick convert command
 *
 * @param string $source Source path
 * @param string $dest Destination path
 * @param int $width Target width
 * @param int $height Target height
 * @return bool Success
 */
function mp_resizeImageMagick(string $source, string $dest, int $width, int $height): bool
{
    try {
        // Security: sanitize paths
        $source = escapeshellarg($source);
        $dest = escapeshellarg($dest);

        $cmd = "convert $source -resize {$width}x{$height}! -quality " . MEDIA_PROCESSOR_QUALITY . " $dest";
        $output = [];
        $returnCode = 0;

        @exec($cmd, $output, $returnCode);

        return $returnCode === 0 && file_exists($dest);
    } catch (Exception $e) {
        error_log("ImageMagick resize error: " . $e->getMessage());
        return false;
    }
}

/**
 * Generate WebP variant of image
 *
 * @param string $source Source path
 * @param string $dest Destination WebP path
 * @param int $width Target width (optional)
 * @param int $height Target height (optional)
 * @return bool Success
 */
function mp_generateWebP(string $source, string $dest, ?int $width = null, ?int $height = null): bool
{
    try {
        // Try GD library WebP support
        if (extension_loaded('gd')) {
            $info = @getimagesize($source);
            if (!$info) {
                return false;
            }

            // Load source
            $image = null;
            switch ($info[2]) {
                case IMAGETYPE_JPEG:
                    $image = @imagecreatefromjpeg($source);
                    break;
                case IMAGETYPE_PNG:
                    $image = @imagecreatefrompng($source);
                    break;
                case IMAGETYPE_WEBP:
                    $image = @imagecreatefromwebp($source);
                    break;
                default:
                    return false;
            }

            if (!$image) {
                return false;
            }

            // Resize if specified
            if ($width && $height) {
                $resized = imagecreatetruecolor($width, $height);
                if (!$resized) {
                    imagedestroy($image);
                    return false;
                }

                imagecopyresampled($resized, $image, 0, 0, 0, 0, $width, $height, $info[0], $info[1]);
                imagedestroy($image);
                $image = $resized;
            }

            // Save as WebP
            $success = imagewebp($image, $dest, MEDIA_PROCESSOR_QUALITY);
            imagedestroy($image);

            return $success;
        }

        // Fallback: try cwebp command
        if (shell_exec('which cwebp')) {
            $source = escapeshellarg($source);
            $dest = escapeshellarg($dest);

            $cmd = "cwebp $source -o $dest -q " . MEDIA_PROCESSOR_QUALITY;
            if ($width && $height) {
                $cmd .= " -resize $width $height";
            }

            $output = [];
            $returnCode = 0;
            @exec($cmd, $output, $returnCode);

            return $returnCode === 0 && file_exists($dest);
        }

        return false;
    } catch (Exception $e) {
        error_log("WebP generation failed: " . $e->getMessage());
        return false;
    }
}

/**
 * Clean up processed variants for a media file
 *
 * @param int $mediaId Media ID
 * @param string $basePath Base upload directory
 * @return bool Success
 */
function mp_cleanupMediaVariants(int $mediaId, string $basePath): bool
{
    try {
        // Find all variant files for this media
        $pattern = preg_quote(basename($basePath), '/');
        $variants = glob(dirname($basePath) . "/{$pattern}_{*,thumb}.{jpg,webp}", GLOB_BRACE);

        foreach ($variants as $file) {
            if (file_exists($file)) {
                @unlink($file);
            }
        }

        return true;
    } catch (Exception $e) {
        error_log("Cleanup failed: " . $e->getMessage());
        return false;
    }
}

/**
 * Suggest alt text for image
 *
 * @param string $filename Original filename
 * @param ?string $service Service key (optional)
 * @param ?string $neighbourhood Neighbourhood key (optional)
 * @return string Suggested alt text
 */
function mp_suggestAltText(string $filename, ?string $service = null, ?string $neighbourhood = null): string
{
    // Clean filename: remove extension, underscores, hyphens
    $base = pathinfo($filename, PATHINFO_FILENAME);
    $base = str_replace(['-', '_'], ' ', $base);

    // Build suggestion from context
    $parts = [];

    if ($service) {
        $parts[] = ucwords(str_replace('-', ' ', $service));
    }

    if ($neighbourhood) {
        $parts[] = "in " . ucwords(str_replace('-', ' ', $neighbourhood));
    }

    $parts[] = "Vancouver";

    if (preg_match('/before|after|before_after/i', $base)) {
        $parts[] = "— before and after";
    } elseif (preg_match('/result|complete|finished/i', $base)) {
        $parts[] = "— completed project";
    }

    return implode(', ', $parts);
}

/**
 * Generate responsive image HTML with picture element
 *
 * @param int $mediaId Media ID
 * @param array $media Media record from DB
 * @param ?string $alt Alt text (fallback to media.alt_text)
 * @param string $class CSS class (optional)
 * @return string HTML picture element
 */
function mp_renderResponsiveImage(int $mediaId, array $media, ?string $alt = null, string $class = ''): string
{
    $alt = h($alt ?? $media['alt_text'] ?? '');
    $filePath = h($media['file_path']);
    $webpPath = h($media['webp_path'] ?? '');
    $sizesJson = $media['sizes_json'] ?? '{}';
    $sizes = json_decode($sizesJson, true) ?? [];

    // Start picture element
    $html = '<picture>';

    // WebP sources (modern browsers)
    if ($webpPath && !empty($sizes)) {
        $html .= '<source type="image/webp" srcset="';
        $srcset = [];
        foreach ($sizes as $width => $path) {
            $srcset[] = "{$path} {$width}w";
        }
        $html .= implode(', ', $srcset) . '">';
    }

    // JPEG fallback
    if (!empty($sizes)) {
        $html .= '<source type="image/jpeg" srcset="';
        $srcset = [];
        foreach ($sizes as $width => $path) {
            // Convert webp path back to jpg
            $jpgPath = str_replace('.webp', '.jpg', $path);
            $srcset[] = "{$jpgPath} {$width}w";
        }
        $html .= implode(', ', $srcset) . '">';
    }

    // Image element (fallback)
    $classList = $class ? " class=\"$class\"" : '';
    $html .= "<img src=\"$filePath\" alt=\"$alt\" width=\"" . ($media['source_width'] ?? 800) . "\" height=\"" . ($media['source_height'] ?? 600) . "\"$classList>";

    $html .= '</picture>';

    return $html;
}

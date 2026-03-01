<?php
/**
 * IconGenerator — shared GD image processing for icon set generation.
 *
 * Included by both api-product-icons.php (legacy product-scoped) and
 * api-icon-sets.php (media-manager icon sets).
 *
 * Sizes generated per set: 1024, 512, 256, 192, 128, 64, 32 px (square PNG)
 * Variants: sold (full colour) + unsold (greyscale with brightness lift)
 *
 * @package Mowology CRM
 * @subpackage Services/Media
 */

declare(strict_types=1);

if (!defined('ICON_SIZES')) {
    define('ICON_SIZES',    [1024, 512, 256, 192, 128, 64, 32]);
}
if (!defined('ICON_MAX_BYTES')) {
    define('ICON_MAX_BYTES',  5 * 1024 * 1024); // 5 MB
}
if (!defined('ICON_PNG_COMPRESS')) {
    define('ICON_PNG_COMPRESS', 2);              // PNG 0–9 (2 = fast + decent size)
}
if (!defined('ICON_GREY_BRIGHT')) {
    define('ICON_GREY_BRIGHT',  18);             // brightness lift for unsold variant
}
if (!defined('ICON_GREY_CONTRAST')) {
    define('ICON_GREY_CONTRAST', -8);            // contrast reduction for unsold variant
}

// ─────────────────────────────────────────────────────────────────────────────

/**
 * Generate a full icon set (sold + unsold variants, all sizes) from a source image.
 *
 * @param  string $srcPath   Filesystem path to the source image
 * @param  string $mimeType  MIME type of the source ('image/jpeg', 'image/png', 'image/webp')
 * @param  string $outDir    Filesystem path to the output directory (created if needed)
 * @return array<string>     List of files that failed to generate (empty = all ok)
 */
function icongen_generateSet(string $srcPath, string $mimeType, string $outDir): array
{
    if (!is_dir($outDir) && !mkdir($outDir, 0755, true)) {
        return ['[mkdir failed]'];
    }

    $src = icongen_loadSource($srcPath, $mimeType);
    if ($src === null) {
        return ['[source image load failed]'];
    }

    $failed = [];
    foreach (ICON_SIZES as $size) {
        // Sold — full colour
        $sold = icongen_resizeCanvas($src, $size);
        if ($sold === null) {
            $failed[] = "icon_{$size}_sold";
        } else {
            imagepng($sold, $outDir . '/icon_' . $size . '_sold.png', ICON_PNG_COMPRESS);
            imagedestroy($sold);
        }

        // Unsold — greyscale with brightness lift
        $unsold = icongen_resizeCanvas($src, $size);
        if ($unsold === null) {
            $failed[] = "icon_{$size}_unsold";
        } else {
            icongen_applyUnsoldEffect($unsold);
            imagepng($unsold, $outDir . '/icon_' . $size . '_unsold.png', ICON_PNG_COMPRESS);
            imagedestroy($unsold);
        }
    }

    imagedestroy($src);
    return $failed;
}

/**
 * Delete all PNG files in an icon set directory and remove it if empty.
 */
function icongen_deleteSet(string $dir): void
{
    if (!is_dir($dir)) {
        return;
    }
    $files = glob($dir . '/*.png') ?: [];
    foreach ($files as $f) {
        @unlink($f);
    }
    @rmdir($dir);
}

// ─────────────────────────────────────────────────────────────────────────────
// Internal helpers
// ─────────────────────────────────────────────────────────────────────────────

/**
 * Load a GD image resource from a file (JPEG / PNG / WEBP).
 */
function icongen_loadSource(string $path, string $mime): ?\GdImage
{
    return match ($mime) {
        'image/jpeg' => imagecreatefromjpeg($path) ?: null,
        'image/png'  => imagecreatefrompng($path)  ?: null,
        'image/webp' => function_exists('imagecreatefromwebp')
                          ? (imagecreatefromwebp($path) ?: null)
                          : null,
        default      => null,
    };
}

/**
 * Save the source image in its original format.
 */
function icongen_saveOriginal(\GdImage $src, string $destPath, string $mime): void
{
    match ($mime) {
        'image/jpeg' => imagejpeg($src, $destPath, 85),
        'image/png'  => imagepng($src, $destPath, ICON_PNG_COMPRESS),
        'image/webp' => function_exists('imagewebp')
                          ? imagewebp($src, $destPath, 85)
                          : imagejpeg($src, $destPath, 85),
        default      => imagejpeg($src, $destPath, 85),
    };
}

/**
 * Create a square resized canvas at $size × $size (cover-crop, centred, alpha preserved).
 */
function icongen_resizeCanvas(\GdImage $src, int $size): ?\GdImage
{
    $srcW = imagesx($src);
    $srcH = imagesy($src);

    if ($srcW <= 0 || $srcH <= 0) {
        return null;
    }

    $scale   = max($size / $srcW, $size / $srcH);
    $fitW    = (int)round($srcW * $scale);
    $fitH    = (int)round($srcH * $scale);
    $offsetX = (int)round(($fitW - $size) / 2);
    $offsetY = (int)round(($fitH - $size) / 2);

    $canvas = imagecreatetruecolor($size, $size);
    if ($canvas === false) {
        return null;
    }

    imagealphablending($canvas, false);
    imagesavealpha($canvas, true);
    $transparent = imagecolorallocatealpha($canvas, 0, 0, 0, 127);
    imagefill($canvas, 0, 0, $transparent);
    imagealphablending($canvas, true);

    $intermediate = imagecreatetruecolor($fitW, $fitH);
    if ($intermediate === false) {
        imagedestroy($canvas);
        return null;
    }
    imagealphablending($intermediate, false);
    imagesavealpha($intermediate, true);
    imagefill($intermediate, 0, 0, imagecolorallocatealpha($intermediate, 0, 0, 0, 127));
    imagealphablending($intermediate, true);

    imagecopyresampled($intermediate, $src, 0, 0, 0, 0, $fitW, $fitH, $srcW, $srcH);
    imagecopyresampled($canvas, $intermediate, 0, 0, $offsetX, $offsetY, $size, $size, $size, $size);

    imagedestroy($intermediate);
    return $canvas;
}

/**
 * Apply unsold greyscale effect in-place (greyscale + slight brightness lift).
 */
function icongen_applyUnsoldEffect(\GdImage $img): void
{
    imagefilter($img, IMG_FILTER_GRAYSCALE);
    imagefilter($img, IMG_FILTER_BRIGHTNESS, ICON_GREY_BRIGHT);
    imagefilter($img, IMG_FILTER_CONTRAST, ICON_GREY_CONTRAST);
}

/**
 * Map MIME type to a file extension.
 */
function icongen_mimeToExtension(string $mime): string
{
    return match ($mime) {
        'image/jpeg' => 'jpg',
        'image/png'  => 'png',
        'image/webp' => 'webp',
        default      => 'jpg',
    };
}

/**
 * Human-readable upload error message.
 */
function icongen_uploadErrorMessage(int $code): string
{
    return match ($code) {
        UPLOAD_ERR_INI_SIZE   => 'File exceeds server limit',
        UPLOAD_ERR_FORM_SIZE  => 'File exceeds form limit',
        UPLOAD_ERR_PARTIAL    => 'File only partially uploaded',
        UPLOAD_ERR_NO_FILE    => 'No file was uploaded',
        UPLOAD_ERR_NO_TMP_DIR => 'Server has no temp directory',
        UPLOAD_ERR_CANT_WRITE => 'Server could not write file',
        UPLOAD_ERR_EXTENSION  => 'Upload blocked by server extension',
        default               => 'Unknown error (code ' . $code . ')',
    };
}

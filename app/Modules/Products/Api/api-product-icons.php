<?php
/**
 * Product Icon Generator API
 *
 * Accepts a product image upload, generates a full icon set in two variants:
 *   - sold   (full colour)
 *   - unsold (greyscale with slight brightness lift — professional, not washed out)
 *
 * Sizes generated: 1024, 512, 256, 192, 128, 64, 32 px (square)
 * Output format:   PNG (preserves transparency, lossless)
 * Output path:     /uploads/products/icons/{product_id}/icon_{size}_{variant}.png
 *
 * POST params (multipart/form-data):
 *   file        - image file (JPEG / PNG / WEBP, max 5 MB)
 *   product_id  - integer
 *   csrf_token  - CSRF token
 *
 * Returns JSON: { success, icon_base_path, preview_256_sold, preview_256_unsold }
 */

declare(strict_types=1);

// ── Bootstrap ──────────────────────────────────────────────────────────────

$__dir = __DIR__;
for ($__i = 0; $__i < 6; $__i++) {
    if (is_file($__dir . '/app/Core/paths.php')) {
        require_once $__dir . '/app/Core/paths.php';
        break;
    }
    $__dir = dirname($__dir);
}

if (!defined('PUBLIC_ROOT')) {
    http_response_code(500);
    echo json_encode(['error' => 'Bootstrap failed']);
    exit;
}

require_once PUBLIC_ROOT . '/loginAuth/auth.php';
requireLogin();
requirePermission('products.edit');

header('Content-Type: application/json');

// ── Constants ──────────────────────────────────────────────────────────────

const ICON_SIZES   = [1024, 512, 256, 192, 128, 64, 32];
const MAX_BYTES    = 5 * 1024 * 1024; // 5 MB
const PNG_COMPRESS = 2;               // PNG level 0–9 (2 = fast + decent size)
const GREY_BRIGHT  = 18;              // brightness lift for unsold variant (–255 to +255)
const GREY_CONTRAST = -8;            // contrast reduction for unsold variant

// ── Request guard ──────────────────────────────────────────────────────────

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['error' => 'Method not allowed']);
    exit;
}

$action = $_POST['action'] ?? '';

if ($action === 'upload-icon') {
    handleUploadIcon();
} elseif ($action === 'delete-icons') {
    handleDeleteIcons();
} else {
    http_response_code(400);
    echo json_encode(['error' => 'Unknown action']);
    exit;
}

// ══════════════════════════════════════════════════════════════════════════════
// ACTION: upload-icon
// ══════════════════════════════════════════════════════════════════════════════

function handleUploadIcon(): void
{
    // CSRF
    if (!verifyCSRFToken($_POST['csrf_token'] ?? '')) {
        http_response_code(403);
        echo json_encode(['error' => 'Invalid CSRF token']);
        exit;
    }

    // Product ID
    $productId = (int)($_POST['product_id'] ?? 0);
    if ($productId <= 0) {
        echo json_encode(['error' => 'Invalid product ID']);
        exit;
    }

    // Verify product exists and user can edit it
    $db = getDB();
    $product = $db->prepare('SELECT id FROM products WHERE id = ? AND is_archived = 0 LIMIT 1');
    $product->execute([$productId]);
    if (!$product->fetch()) {
        echo json_encode(['error' => 'Product not found']);
        exit;
    }

    // File presence
    if (empty($_FILES['file']) || $_FILES['file']['error'] !== UPLOAD_ERR_OK) {
        $errCode = $_FILES['file']['error'] ?? -1;
        echo json_encode(['error' => 'Upload error: ' . uploadErrorMessage($errCode)]);
        exit;
    }

    $file = $_FILES['file'];

    // Size check
    if ($file['size'] > MAX_BYTES) {
        echo json_encode(['error' => 'File exceeds 5 MB limit']);
        exit;
    }

    // MIME validation using finfo (not just extension)
    $finfo    = finfo_open(FILEINFO_MIME_TYPE);
    $mimeType = finfo_file($finfo, $file['tmp_name']);
    finfo_close($finfo);

    $allowedMimes = ['image/jpeg', 'image/png', 'image/webp'];
    if (!in_array($mimeType, $allowedMimes, true)) {
        echo json_encode(['error' => 'Only JPEG, PNG, and WEBP images are accepted']);
        exit;
    }

    // ── Load source image ────────────────────────────────────────────────────

    $src = loadSourceImage($file['tmp_name'], $mimeType);
    if ($src === null) {
        echo json_encode(['error' => 'Could not read image. File may be corrupt.']);
        exit;
    }

    // ── Prepare directories ──────────────────────────────────────────────────

    $webRoot    = PUBLIC_ROOT;
    $origDir    = $webRoot . '/uploads/products/original';
    $iconsDir   = $webRoot . '/uploads/products/icons/' . $productId;

    foreach ([$origDir, $iconsDir] as $dir) {
        if (!is_dir($dir) && !mkdir($dir, 0755, true)) {
            imagedestroy($src);
            echo json_encode(['error' => 'Could not create upload directory']);
            exit;
        }
    }

    // ── Save original ────────────────────────────────────────────────────────

    $ext      = mimeToExtension($mimeType);
    $origFile = $origDir . '/' . $productId . '_' . time() . '.' . $ext;
    saveOriginal($src, $origFile, $mimeType);

    // ── Generate icon set ────────────────────────────────────────────────────

    $failed = [];
    foreach (ICON_SIZES as $size) {
        // Sold variant — full colour
        $soldCanvas = resizeCanvas($src, $size);
        if ($soldCanvas === null) {
            $failed[] = "icon_{$size}_sold";
        } else {
            $soldPath = $iconsDir . '/icon_' . $size . '_sold.png';
            imagepng($soldCanvas, $soldPath, PNG_COMPRESS);
            imagedestroy($soldCanvas);
        }

        // Unsold variant — greyscale with slight brightness lift
        $unsoldCanvas = resizeCanvas($src, $size);
        if ($unsoldCanvas === null) {
            $failed[] = "icon_{$size}_unsold";
        } else {
            applyUnsoldEffect($unsoldCanvas);
            $unsoldPath = $iconsDir . '/icon_' . $size . '_unsold.png';
            imagepng($unsoldCanvas, $unsoldPath, PNG_COMPRESS);
            imagedestroy($unsoldCanvas);
        }
    }

    imagedestroy($src);

    if (!empty($failed)) {
        echo json_encode(['error' => 'Some icons failed to generate: ' . implode(', ', $failed)]);
        exit;
    }

    // ── Update database ──────────────────────────────────────────────────────

    $iconBasePath = '/uploads/products/icons/' . $productId . '/';

    // Check column exists (migration-safe)
    $cols    = $db->query("SHOW COLUMNS FROM `products` LIKE 'icon_base_path'")->fetchAll();
    $hasCol  = !empty($cols);

    if ($hasCol) {
        $db->prepare('UPDATE products SET icon_base_path = ? WHERE id = ?')
           ->execute([$iconBasePath, $productId]);
    }

    echo json_encode([
        'success'           => true,
        'icon_base_path'    => $iconBasePath,
        'preview_256_sold'  => $iconBasePath . 'icon_256_sold.png',
        'preview_256_unsold'=> $iconBasePath . 'icon_256_unsold.png',
        'preview_64_sold'   => $iconBasePath . 'icon_64_sold.png',
        'preview_64_unsold' => $iconBasePath . 'icon_64_unsold.png',
    ]);
}

// ══════════════════════════════════════════════════════════════════════════════
// ACTION: delete-icons
// ══════════════════════════════════════════════════════════════════════════════

function handleDeleteIcons(): void
{
    if (!verifyCSRFToken($_POST['csrf_token'] ?? '')) {
        http_response_code(403);
        echo json_encode(['error' => 'Invalid CSRF token']);
        exit;
    }

    $productId = (int)($_POST['product_id'] ?? 0);
    if ($productId <= 0) {
        echo json_encode(['error' => 'Invalid product ID']);
        exit;
    }

    $iconsDir = PUBLIC_ROOT . '/uploads/products/icons/' . $productId;
    if (is_dir($iconsDir)) {
        $files = glob($iconsDir . '/*.png');
        if ($files) {
            foreach ($files as $f) {
                @unlink($f);
            }
        }
        @rmdir($iconsDir);
    }

    $db = getDB();
    $cols = $db->query("SHOW COLUMNS FROM `products` LIKE 'icon_base_path'")->fetchAll();
    if (!empty($cols)) {
        $db->prepare('UPDATE products SET icon_base_path = NULL WHERE id = ?')
           ->execute([$productId]);
    }

    echo json_encode(['success' => true]);
}

// ══════════════════════════════════════════════════════════════════════════════
// Image helpers
// ══════════════════════════════════════════════════════════════════════════════

/**
 * Load a GD image resource from a file, supporting JPEG / PNG / WEBP.
 */
function loadSourceImage(string $path, string $mime): ?\GdImage
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
 * Save the source image to disk in its original format.
 */
function saveOriginal(\GdImage $src, string $destPath, string $mime): void
{
    match ($mime) {
        'image/jpeg' => imagejpeg($src, $destPath, 85),
        'image/png'  => imagepng($src, $destPath, PNG_COMPRESS),
        'image/webp' => function_exists('imagewebp')
                          ? imagewebp($src, $destPath, 85)
                          : imagejpeg($src, $destPath, 85),
        default      => imagejpeg($src, $destPath, 85),
    };
}

/**
 * Create a square resized canvas at $size × $size with alpha preserved.
 * Crops to fill (cover behaviour), centred.
 */
function resizeCanvas(\GdImage $src, int $size): ?\GdImage
{
    $srcW = imagesx($src);
    $srcH = imagesy($src);

    if ($srcW <= 0 || $srcH <= 0) {
        return null;
    }

    // Cover crop: scale to fill the square, then centre
    $scale   = max($size / $srcW, $size / $srcH);
    $fitW    = (int)round($srcW * $scale);
    $fitH    = (int)round($srcH * $scale);
    $offsetX = (int)round(($fitW - $size) / 2);
    $offsetY = (int)round(($fitH - $size) / 2);

    $canvas = imagecreatetruecolor($size, $size);
    if ($canvas === false) {
        return null;
    }

    // Preserve transparency
    imagealphablending($canvas, false);
    imagesavealpha($canvas, true);
    $transparent = imagecolorallocatealpha($canvas, 0, 0, 0, 127);
    imagefill($canvas, 0, 0, $transparent);
    imagealphablending($canvas, true);

    // Two-pass: first scale into intermediate, then crop
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
 * Apply unsold greyscale effect in-place.
 * Converts to greyscale then lifts brightness slightly — professional, not washed out.
 */
function applyUnsoldEffect(\GdImage $img): void
{
    imagefilter($img, IMG_FILTER_GRAYSCALE);
    imagefilter($img, IMG_FILTER_BRIGHTNESS, GREY_BRIGHT);
    imagefilter($img, IMG_FILTER_CONTRAST, GREY_CONTRAST);
}

/**
 * Map MIME type to a safe file extension.
 */
function mimeToExtension(string $mime): string
{
    return match ($mime) {
        'image/jpeg' => 'jpg',
        'image/png'  => 'png',
        'image/webp' => 'webp',
        default      => 'jpg',
    };
}

/**
 * Human-readable upload error messages.
 */
function uploadErrorMessage(int $code): string
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

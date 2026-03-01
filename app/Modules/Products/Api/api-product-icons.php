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

require_once APP_ROOT . '/Services/Media/IconGenerator.php';

header('Content-Type: application/json');

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
        echo json_encode(['error' => 'Upload error: ' . icongen_uploadErrorMessage($errCode)]);
        exit;
    }

    $file = $_FILES['file'];

    // Size check
    if ($file['size'] > ICON_MAX_BYTES) {
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

    // ── Prepare directories ──────────────────────────────────────────────────

    $origDir  = PUBLIC_ROOT . '/uploads/products/original';
    $iconsDir = PUBLIC_ROOT . '/uploads/products/icons/' . $productId;

    if (!is_dir($origDir) && !mkdir($origDir, 0755, true)) {
        echo json_encode(['error' => 'Could not create upload directory']);
        exit;
    }

    // ── Save original ────────────────────────────────────────────────────────

    $src = icongen_loadSource($file['tmp_name'], $mimeType);
    if ($src === null) {
        echo json_encode(['error' => 'Could not read image. File may be corrupt.']);
        exit;
    }

    $ext      = icongen_mimeToExtension($mimeType);
    $origFile = $origDir . '/' . $productId . '_' . time() . '.' . $ext;
    icongen_saveOriginal($src, $origFile, $mimeType);
    imagedestroy($src);

    // ── Generate icon set ────────────────────────────────────────────────────

    $failed = icongen_generateSet($file['tmp_name'], $mimeType, $iconsDir);

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
    icongen_deleteSet($iconsDir);

    $db = getDB();
    $cols = $db->query("SHOW COLUMNS FROM `products` LIKE 'icon_base_path'")->fetchAll();
    if (!empty($cols)) {
        $db->prepare('UPDATE products SET icon_base_path = NULL WHERE id = ?')
           ->execute([$productId]);
    }

    echo json_encode(['success' => true]);
}

// Image helpers are provided by /app/Services/Media/IconGenerator.php

<?php
/**
 * Icon Sets API
 *
 * Manages reusable, named icon sets that can be created in the Media Manager
 * and assigned to any product. Stores sets in /uploads/icon-sets/{id}/.
 *
 * Actions:
 *   GET  action=list                     — list all icon sets with product usage counts
 *   POST action=upload  name file        — create a new icon set from uploaded image
 *   POST action=rename  id name          — rename an existing icon set
 *   POST action=delete  id               — delete icon set (files + DB + unassign products)
 *   POST action=assign  icon_set_id product_id — assign icon set to a product
 *   POST action=unassign product_id      — remove icon set assignment from a product
 *
 * @package Mowology CRM
 * @subpackage Modules/CMS/Api
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
session_write_close();

require_once APP_ROOT . '/Services/Media/IconGenerator.php';

header('Content-Type: application/json');

$method = $_SERVER['REQUEST_METHOD'];
$action = $method === 'GET' ? ($_GET['action'] ?? 'list') : ($_POST['action'] ?? '');

$db = getDB();

// ── Route ──────────────────────────────────────────────────────────────────

switch ($action) {
    case 'list':
        handleList($db);
        break;
    case 'upload':
        requireRole(['admin', 'staff']);
        handleUpload($db);
        break;
    case 'rename':
        requireRole(['admin', 'staff']);
        handleRename($db);
        break;
    case 'delete':
        requireRole(['admin', 'staff']);
        handleDelete($db);
        break;
    case 'assign':
        requirePermission('products.edit');
        handleAssign($db);
        break;
    case 'unassign':
        requirePermission('products.edit');
        handleUnassign($db);
        break;
    default:
        http_response_code(400);
        echo json_encode(['error' => 'Unknown action']);
        exit;
}

// ── Helpers ────────────────────────────────────────────────────────────────

function requireRole(array $roles): void
{
    global $user;
    if (empty($user)) {
        $user = getCurrentUser();
    }
    if (!in_array($user['role'] ?? '', $roles, true)) {
        http_response_code(403);
        echo json_encode(['error' => 'Insufficient permissions']);
        exit;
    }
}

function verifyCsrf(): void
{
    if (!verifyCSRFToken($_POST['csrf_token'] ?? '')) {
        http_response_code(403);
        echo json_encode(['error' => 'Invalid CSRF token']);
        exit;
    }
}

// ── Actions ────────────────────────────────────────────────────────────────

function handleList(\PDO $db): void
{
    // Check if table exists (migration-safe)
    try {
        $tblCheck = $db->query("SHOW TABLES LIKE 'icon_sets'")->rowCount();
        if ($tblCheck === 0) {
            echo json_encode(['success' => true, 'icon_sets' => []]);
            return;
        }
    } catch (\Throwable $e) {
        echo json_encode(['success' => true, 'icon_sets' => []]);
        return;
    }

    // Check if icon_set_id column exists on products
    $hasIconSetId = false;
    try {
        $hasIconSetId = $db->query("SHOW COLUMNS FROM products LIKE 'icon_set_id'")->rowCount() > 0;
    } catch (\Throwable $e) {}

    if ($hasIconSetId) {
        $stmt = $db->query(
            "SELECT s.*, COUNT(p.id) AS product_count
             FROM icon_sets s
             LEFT JOIN products p ON p.icon_set_id = s.id AND p.is_archived = 0
             GROUP BY s.id
             ORDER BY s.created_at DESC"
        );
    } else {
        $stmt = $db->query(
            "SELECT s.*, 0 AS product_count
             FROM icon_sets s
             ORDER BY s.created_at DESC"
        );
    }

    $sets = $stmt->fetchAll(\PDO::FETCH_ASSOC);

    // Add preview URLs
    foreach ($sets as &$s) {
        $s['preview_sold']   = $s['icon_base_path'] . 'icon_64_sold.png';
        $s['preview_unsold'] = $s['icon_base_path'] . 'icon_64_unsold.png';
        $s['preview_256_sold']   = $s['icon_base_path'] . 'icon_256_sold.png';
        $s['preview_256_unsold'] = $s['icon_base_path'] . 'icon_256_unsold.png';
        $s['product_count'] = (int)$s['product_count'];
    }
    unset($s);

    echo json_encode(['success' => true, 'icon_sets' => $sets]);
}

function handleUpload(\PDO $db): void
{
    verifyCsrf();

    $name = trim($_POST['name'] ?? '');
    if ($name === '') {
        echo json_encode(['error' => 'Icon set name is required']);
        exit;
    }
    if (strlen($name) > 100) {
        echo json_encode(['error' => 'Name must be 100 characters or fewer']);
        exit;
    }

    // File presence
    if (empty($_FILES['file']) || $_FILES['file']['error'] !== UPLOAD_ERR_OK) {
        $errCode = $_FILES['file']['error'] ?? -1;
        echo json_encode(['error' => 'Upload error: ' . icongen_uploadErrorMessage($errCode)]);
        exit;
    }

    $file = $_FILES['file'];

    if ($file['size'] > ICON_MAX_BYTES) {
        echo json_encode(['error' => 'File exceeds 5 MB limit']);
        exit;
    }

    // MIME validation
    $finfo    = finfo_open(FILEINFO_MIME_TYPE);
    $mimeType = finfo_file($finfo, $file['tmp_name']);
    finfo_close($finfo);

    $allowedMimes = ['image/jpeg', 'image/png', 'image/webp'];
    if (!in_array($mimeType, $allowedMimes, true)) {
        echo json_encode(['error' => 'Only JPEG, PNG, and WEBP images are accepted']);
        exit;
    }

    // Check table exists
    try {
        if ($db->query("SHOW TABLES LIKE 'icon_sets'")->rowCount() === 0) {
            echo json_encode(['error' => 'Icon sets table not found — run migration 910 first']);
            exit;
        }
    } catch (\Throwable $e) {
        echo json_encode(['error' => 'Database error: ' . $e->getMessage()]);
        exit;
    }

    // Insert a placeholder record to get the ID (used for directory naming)
    $user = getCurrentUser();
    $stmt = $db->prepare(
        "INSERT INTO icon_sets (name, icon_base_path, created_by) VALUES (?, '', ?)"
    );
    $stmt->execute([$name, $user['id'] ?? null]);
    $iconSetId = (int)$db->lastInsertId();

    // Save original
    $origDir  = PUBLIC_ROOT . '/uploads/icon-sets/originals';
    $outDir   = PUBLIC_ROOT . '/uploads/icon-sets/' . $iconSetId;
    $ext      = icongen_mimeToExtension($mimeType);
    $origFile = $origDir . '/' . $iconSetId . '_' . time() . '.' . $ext;

    if (!is_dir($origDir) && !mkdir($origDir, 0755, true)) {
        $db->prepare('DELETE FROM icon_sets WHERE id = ?')->execute([$iconSetId]);
        echo json_encode(['error' => 'Could not create originals directory']);
        exit;
    }

    $src = icongen_loadSource($file['tmp_name'], $mimeType);
    if ($src === null) {
        $db->prepare('DELETE FROM icon_sets WHERE id = ?')->execute([$iconSetId]);
        echo json_encode(['error' => 'Could not read image — file may be corrupt']);
        exit;
    }
    icongen_saveOriginal($src, $origFile, $mimeType);
    imagedestroy($src);

    // Generate icon set
    $failed = icongen_generateSet($file['tmp_name'], $mimeType, $outDir);
    if (!empty($failed)) {
        $db->prepare('DELETE FROM icon_sets WHERE id = ?')->execute([$iconSetId]);
        echo json_encode(['error' => 'Some icons failed to generate: ' . implode(', ', $failed)]);
        exit;
    }

    // Update record with final path
    $iconBasePath = '/uploads/icon-sets/' . $iconSetId . '/';
    $db->prepare('UPDATE icon_sets SET icon_base_path = ? WHERE id = ?')
       ->execute([$iconBasePath, $iconSetId]);

    echo json_encode([
        'success'         => true,
        'id'              => $iconSetId,
        'name'            => $name,
        'icon_base_path'  => $iconBasePath,
        'preview_sold'    => $iconBasePath . 'icon_64_sold.png',
        'preview_unsold'  => $iconBasePath . 'icon_64_unsold.png',
        'preview_256_sold'   => $iconBasePath . 'icon_256_sold.png',
        'preview_256_unsold' => $iconBasePath . 'icon_256_unsold.png',
    ]);
}

function handleRename(\PDO $db): void
{
    verifyCsrf();

    $id   = (int)($_POST['id'] ?? 0);
    $name = trim($_POST['name'] ?? '');

    if ($id <= 0) {
        echo json_encode(['error' => 'Invalid icon set ID']);
        exit;
    }
    if ($name === '') {
        echo json_encode(['error' => 'Name cannot be empty']);
        exit;
    }
    if (strlen($name) > 100) {
        echo json_encode(['error' => 'Name must be 100 characters or fewer']);
        exit;
    }

    $stmt = $db->prepare('UPDATE icon_sets SET name = ? WHERE id = ?');
    $stmt->execute([$name, $id]);

    if ($stmt->rowCount() === 0) {
        echo json_encode(['error' => 'Icon set not found']);
        exit;
    }

    echo json_encode(['success' => true, 'id' => $id, 'name' => $name]);
}

function handleDelete(\PDO $db): void
{
    verifyCsrf();

    $id = (int)($_POST['id'] ?? 0);
    if ($id <= 0) {
        echo json_encode(['error' => 'Invalid icon set ID']);
        exit;
    }

    // Verify it exists
    $row = $db->prepare('SELECT icon_base_path FROM icon_sets WHERE id = ?');
    $row->execute([$id]);
    $set = $row->fetch(\PDO::FETCH_ASSOC);
    if (!$set) {
        echo json_encode(['error' => 'Icon set not found']);
        exit;
    }

    // Unassign from all products
    try {
        $hasIconSetId = $db->query("SHOW COLUMNS FROM products LIKE 'icon_set_id'")->rowCount() > 0;
        if ($hasIconSetId) {
            $db->prepare('UPDATE products SET icon_set_id = NULL WHERE icon_set_id = ?')
               ->execute([$id]);
        }
    } catch (\Throwable $e) {}

    // Delete files
    $outDir = PUBLIC_ROOT . rtrim($set['icon_base_path'], '/');
    icongen_deleteSet($outDir);

    // Delete DB record
    $db->prepare('DELETE FROM icon_sets WHERE id = ?')->execute([$id]);

    echo json_encode(['success' => true]);
}

function handleAssign(\PDO $db): void
{
    verifyCsrf();

    $iconSetId = (int)($_POST['icon_set_id'] ?? 0);
    $productId = (int)($_POST['product_id'] ?? 0);

    if ($iconSetId <= 0 || $productId <= 0) {
        echo json_encode(['error' => 'Invalid icon_set_id or product_id']);
        exit;
    }

    // Verify icon set exists
    $check = $db->prepare('SELECT id FROM icon_sets WHERE id = ?');
    $check->execute([$iconSetId]);
    if (!$check->fetch()) {
        echo json_encode(['error' => 'Icon set not found']);
        exit;
    }

    // Check column exists
    try {
        $hasCol = $db->query("SHOW COLUMNS FROM products LIKE 'icon_set_id'")->rowCount() > 0;
        if (!$hasCol) {
            echo json_encode(['error' => 'icon_set_id column missing — run migration 910']);
            exit;
        }
    } catch (\Throwable $e) {
        echo json_encode(['error' => 'Database error: ' . $e->getMessage()]);
        exit;
    }

    $db->prepare('UPDATE products SET icon_set_id = ? WHERE id = ?')
       ->execute([$iconSetId, $productId]);

    echo json_encode(['success' => true, 'icon_set_id' => $iconSetId, 'product_id' => $productId]);
}

function handleUnassign(\PDO $db): void
{
    verifyCsrf();

    $productId = (int)($_POST['product_id'] ?? 0);
    if ($productId <= 0) {
        echo json_encode(['error' => 'Invalid product_id']);
        exit;
    }

    try {
        $hasCol = $db->query("SHOW COLUMNS FROM products LIKE 'icon_set_id'")->rowCount() > 0;
        if ($hasCol) {
            $db->prepare('UPDATE products SET icon_set_id = NULL WHERE id = ?')
               ->execute([$productId]);
        }
    } catch (\Throwable $e) {
        echo json_encode(['error' => 'Database error: ' . $e->getMessage()]);
        exit;
    }

    echo json_encode(['success' => true]);
}

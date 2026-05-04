<?php
declare(strict_types=1);

/**
 * app/Modules/Schedule/Api/job-photo.php
 *
 * Mobile Schedule API — Job Photo Upload
 *
 * POST /api/schedule/job-photo
 * Authorization: Bearer <jwt>
 * Content-Type: multipart/form-data
 *
 * Fields:
 *   job_photo   — image file (JPEG / PNG / HEIC)
 *   visit_id    — integer
 *   photo_type  — "before" | "after"
 *
 * Response 200:
 * {
 *   "success": true,
 *   "photo_id": 42,
 *   "photo_url": "/uploads/photos/mob_123_before_abcdef.jpg"
 * }
 *
 * Idempotent: duplicate uploads detected via SHA-256 hash return the
 * existing photo_id instead of inserting a second row.
 */

// ── Bootstrap ─────────────────────────────────────────────────────────────────
if (!defined('APP_ROOT')) {
    $__dir = __DIR__;
    for ($__i = 0; $__i < 6; $__i++) {
        $__dir = dirname($__dir);
        if (is_file($__dir . '/app/Core/paths.php')) {
            require_once $__dir . '/app/Core/paths.php';
            break;
        }
    }
    unset($__dir, $__i);
}

header('Content-Type: application/json; charset=utf-8');
header('X-Content-Type-Options: nosniff');

// ── Auth & config ─────────────────────────────────────────────────────────────
require_once APP_ROOT . '/Core/config.php';
require_once APP_ROOT . '/Core/Auth/JwtAuth.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['error' => 'Method not allowed']);
    exit;
}

$jwtUser = requireJwt();

// ── Input validation ──────────────────────────────────────────────────────────
$visitId   = isset($_POST['visit_id'])   ? (int)$_POST['visit_id']   : 0;
$photoType = isset($_POST['photo_type']) ? trim($_POST['photo_type']) : '';

if ($visitId <= 0) {
    http_response_code(400);
    echo json_encode(['error' => 'visit_id is required']);
    exit;
}

$allowedTypes = ['before', 'after'];
if (!in_array($photoType, $allowedTypes, true)) {
    http_response_code(400);
    echo json_encode(['error' => 'photo_type must be "before" or "after"']);
    exit;
}

if (empty($_FILES['job_photo']) || $_FILES['job_photo']['error'] !== UPLOAD_ERR_OK) {
    $uploadError = $_FILES['job_photo']['error'] ?? UPLOAD_ERR_NO_FILE;
    http_response_code(400);
    echo json_encode(['error' => 'No file uploaded', 'upload_error' => $uploadError]);
    exit;
}

$file = $_FILES['job_photo'];

// ── MIME validation ───────────────────────────────────────────────────────────
$finfo    = new finfo(FILEINFO_MIME_TYPE);
$realMime = $finfo->file($file['tmp_name']);
$mimeAllowed = ['image/jpeg', 'image/png', 'image/webp', 'image/heic', 'image/heif'];
if (!in_array($realMime, $mimeAllowed, true)) {
    http_response_code(415);
    echo json_encode(['error' => 'Unsupported image format']);
    exit;
}

// 20 MB cap
if ($file['size'] > 20 * 1024 * 1024) {
    http_response_code(413);
    echo json_encode(['error' => 'Photo exceeds 20MB limit']);
    exit;
}

try {
    $db = getDB();

    // ── Access control ─────────────────────────────────────────────────────────
    // Admin/manager can upload to any visit.
    // Crew can only upload to visits they are assigned to.
    if (!jwtIsAdmin($jwtUser['role'])) {
        $accessStmt = $db->prepare("
            SELECT jv.id
            FROM job_visits jv
            LEFT JOIN calendar_stop_crew csc ON csc.stop_id = (
                SELECT cs.id
                FROM calendar_stops cs
                JOIN calendar_stop_visits csv ON csv.stop_id = cs.id
                WHERE csv.visit_id = jv.id
                LIMIT 1
            ) AND csc.user_id = ?
            WHERE jv.id = ?
              AND (jv.assigned_crew_id = ? OR csc.user_id IS NOT NULL)
            LIMIT 1
        ");
        $accessStmt->execute([$jwtUser['id'], $visitId, $jwtUser['id']]);
        if (!$accessStmt->fetch()) {
            http_response_code(403);
            echo json_encode(['error' => 'Access denied to this visit']);
            exit;
        }
    }

    // ── Save file ──────────────────────────────────────────────────────────────
    $uploadDir = PUBLIC_ROOT . '/uploads/photos/';
    if (!is_dir($uploadDir)) {
        mkdir($uploadDir, 0755, true);
    }

    $ext      = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION) ?: 'jpg');
    $base     = 'mob_' . $visitId . '_' . $photoType . '_' . bin2hex(random_bytes(8));
    $filename = $base . '.' . $ext;
    $fullPath = $uploadDir . $filename;

    if (!move_uploaded_file($file['tmp_name'], $fullPath)) {
        http_response_code(500);
        echo json_encode(['error' => 'Failed to save photo']);
        exit;
    }

    // ── SHA-256 duplicate guard ────────────────────────────────────────────────
    $sha256 = hash_file('sha256', $fullPath);

    $dupStmt = $db->prepare(
        "SELECT id, filename FROM visit_photos WHERE visit_id = ? AND sha256 = ? AND deleted_at IS NULL LIMIT 1"
    );
    $dupStmt->execute([$visitId, $sha256]);
    $existing = $dupStmt->fetch(PDO::FETCH_ASSOC);

    if ($existing) {
        // Duplicate — discard the redundant file and return existing record
        @unlink($fullPath);
        echo json_encode([
            'success'   => true,
            'photo_id'  => (int)$existing['id'],
            'photo_url' => '/uploads/photos/' . $existing['filename'],
            'duplicate' => true,
        ]);
        exit;
    }

    // ── Denormalization: property_id + service_type ───────────────────────────
    $propStmt = $db->prepare("
        SELECT jp.property_id, jp.service_type
        FROM job_visits jv
        JOIN job_plans jp ON jp.id = jv.plan_id
        WHERE jv.id = ?
        LIMIT 1
    ");
    $propStmt->execute([$visitId]);
    $propRow = $propStmt->fetch(PDO::FETCH_ASSOC);
    $photoPropertyId  = $propRow ? (int)$propRow['property_id'] : null;
    $photoServiceType = $propRow['service_type'] ?? null;

    // ── Insert ────────────────────────────────────────────────────────────────
    $db->prepare("
        INSERT INTO visit_photos
            (visit_id, photo_type, filename, original_filename, file_size,
             mime_type, caption, sort_order, thumb_path, grid_path, view_path,
             uploaded_by, work_zone_id,
             property_id, uploaded_by_name, service_type, sha256)
        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
    ")->execute([
        $visitId,
        $photoType,
        $filename,
        $file['name'],
        $file['size'],
        $realMime,
        null,   // caption — mobile app doesn't capture one at upload time
        0,      // sort_order
        null,   // thumb_path — no variant generation on mobile endpoint
        null,   // grid_path
        null,   // view_path
        (int)$jwtUser['id'],
        null,   // work_zone_id — geo context not available from mobile upload
        $photoPropertyId,
        $jwtUser['name'] ?? null,
        $photoServiceType,
        $sha256,
    ]);
    $photoId = (int)$db->lastInsertId();

    http_response_code(200);
    echo json_encode([
        'success'   => true,
        'photo_id'  => $photoId,
        'photo_url' => '/uploads/photos/' . $filename,
    ], JSON_UNESCAPED_SLASHES);

} catch (Throwable $e) {
    error_log('[schedule/job-photo] ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['error' => 'Server error']);
}

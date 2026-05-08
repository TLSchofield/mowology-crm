<?php
/**
 * Visit Photo Upload — MwPhotoQueue Adapter
 * ──────────────────────────────────────────
 * Receives offline-queued visit photos from photo-queue.js and saves them
 * to visit_photos using the same GD pipeline as pow-actions.php.
 *
 * POST fields (multipart/form-data — sent by MwPhotoQueue.saveAndEnqueue):
 *   files[]       — one or more image files
 *   csrf_token    — CSRF token
 *   context_type  — must be 'job_visit'
 *   context_id    — visit_id (integer)
 *   category      — 'before' | 'after' | 'additional' | 'during' | 'issue' | 'other'
 *   gps_lat       — optional float
 *   gps_lng       — optional float
 *
 * Response (MwPhotoQueue-compatible):
 *   { "success": true,  "results": [{ "success": true,  "media_id": int, "uuid": string, "thumb_url": string, "view_url": string, "photo_type": string }] }
 *   { "success": false, "error": string }
 */
declare(strict_types=1);
header('Content-Type: application/json');

if (!defined('APP_ROOT')) {
    $__dir = __DIR__;
    for ($__i = 0; $__i < 5; $__i++) {
        $__dir = dirname($__dir);
        if (is_file($__dir . '/app/Core/paths.php')) {
            require_once $__dir . '/app/Core/paths.php';
            break;
        }
    }
    unset($__dir, $__i);
}

try {
    require_once PUBLIC_ROOT . '/loginAuth/auth.php';
    require_once CRM_INCLUDES . '/functions.php';

    requireLogin();
    $user = getCurrentUser();
    $db   = getDB();
    session_write_close(); // Release session lock before GD processing

    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        http_response_code(405);
        echo json_encode(['success' => false, 'error' => 'POST required']);
        exit;
    }

    // CSRF
    $token = $_POST['csrf_token'] ?? ($_SERVER['HTTP_X_CSRF_TOKEN'] ?? '');
    if (!verifyCSRFToken($token)) {
        http_response_code(403);
        echo json_encode(['success' => false, 'error' => 'Invalid CSRF token']);
        exit;
    }

    // Context validation
    $contextType = $_POST['context_type'] ?? '';
    if ($contextType !== 'job_visit') {
        http_response_code(400);
        echo json_encode(['success' => false, 'error' => 'context_type must be job_visit']);
        exit;
    }

    $visitId = isset($_POST['context_id']) ? (int)$_POST['context_id'] : 0;
    if ($visitId < 1) {
        http_response_code(400);
        echo json_encode(['success' => false, 'error' => 'context_id (visit_id) required']);
        exit;
    }

    // Verify visit exists and user has access
    $vstmt = $db->prepare("
        SELECT v.id, v.plan_id, v.assigned_crew_id, v.locked_at
        FROM job_visits v
        WHERE v.id = ?
    ");
    $vstmt->execute([$visitId]);
    $visit = $vstmt->fetch(PDO::FETCH_ASSOC);

    if (!$visit) {
        http_response_code(404);
        echo json_encode(['success' => false, 'error' => 'Visit not found']);
        exit;
    }

    $isMyCrew = (int)($visit['assigned_crew_id'] ?? 0) === (int)$user['id'];
    $isAdmin  = ($user['role'] ?? '') === 'admin' || userHasPermission('jobs.edit');
    if (!$isAdmin && !$isMyCrew) {
        http_response_code(403);
        echo json_encode(['success' => false, 'error' => 'Access denied']);
        exit;
    }

    if ($visit['locked_at'] !== null && !$isAdmin) {
        http_response_code(409);
        echo json_encode(['success' => false, 'error' => 'Visit is locked']);
        exit;
    }

    // Photo type — MwPhotoQueue sends as 'category'
    $rawCategory  = $_POST['category'] ?? 'after';
    $allowedTypes = ['before', 'after', 'additional', 'during', 'issue', 'other'];
    $photoType    = in_array($rawCategory, $allowedTypes, true) ? $rawCategory : 'other';

    // Optional GPS + capture time (from EXIF or phone clock)
    $gpsLat      = isset($_POST['gps_lat']) && $_POST['gps_lat'] !== '' ? (float)$_POST['gps_lat'] : null;
    $gpsLng      = isset($_POST['gps_lng']) && $_POST['gps_lng'] !== '' ? (float)$_POST['gps_lng'] : null;
    $gpsAccuracy = isset($_POST['gps_accuracy_m']) && $_POST['gps_accuracy_m'] !== '' ? (float)$_POST['gps_accuracy_m'] : null;
    $capturedAt  = !empty($_POST['captured_at']) ? date('Y-m-d H:i:s', strtotime($_POST['captured_at'])) : null;

    // Property + service type for denormalization
    $propStmt = $db->prepare("
        SELECT jp.property_id, jp.service_type
        FROM job_visits jv
        JOIN job_plans jp ON jp.id = jv.plan_id
        WHERE jv.id = ? LIMIT 1
    ");
    $propStmt->execute([$visitId]);
    $propRow          = $propStmt->fetch(PDO::FETCH_ASSOC);
    $photoPropertyId  = $propRow ? (int)$propRow['property_id'] : null;
    $photoServiceType = $propRow['service_type'] ?? null;

    // Optional geofence work-zone tagging
    $photoWorkZoneId = null;
    if ($gpsLat !== null && $gpsLng !== null && $photoPropertyId) {
        $geoModel = APP_ROOT . '/Modules/Geofence/Models/GeofenceModel.php';
        if (is_file($geoModel)) {
            require_once $geoModel;
            if (function_exists('geofenceGetWorkZones') && function_exists('geofenceClassifyPingAgainstWorkZones')) {
                $zones = geofenceGetWorkZones($photoPropertyId);
                if (!empty($zones)) {
                    $photoWorkZoneId = geofenceClassifyPingAgainstWorkZones($gpsLat, $gpsLng, $zones);
                }
            }
        }
    }

    // Validate files are present
    if (empty($_FILES['files'])) {
        http_response_code(400);
        echo json_encode(['success' => false, 'error' => 'No files received']);
        exit;
    }

    // Normalise PHP's multi-file array into a simple indexed list
    $fileCount = count($_FILES['files']['name']);
    $files = [];
    for ($i = 0; $i < $fileCount; $i++) {
        if ($_FILES['files']['error'][$i] === UPLOAD_ERR_OK) {
            $files[] = [
                'name'     => $_FILES['files']['name'][$i],
                'tmp_name' => $_FILES['files']['tmp_name'][$i],
                'size'     => (int)$_FILES['files']['size'][$i],
            ];
        }
    }

    if (empty($files)) {
        http_response_code(400);
        echo json_encode(['success' => false, 'error' => 'No valid files received']);
        exit;
    }

    $allowed   = ['image/jpeg', 'image/png', 'image/gif', 'image/webp'];
    $uploadDir = PUBLIC_ROOT . '/uploads/photos/';
    if (!is_dir($uploadDir)) @mkdir($uploadDir, 0755, true);

    $ip      = $_SERVER['HTTP_X_FORWARDED_FOR'] ?? $_SERVER['REMOTE_ADDR'] ?? null;
    $results = [];

    foreach ($files as $file) {
        // Validate MIME via finfo (not extension)
        $finfo    = finfo_open(FILEINFO_MIME_TYPE);
        $realMime = finfo_file($finfo, $file['tmp_name']);
        finfo_close($finfo);

        if (!in_array($realMime, $allowed, true)) {
            $results[] = ['success' => false, 'errors' => ['Invalid image type: ' . $realMime]];
            continue;
        }
        if ($file['size'] > 20 * 1024 * 1024) {
            $results[] = ['success' => false, 'errors' => ['Photo exceeds 20MB limit']];
            continue;
        }

        // Move uploaded file to photos dir
        $ext      = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION) ?: 'jpg');
        $base     = 'pow_' . $visitId . '_' . $photoType . '_' . bin2hex(random_bytes(8));
        $filename = $base . '.' . $ext;
        $fullPath = $uploadDir . $filename;
        move_uploaded_file($file['tmp_name'], $fullPath);

        // Generate thumb/grid/view variants + EXIF auto-orient
        $variants    = vphGenerateVariants($fullPath, $visitId, $base, $realMime);
        $photoSha256 = hash_file('sha256', $fullPath);

        // Try full INSERT with all new columns (migration 1025). Fall back gracefully.
        try {
            $db->prepare("
                INSERT INTO visit_photos
                    (visit_id, photo_type, filename, original_filename, file_size,
                     mime_type, caption, sort_order, thumb_path, grid_path, view_path,
                     uploaded_by, work_zone_id,
                     property_id, uploaded_by_name, service_type, sha256,
                     gps_lat, gps_lng, gps_accuracy_m, captured_at)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
            ")->execute([
                $visitId, $photoType, $filename, $file['name'],
                $file['size'], $realMime, '', 0,
                $variants['thumb_path'], $variants['grid_path'], $variants['view_path'],
                $user['id'], $photoWorkZoneId,
                $photoPropertyId, $user['full_name'] ?? $user['name'] ?? null,
                $photoServiceType, $photoSha256,
                $gpsLat, $gpsLng, $gpsAccuracy, $capturedAt,
            ]);
        } catch (PDOException $insErr) {
            // Pre-migration 1025 fallback — omit new columns
            $db->prepare("
                INSERT INTO visit_photos
                    (visit_id, photo_type, filename, original_filename, file_size,
                     mime_type, caption, sort_order, thumb_path, grid_path, view_path,
                     uploaded_by)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
            ")->execute([
                $visitId, $photoType, $filename, $file['name'],
                $file['size'], $realMime, '', 0,
                $variants['thumb_path'], $variants['grid_path'], $variants['view_path'],
                $user['id'],
            ]);
        }
        $photoId = (int)$db->lastInsertId();

        // Audit log — non-fatal
        try {
            $db->prepare("
                INSERT INTO visit_audit_log (visit_id, user_id, action, payload_json, ip_address)
                VALUES (?, ?, ?, ?, ?)
            ")->execute([
                $visitId, (int)$user['id'], 'photo_upload_queue',
                json_encode(['type' => $photoType, 'file' => $filename]),
                $ip ? substr($ip, 0, 45) : null,
            ]);
        } catch (PDOException $e) { /* non-fatal */ }

        $thumbUrl = $variants['thumb_path'] ?? ('/uploads/photos/' . $filename);
        $viewUrl  = $variants['view_path']  ?? ('/uploads/photos/' . $filename);

        $results[] = [
            'success'      => true,
            'media_id'     => $photoId,
            'uuid'         => $filename,
            'thumb_url'    => $thumbUrl,
            'view_url'     => $viewUrl,
            'photo_type'   => $photoType,
            'is_duplicate' => false,
        ];
    }

    echo json_encode(['success' => true, 'results' => $results]);

} catch (InvalidArgumentException $e) {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
} catch (PDOException $e) {
    error_log('visit-photo-upload PDO: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => 'Database error']);
} catch (Throwable $e) {
    error_log('visit-photo-upload: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => 'Server error']);
}


// ── GD helper functions (mirrors pow-actions.php — prefixed vph* to avoid name collisions) ──────

/**
 * Generate thumb (256px sq), grid (512px), and view (1280px) variants.
 * Auto-corrects EXIF orientation. Generates WebP if available, else JPEG.
 */
function vphGenerateVariants(string $srcPath, int $visitId, string $baseName, string $mime): array
{
    $result = ['thumb_path' => null, 'grid_path' => null, 'view_path' => null];

    $gd = null;
    switch ($mime) {
        case 'image/jpeg': $gd = @imagecreatefromjpeg($srcPath); break;
        case 'image/png':  $gd = @imagecreatefrompng($srcPath);  break;
        case 'image/webp': if (function_exists('imagecreatefromwebp')) { $gd = @imagecreatefromwebp($srcPath); } break;
        case 'image/gif':  $gd = @imagecreatefromgif($srcPath);  break;
        default: return $result;
    }
    if (!$gd) return $result;

    // Auto-orient via EXIF (JPEG only)
    if ($mime === 'image/jpeg' && function_exists('exif_read_data')) {
        $exif = @exif_read_data($srcPath);
        $gd   = vphGdAutoOrient($gd, (int)($exif['Orientation'] ?? 1));
    }

    $origW   = imagesx($gd);
    $origH   = imagesy($gd);
    $canWebP = function_exists('imagewebp');
    $outExt  = $canWebP ? 'webp' : 'jpg';

    $tDir = PUBLIC_ROOT . '/uploads/photos/t/' . $visitId . '/';
    $gDir = PUBLIC_ROOT . '/uploads/photos/g/' . $visitId . '/';
    $vDir = PUBLIC_ROOT . '/uploads/photos/v/' . $visitId . '/';
    foreach ([$tDir, $gDir, $vDir] as $d) {
        if (!is_dir($d)) @mkdir($d, 0755, true);
    }

    // Thumb: 256×256 square center-crop
    $shortSide = min($origW, $origH);
    $cropX     = (int)(($origW - $shortSide) / 2);
    $cropY     = (int)(($origH - $shortSide) / 2);
    $thumb     = imagecreatetruecolor(256, 256);
    vphGdFillTransparent($thumb, 256, 256);
    imagecopyresampled($thumb, $gd, 0, 0, $cropX, $cropY, 256, 256, $shortSide, $shortSide);
    $tFile = $baseName . '_t.' . $outExt;
    vphGdSave($thumb, $tDir . $tFile, $outExt, 65);
    imagedestroy($thumb);
    $result['thumb_path'] = '/uploads/photos/t/' . $visitId . '/' . $tFile;

    // Grid: 512px max (contain, no upscale)
    [$gW, $gH] = vphGdScale($origW, $origH, 512);
    $grid      = imagecreatetruecolor($gW, $gH);
    vphGdFillTransparent($grid, $gW, $gH);
    imagecopyresampled($grid, $gd, 0, 0, 0, 0, $gW, $gH, $origW, $origH);
    $gFile = $baseName . '_g.' . $outExt;
    vphGdSave($grid, $gDir . $gFile, $outExt, 70);
    imagedestroy($grid);
    $result['grid_path'] = '/uploads/photos/g/' . $visitId . '/' . $gFile;

    // View: 1280px max (contain, no upscale)
    [$vW, $vH] = vphGdScale($origW, $origH, 1280);
    $view      = imagecreatetruecolor($vW, $vH);
    vphGdFillTransparent($view, $vW, $vH);
    imagecopyresampled($view, $gd, 0, 0, 0, 0, $vW, $vH, $origW, $origH);
    $vFile = $baseName . '_v.' . $outExt;
    vphGdSave($view, $vDir . $vFile, $outExt, 78);
    imagedestroy($view);
    $result['view_path'] = '/uploads/photos/v/' . $visitId . '/' . $vFile;

    imagedestroy($gd);
    return $result;
}

function vphGdAutoOrient($gd, int $orientation)
{
    switch ($orientation) {
        case 2: imageflip($gd, IMG_FLIP_HORIZONTAL); break;
        case 3: $gd = imagerotate($gd, 180, 0); break;
        case 4: imageflip($gd, IMG_FLIP_VERTICAL); break;
        case 5: $gd = imagerotate($gd, -90, 0); imageflip($gd, IMG_FLIP_HORIZONTAL); break;
        case 6: $gd = imagerotate($gd, -90, 0); break;
        case 7: $gd = imagerotate($gd, 90, 0);  imageflip($gd, IMG_FLIP_HORIZONTAL); break;
        case 8: $gd = imagerotate($gd, 90, 0);  break;
    }
    return $gd;
}

function vphGdFillTransparent($gd, int $w, int $h): void
{
    imagealphablending($gd, false);
    imagesavealpha($gd, true);
    $t = imagecolorallocatealpha($gd, 0, 0, 0, 127);
    imagefilledrectangle($gd, 0, 0, $w - 1, $h - 1, $t);
    imagealphablending($gd, true);
}

function vphGdScale(int $w, int $h, int $maxSide): array
{
    if ($w <= $maxSide && $h <= $maxSide) return [$w, $h];
    $scale = min($maxSide / $w, $maxSide / $h);
    return [(int)round($w * $scale), (int)round($h * $scale)];
}

function vphGdSave($gd, string $path, string $ext, int $quality): void
{
    if ($ext === 'webp' && function_exists('imagewebp')) {
        @imagewebp($gd, $path, $quality);
    } else {
        @imagejpeg($gd, $path, $quality);
    }
}

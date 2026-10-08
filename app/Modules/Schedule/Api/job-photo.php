<?php
declare(strict_types=1);

/**
 * app/Modules/Schedule/Api/job-photo.php
 *
 * iOS Before/After Job Photo Upload — JWT authenticated
 * POST /api/schedule/job-photo
 *
 * Multipart fields:
 *   photo       - image file
 *   visit_id    - int
 *   photo_type  - "before" | "after"
 */

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

try {
    require_once APP_ROOT . '/Core/config.php';
    require_once APP_ROOT . '/Core/Auth/JwtAuth.php';
    require_once CRM_INCLUDES . '/functions.php';
    require_once APP_ROOT . '/Services/Media/MediaUploadService.php';
    require_once APP_ROOT . '/Services/Media/MediaVariantGenerator.php';

    $jwtUser = requireJwt();
    $userId  = (int)$jwtUser['id'];
    $role    = $jwtUser['role'] ?? 'crew';

    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        http_response_code(405);
        echo json_encode(['success' => false, 'message' => 'POST required']);
        exit;
    }

    $visitId   = (int)($_POST['visit_id']   ?? 0);
    $photoType = trim($_POST['photo_type']  ?? 'other');

    if ($visitId < 1) {
        http_response_code(400);
        echo json_encode(['success' => false, 'message' => 'visit_id required']);
        exit;
    }

    require_once APP_ROOT . '/Modules/Jobs/Services/VisitPhotoService.php';
    if (!VisitPhotoService::isUploadType($photoType)) {
        $photoType = 'other';
    }

    $db = getDB();

    // Verify visit exists and this user is allowed to upload to it
    $vs = $db->prepare("SELECT id, stop_id, assigned_crew_id, plan_id FROM job_visits WHERE id = ? LIMIT 1");
    $vs->execute([$visitId]);
    $visit = $vs->fetch(PDO::FETCH_ASSOC);

    if (!$visit) {
        http_response_code(404);
        echo json_encode(['success' => false, 'message' => 'Visit not found']);
        exit;
    }

    $isAdmin = in_array($role, ['admin', 'manager', 'staff']);
    // Anyone on the stop's crew may add photos, not only the lead the visit is
    // assigned to — same rule as the Work Record and the photo list.
    require_once APP_ROOT . '/Modules/Jobs/Services/VisitWorkService.php';
    $stopCrew = (new VisitWorkService($db))->stopCrewIds(isset($visit['stop_id']) ? (int)$visit['stop_id'] : null);
    if (!VisitWorkService::canAccess($visit, $userId, $isAdmin, $stopCrew)) {
        http_response_code(403);
        echo json_encode(['success' => false, 'message' => 'Not authorised for this visit']);
        exit;
    }

    // Special request gate (inert unless ops_settings.special_requests_enabled): a live photo of
    // a visit with an unread client request answers 409 with the request. A queued upload sends
    // queued_at and passes (logged), so an already-taken photo is never stuck.
    require_once APP_ROOT . '/Modules/Operations/Services/SpecialRequestGate.php';
    SpecialRequestGate::enforce($db, $visitId, $userId, isset($_POST['queued_at']), $_POST['queued_at'] ?? null, 'photo', 'message');

    // Validate file
    if (empty($_FILES['photo']) || $_FILES['photo']['error'] !== UPLOAD_ERR_OK) {
        $code = $_FILES['photo']['error'] ?? -1;
        http_response_code(400);
        echo json_encode(['success' => false, 'message' => "No file or upload error: {$code}"]);
        exit;
    }

    // Accept GPS coords from the iOS app alongside the photo
    $browserGps = null;
    if (!empty($_POST['gps_lat']) && !empty($_POST['gps_lng'])) {
        $browserGps = [
            'lat'      => (float)$_POST['gps_lat'],
            'lng'      => (float)$_POST['gps_lng'],
            'accuracy' => isset($_POST['gps_accuracy']) ? (float)$_POST['gps_accuracy'] : null,
        ];
    }

    // Store via unified media service
    $result = mediaUploadFile(
        $_FILES['photo'],
        $userId,
        'job_visit',
        $browserGps,
        null,
        null
    );

    if (!$result['success']) {
        http_response_code(422);
        echo json_encode(['success' => false, 'message' => implode(', ', $result['errors'])]);
        exit;
    }

    $mediaId     = $result['media_id'];
    $isDuplicate = $result['is_duplicate'] ?? false;

    // Link to visit
    createMediaLink($mediaId, 'job_visit', $visitId, $photoType, 'internal', $userId);

    // Use photo timestamp as implied visit start/completion when GPS clock-in didn't fire
    if (!$isDuplicate) {
        $capturedAt = null;
        $maStmt = $db->prepare('SELECT captured_at FROM media_assets WHERE id = ? LIMIT 1');
        $maStmt->execute([$mediaId]);
        $capturedAt = $maStmt->fetchColumn() ?: null;

        if ($capturedAt) {
            if ($photoType === 'before') {
                $db->prepare(
                    'UPDATE job_visits SET started_at = ? WHERE id = ? AND started_at IS NULL'
                )->execute([$capturedAt, $visitId]);
            } elseif ($photoType === 'after') {
                $db->prepare(
                    'UPDATE job_visits SET completed_at = ? WHERE id = ? AND completed_at IS NULL'
                )->execute([$capturedAt, $visitId]);
            }
        }
    }

    // Generate thumbnail (skip for duplicates — already have variants)
    $thumbUrl = null;
    if (!$isDuplicate) {
        $absPath     = PUBLIC_ROOT . $result['file_path'];
        $variantResult = generateMediaThumbOnly($mediaId, $absPath);
        if ($variantResult && !empty($variantResult['thumb_url'])) {
            $thumbUrl = $variantResult['thumb_url'];
        }
    } else {
        $thumbUrl = getMediaThumbUrl($mediaId);
    }

    echo json_encode([
        'success'   => true,
        'media_id'  => $mediaId,
        'file_path' => $result['file_path'],
        'thumb_url' => $thumbUrl,
        'type'      => $photoType,
    ]);

} catch (Throwable $e) {
    error_log('[schedule/job-photo] ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Server error']);
}

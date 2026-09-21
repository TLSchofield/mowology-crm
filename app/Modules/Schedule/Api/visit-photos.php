<?php
declare(strict_types=1);

/**
 * app/Modules/Schedule/Api/visit-photos.php
 *
 * Mobile Schedule API — List Photos for a Visit
 *
 * GET /api/schedule/visit-photos?visit_id=N
 * Authorization: Bearer <jwt>
 *
 * Response 200:
 * {
 *   "success": true,
 *   "visit_id": 42,
 *   "photos": [
 *     {
 *       "id": 1,
 *       "photo_type": "before",          // before | after | additional
 *       "photo_url": "/uploads/photos/mob_42_before_abc.jpg",
 *       "thumb_url": "/uploads/photos/t/42/mob_42_before_abc_t.webp"
 *     }
 *   ]
 * }
 *
 * thumb_url falls back to photo_url when no thumbnail was generated.
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

require_once APP_ROOT . '/Core/config.php';
require_once APP_ROOT . '/Core/Auth/JwtAuth.php';

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    http_response_code(405);
    echo json_encode(['error' => 'Method not allowed']);
    exit;
}

$jwtUser = requireJwt();

$visitId = isset($_GET['visit_id']) ? (int)$_GET['visit_id'] : 0;
if ($visitId < 1) {
    http_response_code(400);
    echo json_encode(['error' => 'visit_id is required']);
    exit;
}

try {
    require_once CRM_INCLUDES . '/functions.php';
    require_once APP_ROOT . '/Modules/Jobs/Services/VisitWorkService.php';
    require_once APP_ROOT . '/Modules/Jobs/Services/VisitPhotoService.php';

    $db      = getDB();
    $userId  = (int)$jwtUser['id'];
    $isAdmin = jwtIsAdmin((string)$jwtUser['role']);

    // Same access rule as the Work Record: assigned crew, anyone on the stop's crew, or office.
    $work  = new VisitWorkService($db);
    $visit = $work->loadVisit($visitId);
    if (!$visit) {
        http_response_code(404);
        echo json_encode(['error' => 'That visit is no longer available.']);
        exit;
    }
    $stopCrew = $work->stopCrewIds(isset($visit['stop_id']) ? (int)$visit['stop_id'] : null);
    if (!VisitWorkService::canAccess($visit, $userId, $isAdmin, $stopCrew)) {
        http_response_code(403);
        echo json_encode(['error' => 'Not authorized for this visit']);
        exit;
    }

    echo json_encode([
        'success'  => true,
        'visit_id' => $visitId,
        'photos'   => (new VisitPhotoService($db))->listForVisit($visitId),
    ]);

} catch (Throwable $e) {
    error_log('visit-photos API error: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['error' => 'Server error']);
}

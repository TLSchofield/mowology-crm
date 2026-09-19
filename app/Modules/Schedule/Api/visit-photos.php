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
 *       "photo_type": "before",
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
    $db = getDB();

    // Verify the requesting user has access to this visit
    $authCheck = $db->prepare("
        SELECT jv.id
        FROM job_visits jv
        JOIN job_plans jp ON jv.plan_id = jp.id
        WHERE jv.id = ?
          AND (jv.assigned_crew_id = ? OR ? IN (SELECT id FROM users WHERE role IN ('admin','manager')))
        LIMIT 1
    ");
    $authCheck->execute([$visitId, $jwtUser['id'], $jwtUser['id']]);
    if (!$authCheck->fetch()) {
        http_response_code(403);
        echo json_encode(['error' => 'Not authorized for this visit']);
        exit;
    }

    $stmt = $db->prepare("
        SELECT id, photo_type, filename, thumb_path, grid_path
        FROM visit_photos
        WHERE visit_id = ?
          AND deleted_at IS NULL
          AND photo_type IN ('before', 'after')
        ORDER BY photo_type ASC, id ASC
    ");
    $stmt->execute([$visitId]);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $photos = [];
    foreach ($rows as $row) {
        $baseUrl  = '/uploads/photos/' . $row['filename'];
        $thumbUrl = $row['thumb_path'] ?? $baseUrl;
        $photos[] = [
            'id'         => (int)$row['id'],
            'photo_type' => $row['photo_type'],
            'photo_url'  => $baseUrl,
            'thumb_url'  => $thumbUrl,
        ];
    }

    echo json_encode([
        'success'  => true,
        'visit_id' => $visitId,
        'photos'   => $photos,
    ]);

} catch (Throwable $e) {
    error_log('visit-photos API error: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['error' => 'Server error']);
}

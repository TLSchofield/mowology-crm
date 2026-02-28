<?php
/**
 * Job Photo API
 * ──────────────
 * Endpoints for photo management after initial upload.
 *
 * Actions (GET or POST):
 *   GET  ?action=list&visit_id=N           — List photos for a visit
 *   POST action=delete   { visit_id, photo_id, csrf_token }
 *   POST action=reorder  { visit_id, order: [id,...], csrf_token }
 *
 * Returns: JSON
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
    $user    = getCurrentUser();
    $db      = getDB();
    $isAdmin = ($user['role'] ?? '') === 'admin' || userHasPermission('jobs.edit');
    $method  = $_SERVER['REQUEST_METHOD'];

    // ── GET: list ─────────────────────────────────────────────────────────
    if ($method === 'GET') {
        $action  = $_GET['action'] ?? 'list';
        $visitId = isset($_GET['visit_id']) ? (int)$_GET['visit_id'] : 0;

        if ($visitId < 1) {
            http_response_code(400);
            echo json_encode(['error' => 'visit_id required']);
            exit;
        }

        // Authorize: must be admin or assigned crew
        if (!$isAdmin) {
            $stmt = $db->prepare("SELECT assigned_crew_id FROM job_visits WHERE id = ?");
            $stmt->execute([$visitId]);
            $v = $stmt->fetch(PDO::FETCH_ASSOC);
            if (!$v || (int)$v['assigned_crew_id'] !== (int)$user['id']) {
                http_response_code(403);
                echo json_encode(['error' => 'Not authorized']);
                exit;
            }
        }

        $stmt = $db->prepare("
            SELECT id, photo_type, filename, caption, sort_order,
                   thumb_path, grid_path, view_path,
                   uploaded_at, uploaded_by
            FROM visit_photos
            WHERE visit_id = ? AND deleted_at IS NULL
            ORDER BY
                FIELD(photo_type,'before','after','additional','during','issue','other'),
                sort_order ASC,
                uploaded_at ASC
        ");
        $stmt->execute([$visitId]);
        $photos = $stmt->fetchAll(PDO::FETCH_ASSOC);

        // Build response with fallback URLs
        $out = [];
        foreach ($photos as $p) {
            $origUrl = '/uploads/photos/' . $p['filename'];
            $out[] = [
                'id'         => (int)$p['id'],
                'type'       => $p['photo_type'],
                'caption'    => $p['caption'],
                'sort_order' => (int)$p['sort_order'],
                'thumb_url'  => $p['thumb_path'] ?? $origUrl,
                'grid_url'   => $p['grid_path']  ?? $origUrl,
                'view_url'   => $p['view_path']  ?? $origUrl,
                'orig_url'   => $origUrl,
                'uploaded_at'=> $p['uploaded_at'],
            ];
        }

        // Group by type for convenience
        $grouped = ['before' => [], 'after' => [], 'additional' => [], 'other' => []];
        foreach ($out as $ph) {
            $bucket = in_array($ph['type'], ['before','after','additional']) ? $ph['type'] : 'other';
            $grouped[$bucket][] = $ph;
        }

        echo json_encode(['success' => true, 'photos' => $out, 'grouped' => $grouped]);
        exit;
    }

    // ── POST actions ──────────────────────────────────────────────────────
    if ($method !== 'POST') {
        http_response_code(405);
        echo json_encode(['error' => 'GET or POST required']);
        exit;
    }

    $input = json_decode(file_get_contents('php://input'), true) ?? $_POST;

    $csrfToken = $input['csrf_token'] ?? ($_SERVER['HTTP_X_CSRF_TOKEN'] ?? '');
    if (!verifyCSRFToken($csrfToken)) {
        http_response_code(403);
        echo json_encode(['error' => 'Invalid CSRF token']);
        exit;
    }

    $action  = $input['action'] ?? '';
    $visitId = isset($input['visit_id']) ? (int)$input['visit_id'] : 0;

    if ($visitId < 1) {
        http_response_code(400);
        echo json_encode(['error' => 'visit_id required']);
        exit;
    }

    // Load visit to authorize
    $stmt = $db->prepare("SELECT assigned_crew_id, locked_at FROM job_visits WHERE id = ?");
    $stmt->execute([$visitId]);
    $visit = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$visit) {
        http_response_code(404);
        echo json_encode(['error' => 'Visit not found']);
        exit;
    }

    $isCrew = (int)($visit['assigned_crew_id'] ?? 0) === (int)$user['id'];
    if (!$isAdmin && !$isCrew) {
        http_response_code(403);
        echo json_encode(['error' => 'Not authorized']);
        exit;
    }

    // Lock guard for non-admin mutations
    $isLocked = ($visit['locked_at'] !== null);
    if ($isLocked && !$isAdmin && in_array($action, ['delete','reorder'])) {
        http_response_code(409);
        echo json_encode(['error' => 'Visit is locked']);
        exit;
    }

    switch ($action) {

        // ── Soft delete ───────────────────────────────────────────────────
        case 'delete':
            $photoId = isset($input['photo_id']) ? (int)$input['photo_id'] : 0;
            if ($photoId < 1) {
                http_response_code(400);
                echo json_encode(['error' => 'photo_id required']);
                break;
            }
            // Confirm the photo belongs to this visit
            $chk = $db->prepare("SELECT id FROM visit_photos WHERE id = ? AND visit_id = ? AND deleted_at IS NULL");
            $chk->execute([$photoId, $visitId]);
            if (!$chk->fetch()) {
                http_response_code(404);
                echo json_encode(['error' => 'Photo not found']);
                break;
            }

            $db->prepare("UPDATE visit_photos SET deleted_at = NOW() WHERE id = ?")
               ->execute([$photoId]);

            // Audit
            $db->prepare("
                INSERT INTO visit_audit_log (visit_id, user_id, action, payload_json, ip_address)
                VALUES (?, ?, 'photo_delete', ?, ?)
            ")->execute([
                $visitId, $user['id'],
                json_encode(['photo_id' => $photoId]),
                substr($_SERVER['REMOTE_ADDR'] ?? '', 0, 45),
            ]);

            echo json_encode(['success' => true]);
            break;

        // ── Reorder ───────────────────────────────────────────────────────
        case 'reorder':
            $order = $input['order'] ?? [];
            if (!is_array($order) || empty($order)) {
                http_response_code(400);
                echo json_encode(['error' => 'order array required']);
                break;
            }

            $stmt = $db->prepare("
                UPDATE visit_photos SET sort_order = ?
                WHERE id = ? AND visit_id = ? AND deleted_at IS NULL
            ");
            foreach (array_values($order) as $sortIdx => $photoId) {
                $stmt->execute([$sortIdx, (int)$photoId, $visitId]);
            }

            echo json_encode(['success' => true]);
            break;

        default:
            http_response_code(400);
            echo json_encode(['error' => 'Unknown action: ' . $action]);
    }

} catch (PDOException $e) {
    error_log('job-photo.php DB error: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['error' => 'Database error']);
} catch (Throwable $e) {
    error_log('job-photo.php error: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['error' => 'Server error']);
}

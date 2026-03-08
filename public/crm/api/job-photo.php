<?php
/**
 * Job Photo API
 * ──────────────
 * Endpoints for photo management after initial upload.
 *
 * GET  ?action=list&visit_id=N              — list photos for a visit
 * GET  ?action=list_comments&photo_id=N     — list comments for a photo
 * GET  ?action=get_annotation&photo_id=N    — get annotation shapes for a photo
 *
 * POST action=delete          { visit_id, photo_id, csrf_token }
 * POST action=reorder         { visit_id, order: [id,...], csrf_token }
 * POST action=update_tags     { photo_id, tags: [...], csrf_token }
 * POST action=add_comment     { photo_id, comment, csrf_token }
 * POST action=delete_comment  { comment_id, csrf_token }
 * POST action=save_annotation { photo_id, shapes: [...], csrf_token }   (admin)
 * POST action=bulk_tag        { photo_ids: [...], tags: [...], csrf_token } (admin)
 * POST action=bulk_delete     { photo_ids: [...], csrf_token }              (admin)
 * POST action=bulk_change_type { photo_ids: [...], photo_type, csrf_token } (admin)
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

// ── Helpers (defined before try so they are in global scope) ─────────────────

function _jp_fetchPhoto(PDO $db, int $photoId): ?array
{
    $s = $db->prepare("SELECT id, visit_id, uploaded_by FROM visit_photos WHERE id = ? AND deleted_at IS NULL");
    $s->execute([$photoId]);
    return $s->fetch(PDO::FETCH_ASSOC) ?: null;
}

function _jp_fetchVisit(PDO $db, int $visitId): ?array
{
    $s = $db->prepare("SELECT id, assigned_crew_id, locked_at FROM job_visits WHERE id = ?");
    $s->execute([$visitId]);
    return $s->fetch(PDO::FETCH_ASSOC) ?: null;
}

function _jp_canAccess(array $visit, array $user, bool $isAdmin): bool
{
    if ($isAdmin) return true;
    return (int)($visit['assigned_crew_id'] ?? 0) === (int)$user['id'];
}

$VALID_PHOTO_TYPES = ['before', 'after', 'additional', 'during', 'issue', 'other'];

try {
    require_once PUBLIC_ROOT . '/loginAuth/auth.php';
    require_once CRM_INCLUDES . '/functions.php';

    requireLogin();
    $user    = getCurrentUser();
    $db      = getDB();
    session_write_close();

    $isAdmin = ($user['role'] ?? '') === 'admin' || userHasPermission('jobs.edit');
    $method  = $_SERVER['REQUEST_METHOD'];

    // ── GET ───────────────────────────────────────────────────────────────────
    if ($method === 'GET') {
        $action = $_GET['action'] ?? 'list';

        // ── list_comments ─────────────────────────────────────────────────────
        if ($action === 'list_comments') {
            $photoId = isset($_GET['photo_id']) ? (int)$_GET['photo_id'] : 0;
            if ($photoId < 1) {
                http_response_code(400);
                echo json_encode(['error' => 'photo_id required']);
                exit;
            }
            $photo = _jp_fetchPhoto($db, $photoId);
            if (!$photo) {
                http_response_code(404);
                echo json_encode(['error' => 'Photo not found']);
                exit;
            }
            if (!$isAdmin) {
                $visit = _jp_fetchVisit($db, (int)$photo['visit_id']);
                if (!$visit || !_jp_canAccess($visit, $user, false)) {
                    http_response_code(403);
                    echo json_encode(['error' => 'Not authorized']);
                    exit;
                }
            }

            $stmt = $db->prepare("
                SELECT c.id, c.comment, c.created_at, c.user_id,
                       u.full_name AS user_name
                FROM visit_photo_comments c
                LEFT JOIN users u ON u.id = c.user_id
                WHERE c.photo_id = ? AND c.deleted_at IS NULL
                ORDER BY c.created_at ASC
            ");
            $stmt->execute([$photoId]);
            echo json_encode(['success' => true, 'comments' => $stmt->fetchAll(PDO::FETCH_ASSOC)]);
            exit;
        }

        // ── get_annotation ────────────────────────────────────────────────────
        if ($action === 'get_annotation') {
            $photoId = isset($_GET['photo_id']) ? (int)$_GET['photo_id'] : 0;
            if ($photoId < 1) {
                http_response_code(400);
                echo json_encode(['error' => 'photo_id required']);
                exit;
            }
            $photo = _jp_fetchPhoto($db, $photoId);
            if (!$photo) {
                http_response_code(404);
                echo json_encode(['error' => 'Photo not found']);
                exit;
            }
            if (!$isAdmin) {
                $visit = _jp_fetchVisit($db, (int)$photo['visit_id']);
                if (!$visit || !_jp_canAccess($visit, $user, false)) {
                    http_response_code(403);
                    echo json_encode(['error' => 'Not authorized']);
                    exit;
                }
            }

            $stmt = $db->prepare("SELECT shapes_json, created_by, created_at FROM visit_photo_annotations WHERE photo_id = ?");
            $stmt->execute([$photoId]);
            $ann = $stmt->fetch(PDO::FETCH_ASSOC);

            if (!$ann) {
                echo json_encode(['success' => true, 'annotation' => null]);
            } else {
                echo json_encode([
                    'success'    => true,
                    'annotation' => [
                        'shapes'     => json_decode($ann['shapes_json'], true) ?: [],
                        'created_by' => (int)$ann['created_by'],
                        'created_at' => $ann['created_at'],
                    ],
                ]);
            }
            exit;
        }

        // ── list (original) ───────────────────────────────────────────────────
        $visitId = isset($_GET['visit_id']) ? (int)$_GET['visit_id'] : 0;
        if ($visitId < 1) {
            http_response_code(400);
            echo json_encode(['error' => 'visit_id required']);
            exit;
        }

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
            SELECT id, photo_type, filename, caption, sort_order, tags,
                   thumb_path, grid_path, view_path,
                   uploaded_at, uploaded_by, uploaded_by_name
            FROM visit_photos
            WHERE visit_id = ? AND deleted_at IS NULL
            ORDER BY
                FIELD(photo_type,'before','after','additional','during','issue','other'),
                sort_order ASC,
                uploaded_at ASC
        ");
        $stmt->execute([$visitId]);
        $photos = $stmt->fetchAll(PDO::FETCH_ASSOC);

        $out = [];
        foreach ($photos as $p) {
            $origUrl = '/uploads/photos/' . $p['filename'];
            $out[] = [
                'id'              => (int)$p['id'],
                'type'            => $p['photo_type'],
                'caption'         => $p['caption'],
                'sort_order'      => (int)$p['sort_order'],
                'tags'            => json_decode($p['tags'] ?? '[]', true) ?: [],
                'thumb_url'       => $p['thumb_path'] ?? $origUrl,
                'grid_url'        => $p['grid_path']  ?? $origUrl,
                'view_url'        => $p['view_path']  ?? $origUrl,
                'orig_url'        => $origUrl,
                'uploaded_at'     => $p['uploaded_at'],
                'uploaded_by_name'=> $p['uploaded_by_name'],
            ];
        }

        $grouped = ['before' => [], 'after' => [], 'additional' => [], 'other' => []];
        foreach ($out as $ph) {
            $bucket = in_array($ph['type'], ['before','after','additional']) ? $ph['type'] : 'other';
            $grouped[$bucket][] = $ph;
        }

        echo json_encode(['success' => true, 'photos' => $out, 'grouped' => $grouped]);
        exit;
    }

    // ── POST ──────────────────────────────────────────────────────────────────
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

    $action = $input['action'] ?? '';

    // ── Admin-only bulk actions (no visit_id needed) ──────────────────────────
    if (in_array($action, ['bulk_tag', 'bulk_delete', 'bulk_change_type'], true)) {
        if (!$isAdmin) {
            http_response_code(403);
            echo json_encode(['error' => 'Admin only']);
            exit;
        }

        $photoIds = $input['photo_ids'] ?? [];
        if (!is_array($photoIds) || empty($photoIds)) {
            http_response_code(400);
            echo json_encode(['error' => 'photo_ids array required']);
            exit;
        }
        $photoIds = array_values(array_filter(array_map('intval', $photoIds), fn($id) => $id > 0));
        if (empty($photoIds)) {
            http_response_code(400);
            echo json_encode(['error' => 'No valid photo IDs provided']);
            exit;
        }

        $ph = implode(',', array_fill(0, count($photoIds), '?'));

        if ($action === 'bulk_tag') {
            $newTags = $input['tags'] ?? [];
            if (!is_array($newTags)) {
                http_response_code(400);
                echo json_encode(['error' => 'tags must be an array']);
                exit;
            }
            $newTags = array_values(array_filter(array_map(fn($t) => mb_substr(trim((string)$t), 0, 50), $newTags)));
            $newTags = array_slice($newTags, 0, 10);

            $fetchStmt = $db->prepare("SELECT id, tags FROM visit_photos WHERE id IN ({$ph}) AND deleted_at IS NULL");
            $fetchStmt->execute($photoIds);
            $rows = $fetchStmt->fetchAll(PDO::FETCH_ASSOC);

            $updStmt = $db->prepare("UPDATE visit_photos SET tags = ? WHERE id = ?");
            $updated = 0;
            foreach ($rows as $row) {
                $existing = json_decode($row['tags'] ?? '[]', true) ?: [];
                $merged   = array_values(array_unique(array_merge($existing, $newTags)));
                $merged   = array_slice($merged, 0, 10);
                $updStmt->execute([json_encode($merged), (int)$row['id']]);
                $updated++;
            }
            echo json_encode(['success' => true, 'updated' => $updated]);
            exit;
        }

        if ($action === 'bulk_delete') {
            $db->prepare("UPDATE visit_photos SET deleted_at = NOW() WHERE id IN ({$ph})")
               ->execute($photoIds);
            echo json_encode(['success' => true, 'deleted' => count($photoIds)]);
            exit;
        }

        if ($action === 'bulk_change_type') {
            $photoType = $input['photo_type'] ?? '';
            if (!in_array($photoType, $VALID_PHOTO_TYPES, true)) {
                http_response_code(400);
                echo json_encode(['error' => 'Invalid photo_type']);
                exit;
            }
            $params = array_merge([$photoType], $photoIds);
            $db->prepare("UPDATE visit_photos SET photo_type = ? WHERE id IN ({$ph}) AND deleted_at IS NULL")
               ->execute($params);
            echo json_encode(['success' => true, 'updated' => count($photoIds)]);
            exit;
        }
    }

    // ── delete_comment (auth by ownership) ───────────────────────────────────
    if ($action === 'delete_comment') {
        $commentId = isset($input['comment_id']) ? (int)$input['comment_id'] : 0;
        if ($commentId < 1) {
            http_response_code(400);
            echo json_encode(['error' => 'comment_id required']);
            exit;
        }

        $stmt = $db->prepare("SELECT id, user_id FROM visit_photo_comments WHERE id = ? AND deleted_at IS NULL");
        $stmt->execute([$commentId]);
        $comment = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$comment) {
            http_response_code(404);
            echo json_encode(['error' => 'Comment not found']);
            exit;
        }

        if (!$isAdmin && (int)$comment['user_id'] !== (int)$user['id']) {
            http_response_code(403);
            echo json_encode(['error' => 'Not authorized']);
            exit;
        }

        $db->prepare("UPDATE visit_photo_comments SET deleted_at = NOW() WHERE id = ?")
           ->execute([$commentId]);
        echo json_encode(['success' => true]);
        exit;
    }

    // ── Photo-scoped actions: auth via photo to visit lookup ─────────────────
    if (in_array($action, ['update_tags', 'add_comment', 'save_annotation'], true)) {
        $photoId = isset($input['photo_id']) ? (int)$input['photo_id'] : 0;
        if ($photoId < 1) {
            http_response_code(400);
            echo json_encode(['error' => 'photo_id required']);
            exit;
        }

        $photo = _jp_fetchPhoto($db, $photoId);
        if (!$photo) {
            http_response_code(404);
            echo json_encode(['error' => 'Photo not found']);
            exit;
        }

        $visit = _jp_fetchVisit($db, (int)$photo['visit_id']);
        if (!$visit) {
            http_response_code(404);
            echo json_encode(['error' => 'Visit not found']);
            exit;
        }

        if (!_jp_canAccess($visit, $user, $isAdmin)) {
            http_response_code(403);
            echo json_encode(['error' => 'Not authorized']);
            exit;
        }

        if ($action === 'update_tags') {
            $tags = $input['tags'] ?? [];
            if (!is_array($tags)) {
                http_response_code(400);
                echo json_encode(['error' => 'tags must be an array']);
                exit;
            }
            $tags = array_values(array_filter(array_map(fn($t) => mb_substr(trim((string)$t), 0, 50), $tags)));
            $tags = array_slice($tags, 0, 10);

            $db->prepare("UPDATE visit_photos SET tags = ? WHERE id = ?")
               ->execute([json_encode($tags), $photoId]);
            echo json_encode(['success' => true, 'tags' => $tags]);
            exit;
        }

        if ($action === 'add_comment') {
            $comment = trim((string)($input['comment'] ?? ''));
            if ($comment === '') {
                http_response_code(400);
                echo json_encode(['error' => 'comment required']);
                exit;
            }
            if (mb_strlen($comment) > 1000) {
                http_response_code(400);
                echo json_encode(['error' => 'Comment exceeds 1000 character limit']);
                exit;
            }

            $stmt = $db->prepare("INSERT INTO visit_photo_comments (photo_id, user_id, comment) VALUES (?, ?, ?)");
            $stmt->execute([$photoId, $user['id'], $comment]);
            $newId = (int)$db->lastInsertId();

            $userName = trim(($user['first_name'] ?? '') . ' ' . ($user['last_name'] ?? ''));
            if ($userName === '') $userName = $user['full_name'] ?? 'Unknown';

            echo json_encode([
                'success' => true,
                'comment' => [
                    'id'         => $newId,
                    'comment'    => $comment,
                    'user_id'    => (int)$user['id'],
                    'user_name'  => $userName,
                    'created_at' => date('Y-m-d H:i:s'),
                ],
            ]);
            exit;
        }

        if ($action === 'save_annotation') {
            if (!$isAdmin) {
                http_response_code(403);
                echo json_encode(['error' => 'Admin only']);
                exit;
            }

            $shapes = $input['shapes'] ?? [];
            if (!is_array($shapes)) {
                http_response_code(400);
                echo json_encode(['error' => 'shapes must be an array']);
                exit;
            }

            $clean = [];
            foreach ($shapes as $shape) {
                if (!is_array($shape)) continue;
                $clean[] = [
                    'type'  => mb_substr((string)($shape['type']  ?? ''), 0, 20),
                    'x1'    => (float)($shape['x1']    ?? 0),
                    'y1'    => (float)($shape['y1']    ?? 0),
                    'x2'    => (float)($shape['x2']    ?? 0),
                    'y2'    => (float)($shape['y2']    ?? 0),
                    'color' => mb_substr((string)($shape['color'] ?? '#e85d04'), 0, 20),
                    'label' => mb_substr((string)($shape['label'] ?? ''), 0, 100),
                ];
            }

            $stmt = $db->prepare("
                INSERT INTO visit_photo_annotations (photo_id, created_by, shapes_json)
                VALUES (?, ?, ?)
                ON DUPLICATE KEY UPDATE shapes_json = VALUES(shapes_json), updated_at = NOW()
            ");
            $stmt->execute([$photoId, $user['id'], json_encode($clean)]);
            echo json_encode(['success' => true, 'shapes' => $clean]);
            exit;
        }
    }

    // ── Visit-scoped actions (delete, reorder) ────────────────────────────────
    $visitId = isset($input['visit_id']) ? (int)$input['visit_id'] : 0;
    if ($visitId < 1) {
        http_response_code(400);
        echo json_encode(['error' => 'visit_id required']);
        exit;
    }

    $stmt = $db->prepare("SELECT id, assigned_crew_id, locked_at FROM job_visits WHERE id = ?");
    $stmt->execute([$visitId]);
    $visit = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$visit) {
        http_response_code(404);
        echo json_encode(['error' => 'Visit not found']);
        exit;
    }

    $isCrew   = (int)($visit['assigned_crew_id'] ?? 0) === (int)$user['id'];
    $isLocked = ($visit['locked_at'] !== null);

    if (!$isAdmin && !$isCrew) {
        http_response_code(403);
        echo json_encode(['error' => 'Not authorized']);
        exit;
    }

    if ($isLocked && !$isAdmin && in_array($action, ['delete', 'reorder'], true)) {
        http_response_code(409);
        echo json_encode(['error' => 'Visit is locked']);
        exit;
    }

    switch ($action) {

        case 'delete':
            $photoId = isset($input['photo_id']) ? (int)$input['photo_id'] : 0;
            if ($photoId < 1) {
                http_response_code(400);
                echo json_encode(['error' => 'photo_id required']);
                break;
            }

            $chk = $db->prepare("SELECT id FROM visit_photos WHERE id = ? AND visit_id = ? AND deleted_at IS NULL");
            $chk->execute([$photoId, $visitId]);
            if (!$chk->fetch()) {
                http_response_code(404);
                echo json_encode(['error' => 'Photo not found']);
                break;
            }

            $db->prepare("UPDATE visit_photos SET deleted_at = NOW() WHERE id = ?")
               ->execute([$photoId]);

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
            foreach (array_values($order) as $sortIdx => $pId) {
                $stmt->execute([$sortIdx, (int)$pId, $visitId]);
            }

            echo json_encode(['success' => true]);
            break;

        default:
            http_response_code(400);
            echo json_encode(['error' => 'Unknown action: ' . htmlspecialchars($action)]);
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

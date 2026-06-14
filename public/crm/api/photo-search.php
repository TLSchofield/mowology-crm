<?php
/**
 * Photo Search API
 *
 * GET ?q=<term>&limit=30&property_id=N&photo_type=before
 *
 * Searches visit_photos across: tags, caption, uploaded_by_name,
 * property address, contact name, service_type.
 *
 * Admin only. Returns JSON array of photo cards for the timeline UI.
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
    requireLogin();
    $user = getCurrentUser();

    // Admin-only endpoint
    if (($user['role'] ?? '') !== 'admin') {
        http_response_code(403);
        echo json_encode(['error' => 'Admin only']);
        exit;
    }

    $db = getDB();
    session_write_close();

    $q          = trim((string)($_GET['q']          ?? ''));
    $propertyId = isset($_GET['property_id']) ? (int)$_GET['property_id'] : 0;
    $photoType  = $_GET['photo_type'] ?? '';
    $limit      = max(1, min(100, (int)($_GET['limit'] ?? 48)));

    $validTypes = ['before', 'after', 'additional', 'during', 'issue', 'other'];

    // Require a search term or property scope
    if ($q === '' && $propertyId === 0) {
        echo json_encode(['photos' => [], 'note' => 'Provide a search term or property_id']);
        exit;
    }

    $where  = ['vp.deleted_at IS NULL'];
    $params = [];

    // ── Text search ──────────────────────────────────────────────────────────
    if ($q !== '') {
        $like = '%' . $q . '%';
        $where[] = "(
            vp.tags            LIKE ?
            OR vp.caption      LIKE ?
            OR vp.uploaded_by_name LIKE ?
            OR vp.service_type LIKE ?
            OR pr.address      LIKE ?
            OR CONCAT(c.first_name, ' ', c.last_name) LIKE ?
        )";
        $params = array_merge($params, [$like, $like, $like, $like, $like, $like]);
    }

    // ── Scope filters ────────────────────────────────────────────────────────
    if ($propertyId > 0) {
        $where[]  = 'vp.property_id = ?';
        $params[] = $propertyId;
    }

    if ($photoType !== '' && in_array($photoType, $validTypes, true)) {
        $where[]  = 'vp.photo_type = ?';
        $params[] = $photoType;
    }

    $whereSQL = implode(' AND ', $where);

    $stmt = $db->prepare("
        SELECT
            vp.id,
            vp.photo_type,
            vp.filename,
            vp.caption,
            vp.tags,
            vp.thumb_path,
            vp.grid_path,
            vp.view_path,
            vp.uploaded_at,
            vp.uploaded_by_name,
            vp.service_type,
            vp.property_id,
            vp.visit_id,
            pr.address        AS property_address,
            CONCAT(c.first_name, ' ', c.last_name) AS contact_name
        FROM visit_photos vp
        LEFT JOIN properties pr ON pr.id = vp.property_id
        LEFT JOIN contacts   c  ON c.id  = pr.site_contact_id
        WHERE {$whereSQL}
        ORDER BY vp.uploaded_at DESC
        LIMIT ?
    ");
    $params[] = $limit;
    $stmt->execute($params);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $photos = [];
    foreach ($rows as $r) {
        $origUrl  = '/uploads/photos/' . $r['filename'];
        $photos[] = [
            'id'               => (int)$r['id'],
            'type'             => $r['photo_type'],
            'caption'          => $r['caption'],
            'tags'             => json_decode($r['tags'] ?? '[]', true) ?: [],
            'thumb_url'        => $r['thumb_path'] ?? $origUrl,
            'grid_url'         => $r['grid_path']  ?? $origUrl,
            'view_url'         => $r['view_path']  ?? $origUrl,
            'orig_url'         => $origUrl,
            'uploaded_at'      => $r['uploaded_at'],
            'uploaded_by_name' => $r['uploaded_by_name'],
            'service_type'     => $r['service_type'],
            'property_id'      => (int)$r['property_id'],
            'visit_id'         => (int)$r['visit_id'],
            'property_address' => $r['property_address'],
            'contact_name'     => trim($r['contact_name'] ?? ''),
        ];
    }

    echo json_encode(['photos' => $photos, 'count' => count($photos), 'q' => $q]);

} catch (PDOException $e) {
    error_log('photo-search.php DB error: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['error' => 'Database error']);
} catch (Throwable $e) {
    error_log('photo-search.php error: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['error' => 'Server error']);
}

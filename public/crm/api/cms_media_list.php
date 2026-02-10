<?php
/**
 * CMS Media List API Endpoint
 *
 * Returns paginated, searchable list of media assets for media picker modal.
 * Used by cms-block-editor.php media field.
 *
 * @package Mowology CRM
 * @subpackage CMS API
 *
 * Query Parameters:
 *   - search: (optional) Search by filename or alt text
 *   - page: (optional, default 1) Page number for pagination
 *   - per_page: (optional, default 12) Items per page
 *   - type: (optional) Filter by media_type (image, video, document)
 */

declare(strict_types=1);

require_once dirname(dirname(__DIR__)) . '/includes/bootstrap.php';
require_once dirname(__DIR__) . '/includes/cms-functions.php';
require_once dirname(__DIR__) . '/loginAuth/auth.php';

// Require login
requireLogin();

// Output JSON
header('Content-Type: application/json; charset=utf-8');

try {
    // Get parameters
    $search = trim($_GET['search'] ?? '');
    $page = max(1, (int)($_GET['page'] ?? 1));
    $perPage = max(1, min(50, (int)($_GET['per_page'] ?? 12)));
    $type = trim($_GET['type'] ?? '');

    // Build query
    $db = getDB();
    $sql = "SELECT id, filename, file_path, thumb_path, media_type, alt_text, file_size, uploaded_at FROM cms_media";
    $params = [];
    $where = [];

    if ($search) {
        $where[] = "(filename LIKE ? OR alt_text LIKE ?)";
        $searchTerm = '%' . $search . '%';
        $params[] = $searchTerm;
        $params[] = $searchTerm;
    }

    if ($type) {
        $where[] = "media_type = ?";
        $params[] = $type;
    }

    if (!empty($where)) {
        $sql .= " WHERE " . implode(" AND ", $where);
    }

    $sql .= " ORDER BY uploaded_at DESC";

    // Get total count for pagination
    $countSql = "SELECT COUNT(*) as total FROM cms_media";
    if (!empty($where)) {
        $countSql .= " WHERE " . implode(" AND ", $where);
    }
    $countStmt = $db->prepare($countSql);
    $countStmt->execute($params);
    $totalCount = (int)$countStmt->fetchColumn();

    // Apply pagination
    $offset = ($page - 1) * $perPage;
    $sql .= " LIMIT ? OFFSET ?";
    $params[] = $perPage;
    $params[] = $offset;

    // Execute query
    $stmt = $db->prepare($sql);
    $stmt->execute($params);
    $items = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

    // Format response
    $totalPages = ceil($totalCount / $perPage);
    $response = [
        'success' => true,
        'data' => array_map(function($item) {
            return [
                'id' => (int)$item['id'],
                'filename' => h($item['filename']),
                'file_path' => h($item['file_path']),
                'thumb_path' => h($item['thumb_path'] ?? $item['file_path']),
                'type' => h($item['media_type']),
                'alt_text' => h($item['alt_text'] ?? ''),
                'size' => (int)$item['file_size'],
                'uploaded_at' => $item['uploaded_at'],
            ];
        }, $items),
        'pagination' => [
            'page' => $page,
            'per_page' => $perPage,
            'total_pages' => $totalPages,
            'total_items' => $totalCount,
        ],
    ];

    http_response_code(200);
    echo json_encode($response, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

} catch (Exception $e) {
    error_log("CMS Media List API Error: " . $e->getMessage());
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'error' => 'Failed to fetch media list',
    ]);
}

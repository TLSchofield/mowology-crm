<?php
/**
 * CMS Media List API Endpoint
 *
 * Returns paginated, searchable list of media assets for media picker modal.
 *
 * @package Mowology CRM
 * @subpackage CMS API
 *
 * Query Parameters:
 *   - search: (optional) Search by filename or alt text
 *   - page: (optional, default 1) Page number for pagination
 *   - per_page: (optional, default 12) Items per page
 *   - type: (optional) Filter by file_type (image, video, document)
 */

declare(strict_types=1);

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

require_once PUBLIC_ROOT . '/loginAuth/auth.php';
require_once CRM_INCLUDES . '/functions.php';
require_once CRM_INCLUDES . '/cms-functions.php';

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

    // Build query using the actual media_assets table
    $db = getDB();
    $where = [];
    $params = [];

    if ($search) {
        $where[] = "(original_filename LIKE ? OR alt_text LIKE ? OR caption LIKE ?)";
        $searchTerm = '%' . $search . '%';
        $params[] = $searchTerm;
        $params[] = $searchTerm;
        $params[] = $searchTerm;
    }

    if ($type) {
        $where[] = "file_type = ?";
        $params[] = $type;
    }

    $whereSql = !empty($where) ? " WHERE " . implode(" AND ", $where) : "";

    // Get total count for pagination
    $countStmt = $db->prepare("SELECT COUNT(*) FROM media_assets" . $whereSql);
    $countStmt->execute($params);
    $totalCount = (int)$countStmt->fetchColumn();

    // Fetch page of results
    $sql = "SELECT id, original_filename, stored_filename, file_path, file_type, mime_type, file_size,
                   image_width, image_height, alt_text, caption, created_at
            FROM media_assets" . $whereSql . "
            ORDER BY created_at DESC
            LIMIT " . $perPage . " OFFSET " . (($page - 1) * $perPage);

    $stmt = $db->prepare($sql);
    $stmt->execute($params);
    $items = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

    // Format response
    $totalPages = (int)ceil($totalCount / $perPage);
    $response = [
        'success' => true,
        'data' => array_map(function($item) {
            return [
                'id' => (int)$item['id'],
                'filename' => $item['original_filename'],
                'file_path' => $item['file_path'],
                'thumb_path' => $item['file_path'], // same for now, could add thumbnails later
                'type' => $item['file_type'],
                'mime_type' => $item['mime_type'],
                'alt_text' => $item['alt_text'] ?? '',
                'caption' => $item['caption'] ?? '',
                'size' => (int)$item['file_size'],
                'width' => $item['image_width'] ? (int)$item['image_width'] : null,
                'height' => $item['image_height'] ? (int)$item['image_height'] : null,
                'uploaded_at' => $item['created_at'],
            ];
        }, $items),
        'pagination' => [
            'page' => $page,
            'per_page' => $perPage,
            'total_pages' => $totalPages,
            'total_items' => $totalCount,
        ],
    ];

    echo json_encode($response, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

} catch (Exception $e) {
    error_log("CMS Media List API Error: " . $e->getMessage());
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'error' => 'Failed to fetch media list',
    ]);
}

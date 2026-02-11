<?php
/**
 * API: Media Library Browser for Products
 *
 * GET /crm/products/api-media-browse.php?search=mulch&type=image
 * Returns media assets for the product image picker.
 * Supports smart suggestions by matching alt_text/filename to search term.
 */

require_once dirname(__DIR__) . '/../loginAuth/auth.php';
require_once dirname(__DIR__) . '/includes/functions.php';

requireLogin();
$user = getCurrentUser();

header('Content-Type: application/json');

// Access control
if (!in_array($user['role'], ['admin', 'staff'])) {
    http_response_code(403);
    echo json_encode(['success' => false, 'error' => 'Access denied']);
    exit;
}

$search = trim($_GET['search'] ?? '');
$type = $_GET['type'] ?? 'image';
$limit = min((int)($_GET['limit'] ?? 50), 100);

try {
    $db = getDB();

    // Base query — only images by default
    $sql = "SELECT id, file_path, alt_text, original_filename, file_size, image_width, image_height
            FROM media_assets
            WHERE file_type = ?";
    $params = [$type];

    if ($search !== '') {
        $sql .= " AND (
            alt_text LIKE ? OR
            original_filename LIKE ? OR
            caption LIKE ? OR
            description LIKE ?
        )";
        $pattern = "%{$search}%";
        $params[] = $pattern;
        $params[] = $pattern;
        $params[] = $pattern;
        $params[] = $pattern;
    }

    $sql .= " ORDER BY created_at DESC LIMIT ?";
    $params[] = $limit;

    $stmt = $db->prepare($sql);
    $stmt->execute($params);
    $media = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // If we have a search term, score results for relevance
    if ($search !== '') {
        $searchWords = array_filter(explode(' ', strtolower($search)));

        foreach ($media as &$item) {
            $score = 0;
            $altLower = strtolower($item['alt_text'] ?? '');
            $nameLower = strtolower($item['original_filename'] ?? '');

            // Exact phrase match in alt_text = highest score
            if (stripos($altLower, strtolower($search)) !== false) {
                $score += 10;
            }

            // Individual word matches in alt_text
            foreach ($searchWords as $word) {
                if (strlen($word) < 3) continue;
                if (stripos($altLower, $word) !== false) {
                    $score += 3;
                }
                if (stripos($nameLower, $word) !== false) {
                    $score += 1;
                }
            }

            $item['relevance_score'] = $score;
            $item['is_suggested'] = $score >= 3;
        }
        unset($item);

        // Sort: suggested first (by score desc), then by date
        usort($media, function ($a, $b) {
            if ($b['relevance_score'] !== $a['relevance_score']) {
                return $b['relevance_score'] <=> $a['relevance_score'];
            }
            return 0; // preserve original date order for equal scores
        });
    } else {
        // No search — no suggestions
        foreach ($media as &$item) {
            $item['relevance_score'] = 0;
            $item['is_suggested'] = false;
        }
        unset($item);
    }

    echo json_encode([
        'success' => true,
        'media' => $media,
        'total' => count($media),
    ]);

} catch (PDOException $e) {
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => 'Database error']);
    exit;
}

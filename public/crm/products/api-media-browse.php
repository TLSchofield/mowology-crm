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

    // Always fetch ALL images — scoring handles relevance, not SQL filtering.
    // This ensures every image is browsable even when a search term is active.
    $sql = "SELECT id, file_path, alt_text, caption, description, original_filename,
                   file_size, image_width, image_height
            FROM media_assets
            WHERE file_type = ?
            ORDER BY created_at DESC
            LIMIT ?";
    $params = [$type, $limit];

    $stmt = $db->prepare($sql);
    $stmt->execute($params);
    $media = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // Score every image against the search term (product name)
    if ($search !== '') {
        // Break product name into meaningful words (skip short filler words)
        $searchLower = strtolower($search);
        $searchWords = array_filter(
            explode(' ', $searchLower),
            fn($w) => strlen($w) >= 3
        );

        foreach ($media as &$item) {
            $score = 0;
            $altLower   = strtolower($item['alt_text'] ?? '');
            $nameLower  = strtolower($item['original_filename'] ?? '');
            $captLower  = strtolower($item['caption'] ?? '');
            $descLower  = strtolower($item['description'] ?? '');

            // Exact phrase match = highest score
            if ($altLower !== '' && stripos($altLower, $searchLower) !== false) {
                $score += 10;
            }
            if ($nameLower !== '' && stripos($nameLower, $searchLower) !== false) {
                $score += 8;
            }

            // Individual word matches (each word can contribute)
            foreach ($searchWords as $word) {
                if (stripos($altLower, $word) !== false)  $score += 3;
                if (stripos($nameLower, $word) !== false)  $score += 2;
                if (stripos($captLower, $word) !== false)  $score += 1;
                if (stripos($descLower, $word) !== false)  $score += 1;
            }

            $item['relevance_score'] = $score;
            $item['is_suggested'] = $score >= 2;
        }
        unset($item);

        // Sort: suggested first (by score desc), then by date (original order)
        usort($media, function ($a, $b) {
            return $b['relevance_score'] <=> $a['relevance_score'];
        });
    } else {
        foreach ($media as &$item) {
            $item['relevance_score'] = 0;
            $item['is_suggested'] = false;
        }
        unset($item);
    }

    // Strip internal fields before sending to client
    foreach ($media as &$item) {
        unset($item['caption'], $item['description']);
    }
    unset($item);

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

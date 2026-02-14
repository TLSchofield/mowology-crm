<?php
/**
 * /crm/media/favorite.php
 * AJAX handler to toggle favorite status on media
 */

declare(strict_types=1);
require_once dirname(__DIR__) . '/../loginAuth/auth.php';
require_once dirname(__DIR__) . '/includes/functions.php';
require_once dirname(__DIR__) . '/includes/portfolio-functions.php';

requireLogin();
$user = getCurrentUser();

if (!$user) {
    http_response_code(401);
    die(json_encode(['success' => false, 'message' => 'Unauthorized']));
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    die(json_encode(['success' => false, 'message' => 'Method not allowed']));
}

$mediaId = !empty($_POST['media_id']) ? (int)$_POST['media_id'] : 0;
$csrfToken = $_POST['csrf_token'] ?? '';

if (!$mediaId) {
    http_response_code(400);
    die(json_encode(['success' => false, 'message' => 'Missing media_id']));
}

if (!verifyCSRFToken($csrfToken)) {
    http_response_code(403);
    die(json_encode(['success' => false, 'message' => 'CSRF token invalid']));
}

try {
    $result = toggleMediaFavorite($mediaId, $user['id']);

    if ($result) {
        // Get updated status
        $db = getDB();
        $stmt = $db->prepare("SELECT is_favorite FROM media_assets WHERE id = ?");
        $stmt->execute([$mediaId]);
        $row = $stmt->fetch();

        die(json_encode([
            'success' => true,
            'is_favorite' => (bool)$row['is_favorite'],
            'message' => $row['is_favorite'] ? 'Added to favorites' : 'Removed from favorites'
        ]));
    } else {
        http_response_code(400);
        die(json_encode(['success' => false, 'message' => 'Media not found']));
    }

} catch (Throwable $e) {
    error_log("Favorite toggle error: " . $e->getMessage());
    http_response_code(500);
    die(json_encode(['success' => false, 'message' => $e->getMessage()]));
}

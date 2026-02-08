<?php
/**
 * /crm/media/approve.php
 * AJAX handler for admin to approve/reject media
 */

declare(strict_types=1);
require_once dirname(__DIR__) . '/../loginAuth/auth.php';
require_once dirname(__DIR__) . '/includes/functions.php';
require_once dirname(__DIR__) . '/includes/portfolio-functions.php';

requireLogin();
$user = getCurrentUser();

if (!$user || $user['role'] !== 'admin') {
    http_response_code(403);
    die(json_encode(['success' => false, 'message' => 'Admin access required']));
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    die(json_encode(['success' => false, 'message' => 'Method not allowed']));
}

$mediaId = !empty($_POST['media_id']) ? (int)$_POST['media_id'] : 0;
$action = trim($_POST['action'] ?? '');
$reason = trim($_POST['reason'] ?? '');
$csrfToken = $_POST['csrf_token'] ?? '';

if (!$mediaId || !in_array($action, ['approve', 'reject'])) {
    http_response_code(400);
    die(json_encode(['success' => false, 'message' => 'Invalid parameters']));
}

if (!verifyCSRFToken($csrfToken)) {
    http_response_code(403);
    die(json_encode(['success' => false, 'message' => 'CSRF token invalid']));
}

try {
    $approved = ($action === 'approve');
    $result = approveMedia($mediaId, $user['id'], $approved, $reason ?: null);

    if ($result) {
        die(json_encode([
            'success' => true,
            'status' => $approved ? 'ready' : 'rejected',
            'message' => $approved ? 'Photo approved' : 'Photo rejected'
        ]));
    } else {
        http_response_code(400);
        die(json_encode(['success' => false, 'message' => 'Media not found']));
    }

} catch (Throwable $e) {
    error_log("Media approval error: " . $e->getMessage());
    http_response_code(500);
    die(json_encode(['success' => false, 'message' => $e->getMessage()]));
}

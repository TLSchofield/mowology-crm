<?php
/**
 * /app/Modules/Marketing/Api/status.php
 * AJAX Endpoint: Update recommendation status
 *
 * POST /crm/api/seo/status.php
 * Data:
 *   recommendation_id: int
 *   status: 'accepted' | 'ignored' | 'done'
 *   csrf_token: string
 *
 * Response: {success: bool, message: string}
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

header('Content-Type: application/json');

require_once PUBLIC_ROOT . '/loginAuth/auth.php';
require_once CRM_INCLUDES . '/seo-functions.php';

requireLogin();
$user = getCurrentUser();

if (!$user || $user['role'] !== 'admin') {
    http_response_code(403);
    die(json_encode(['success' => false, 'message' => 'Admin access required']));
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    die(json_encode(['success' => false, 'message' => 'POST required']));
}

$csrfToken = $_POST['csrf_token'] ?? '';
if (!verifyCSRFToken($csrfToken)) {
    http_response_code(403);
    die(json_encode(['success' => false, 'message' => 'CSRF token invalid']));
}

$recId = (int)($_POST['recommendation_id'] ?? 0);
$newStatus = trim($_POST['status'] ?? '');

if (!$recId || !$newStatus) {
    http_response_code(400);
    die(json_encode(['success' => false, 'message' => 'recommendation_id and status required']));
}

$validStatuses = ['new', 'accepted', 'applied', 'done', 'ignored'];
if (!in_array($newStatus, $validStatuses)) {
    http_response_code(400);
    die(json_encode(['success' => false, 'message' => 'Invalid status: ' . $newStatus]));
}

$db = getDB();

try {
    // Get current recommendation
    $recStmt = $db->prepare("
        SELECT id, status, priority_score
        FROM seo_recommendations
        WHERE id = ?
    ");
    $recStmt->execute([$recId]);
    $rec = $recStmt->fetch(PDO::FETCH_ASSOC);

    if (!$rec) {
        http_response_code(404);
        die(json_encode(['success' => false, 'message' => 'Recommendation not found']));
    }

    $oldStatus = $rec['status'];

    // Update status
    $updateStmt = $db->prepare("
        UPDATE seo_recommendations
        SET status = ?, updated_at = NOW()
        WHERE id = ?
    ");
    $updateStmt->execute([$newStatus, $recId]);

    // Log action
    logRecommendationAction(
        $recId,
        'status_changed',
        $user['id'],
        $oldStatus,
        $newStatus,
        'Status changed via UI',
        $_SERVER['REMOTE_ADDR'] ?? null,
        $db
    );

    http_response_code(200);
    die(json_encode([
        'success' => true,
        'message' => 'Status updated to: ' . $newStatus
    ]));

} catch (Throwable $e) {
    error_log("SEO Status Error: " . $e->getMessage());
    http_response_code(500);
    die(json_encode([
        'success' => false,
        'message' => 'Failed to update status: ' . $e->getMessage()
    ]));
}

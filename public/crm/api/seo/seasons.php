<?php
/**
 * /crm/api/seo/seasons.php
 * AJAX Endpoint: Manage SEO seasons (seasonal campaigns)
 *
 * GET: List seasons
 * POST: Create/update season
 * DELETE: Deactivate season
 */

declare(strict_types=1);
header('Content-Type: application/json');

require_once dirname(__DIR__, 2) . '/loginAuth/auth.php';

requireLogin();
$user = getCurrentUser();

if (!$user || $user['role'] !== 'admin') {
    http_response_code(403);
    die(json_encode(['success' => false, 'message' => 'Admin access required']));
}

$db = getDB();
$method = $_SERVER['REQUEST_METHOD'];

try {
    if ($method === 'GET') {
        // List all seasons
        $stmt = $db->query("
            SELECT id, season_key, label, start_month, start_day, end_month, end_day, priority_boost, is_active
            FROM seo_seasons
            ORDER BY start_month, priority_boost DESC
        ");
        $seasons = $stmt->fetchAll(PDO::FETCH_ASSOC);

        die(json_encode([
            'success' => true,
            'seasons' => $seasons
        ]));

    } elseif ($method === 'POST') {
        $csrfToken = $_POST['csrf_token'] ?? '';
        if (!verifyCSRFToken($csrfToken)) {
            http_response_code(403);
            die(json_encode(['success' => false, 'message' => 'CSRF token invalid']));
        }

        $id = isset($_POST['id']) ? (int)$_POST['id'] : null;
        $seasonKey = trim($_POST['season_key'] ?? '');
        $label = trim($_POST['label'] ?? '');
        $startMonth = max(1, min(12, (int)($_POST['start_month'] ?? 1)));
        $startDay = max(1, min(31, (int)($_POST['start_day'] ?? 1)));
        $endMonth = max(1, min(12, (int)($_POST['end_month'] ?? 12)));
        $endDay = max(1, min(31, (int)($_POST['end_day'] ?? 31)));
        $boost = max(0, min(100, (int)($_POST['priority_boost'] ?? 20)));
        $isActive = (int)($_POST['is_active'] ?? 1);

        if (!$seasonKey || !$label) {
            http_response_code(400);
            die(json_encode(['success' => false, 'message' => 'season_key and label required']));
        }

        if ($id) {
            // Update
            $stmt = $db->prepare("
                UPDATE seo_seasons
                SET season_key = ?, label = ?, start_month = ?, start_day = ?, end_month = ?, end_day = ?, priority_boost = ?, is_active = ?
                WHERE id = ?
            ");
            $stmt->execute([$seasonKey, $label, $startMonth, $startDay, $endMonth, $endDay, $boost, $isActive, $id]);

            die(json_encode(['success' => true, 'message' => 'Season updated', 'id' => $id]));
        } else {
            // Insert
            $stmt = $db->prepare("
                INSERT INTO seo_seasons (season_key, label, start_month, start_day, end_month, end_day, priority_boost, is_active)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?)
            ");
            $stmt->execute([$seasonKey, $label, $startMonth, $startDay, $endMonth, $endDay, $boost, $isActive]);

            die(json_encode(['success' => true, 'message' => 'Season created', 'id' => $db->lastInsertId()]));
        }

    } elseif ($method === 'DELETE') {
        $csrfToken = $_POST['csrf_token'] ?? $_GET['csrf_token'] ?? '';
        if (!verifyCSRFToken($csrfToken)) {
            http_response_code(403);
            die(json_encode(['success' => false, 'message' => 'CSRF token invalid']));
        }

        $id = (int)($_POST['id'] ?? $_GET['id'] ?? 0);
        if (!$id) {
            http_response_code(400);
            die(json_encode(['success' => false, 'message' => 'id required']));
        }

        // Deactivate instead of delete
        $stmt = $db->prepare("UPDATE seo_seasons SET is_active = 0 WHERE id = ?");
        $stmt->execute([$id]);

        die(json_encode(['success' => true, 'message' => 'Season deactivated']));

    } else {
        http_response_code(405);
        die(json_encode(['success' => false, 'message' => 'Method not allowed']));
    }

} catch (Throwable $e) {
    error_log("SEO Seasons Error: " . $e->getMessage());
    http_response_code(500);
    die(json_encode(['success' => false, 'message' => 'Error: ' . $e->getMessage()]));
}

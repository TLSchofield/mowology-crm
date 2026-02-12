<?php
/**
 * /app/Modules/Marketing/Api/targets.php
 * AJAX Endpoint: Manage SEO targets (city/postcode/neighbourhood)
 *
 * GET: List targets
 * POST: Create/update target
 * DELETE: Deactivate target
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
        // List all targets
        $stmt = $db->query("
            SELECT id, name, target_type, canonical_slug, city, postcode_prefix, neighbourhood, priority_weight, is_active
            FROM seo_targets
            ORDER BY target_type, name
        ");
        $targets = $stmt->fetchAll(PDO::FETCH_ASSOC);

        die(json_encode([
            'success' => true,
            'targets' => $targets
        ]));

    } elseif ($method === 'POST') {
        $csrfToken = $_POST['csrf_token'] ?? '';
        if (!verifyCSRFToken($csrfToken)) {
            http_response_code(403);
            die(json_encode(['success' => false, 'message' => 'CSRF token invalid']));
        }

        $id = isset($_POST['id']) ? (int)$_POST['id'] : null;
        $name = trim($_POST['name'] ?? '');
        $targetType = $_POST['target_type'] ?? '';
        $canonicalSlug = trim($_POST['canonical_slug'] ?? '');
        $city = trim($_POST['city'] ?? '') ?: null;
        $postcode = trim($_POST['postcode_prefix'] ?? '') ?: null;
        $neighbourhood = trim($_POST['neighbourhood'] ?? '') ?: null;
        $weight = max(1, min(200, (int)($_POST['priority_weight'] ?? 100)));
        $isActive = (int)($_POST['is_active'] ?? 1);

        if (!$name || !$targetType || !$canonicalSlug) {
            http_response_code(400);
            die(json_encode(['success' => false, 'message' => 'name, target_type, and canonical_slug required']));
        }

        if ($id) {
            // Update
            $stmt = $db->prepare("
                UPDATE seo_targets
                SET name = ?, city = ?, postcode_prefix = ?, neighbourhood = ?, priority_weight = ?, is_active = ?
                WHERE id = ?
            ");
            $stmt->execute([$name, $city, $postcode, $neighbourhood, $weight, $isActive, $id]);

            die(json_encode(['success' => true, 'message' => 'Target updated', 'id' => $id]));
        } else {
            // Insert
            $stmt = $db->prepare("
                INSERT INTO seo_targets (name, target_type, canonical_slug, city, postcode_prefix, neighbourhood, priority_weight, is_active)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?)
            ");
            $stmt->execute([$name, $targetType, $canonicalSlug, $city, $postcode, $neighbourhood, $weight, $isActive]);

            die(json_encode(['success' => true, 'message' => 'Target created', 'id' => $db->lastInsertId()]));
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

        // Deactivate instead of delete (preserve history)
        $stmt = $db->prepare("UPDATE seo_targets SET is_active = 0 WHERE id = ?");
        $stmt->execute([$id]);

        die(json_encode(['success' => true, 'message' => 'Target deactivated']));

    } else {
        http_response_code(405);
        die(json_encode(['success' => false, 'message' => 'Method not allowed']));
    }

} catch (Throwable $e) {
    error_log("SEO Targets Error: " . $e->getMessage());
    http_response_code(500);
    die(json_encode(['success' => false, 'message' => 'Error: ' . $e->getMessage()]));
}

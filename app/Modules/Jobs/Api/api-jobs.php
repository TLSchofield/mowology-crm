<?php
/**
 * Jobs API Handler
 * Handles AJAX operations for jobs (bulk-delete, etc.)
 */

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

requireLogin();
$user = getCurrentUser();
requirePermission('jobs.edit');

header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'error' => 'Method not allowed']);
    exit;
}

$csrfToken = $_POST['csrf_token'] ?? $_SERVER['HTTP_X_CSRF_TOKEN'] ?? '';
if (!verifyCSRFToken($csrfToken)) {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'Invalid CSRF token']);
    exit;
}

$action = $_GET['action'] ?? '';

try {
    $db = getDB();

    if ($action === 'bulk-delete') {
        $input = json_decode(file_get_contents('php://input'), true);
        $ids = array_filter(array_map('intval', $input['ids'] ?? []), function ($id) {
            return $id > 0;
        });

        if (empty($ids)) {
            throw new Exception('No valid plan IDs provided');
        }

        if (count($ids) > 100) {
            throw new Exception('Maximum 100 plans can be deleted at once');
        }

        $db->beginTransaction();
        try {
            $placeholders = implode(',', array_fill(0, count($ids), '?'));

            // Delete visits first (child rows)
            $db->prepare("DELETE FROM job_visits WHERE plan_id IN ({$placeholders})")->execute($ids);

            // Clean up activity_log references (no FK constraint)
            try {
                $db->prepare("UPDATE activity_log SET plan_id = NULL WHERE plan_id IN ({$placeholders})")->execute($ids);
            } catch (Exception $e) {
                // activity_log may not have plan_id column — ignore
            }

            // Release quote line items so they return to the quote's "available" pool
            // for re-allocation (no FK on quote_line_items.plan_id — must be explicit,
            // otherwise the items orphan to a deleted plan).
            try {
                $db->prepare("UPDATE quote_line_items SET plan_id = NULL WHERE plan_id IN ({$placeholders})")->execute($ids);
            } catch (Exception $e) {
                // quote_line_items.plan_id may be absent in some environments — ignore
            }

            // Delete plans
            $stmt = $db->prepare("DELETE FROM job_plans WHERE id IN ({$placeholders})");
            $stmt->execute($ids);
            $deleted = $stmt->rowCount();

            $db->commit();

            echo json_encode([
                'success' => true,
                'deleted_count' => $deleted,
                'message' => $deleted . ' plan(s) deleted'
            ]);
        } catch (Exception $e) {
            $db->rollBack();
            throw $e;
        }
    } elseif ($action === 'bulk-delete-plans') {
        $input = json_decode(file_get_contents('php://input'), true);
        $ids = array_filter(array_map('intval', $input['ids'] ?? []), function ($id) {
            return $id > 0;
        });

        if (empty($ids)) {
            throw new Exception('No valid plan IDs provided');
        }

        if (count($ids) > 100) {
            throw new Exception('Maximum 100 plans can be deleted at once');
        }

        $db->beginTransaction();
        try {
            $placeholders = implode(',', array_fill(0, count($ids), '?'));

            // Delete visits first (child rows)
            $db->prepare("DELETE FROM job_visits WHERE plan_id IN ({$placeholders})")->execute($ids);

            // Clean up activity_log references
            try {
                $db->prepare("UPDATE activity_log SET plan_id = NULL WHERE plan_id IN ({$placeholders})")->execute($ids);
            } catch (Exception $e) {
                // activity_log may not have plan_id column — ignore
            }

            // Release quote line items so they return to the quote's "available" pool
            // for re-allocation (no FK on quote_line_items.plan_id — must be explicit,
            // otherwise the items orphan to a deleted plan).
            try {
                $db->prepare("UPDATE quote_line_items SET plan_id = NULL WHERE plan_id IN ({$placeholders})")->execute($ids);
            } catch (Exception $e) {
                // quote_line_items.plan_id may be absent in some environments — ignore
            }

            // Delete plans
            $stmt = $db->prepare("DELETE FROM job_plans WHERE id IN ({$placeholders})");
            $stmt->execute($ids);
            $deleted = $stmt->rowCount();

            $db->commit();

            echo json_encode([
                'success' => true,
                'deleted_count' => $deleted,
                'message' => $deleted . ' plan(s) deleted'
            ]);
        } catch (Exception $e) {
            $db->rollBack();
            throw $e;
        }
    } else {
        http_response_code(400);
        echo json_encode(['success' => false, 'error' => 'Invalid action']);
    }
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
}

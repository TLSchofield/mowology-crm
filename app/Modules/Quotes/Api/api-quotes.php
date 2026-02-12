<?php
/**
 * Quotes API — Bulk operations
 *
 * Actions:
 * - bulk-delete: Permanently delete multiple quotes
 *
 * Requires authentication. POST only.
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

requireLogin();
$user = getCurrentUser();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'error' => 'Method not allowed']);
    exit;
}

header('Content-Type: application/json');

$action = $_GET['action'] ?? null;
$input = json_decode(file_get_contents('php://input'), true) ?? [];

try {
    $db = getDB();

    if ($action === 'bulk-delete') {
        $ids = array_filter(array_map('intval', $input['ids'] ?? []), function ($id) {
            return $id > 0;
        });

        if (empty($ids)) {
            throw new Exception('No valid quote IDs provided');
        }

        if (count($ids) > 100) {
            throw new Exception('Maximum 100 quotes can be deleted at once');
        }

        $db->beginTransaction();
        try {
            $placeholders = implode(',', array_fill(0, count($ids), '?'));

            // Clean up activity_log references (no FK constraint)
            try {
                $db->prepare("UPDATE activity_log SET quote_id = NULL WHERE quote_id IN ({$placeholders})")->execute($ids);
            } catch (Exception $e) {
                // activity_log may not have quote_id column — ignore
            }

            // Delete quotes (cascades: quote_line_items, quote_notes; SET NULL: quote_requests.quote_id, jobs.quote_id)
            $stmt = $db->prepare("DELETE FROM quotes WHERE id IN ({$placeholders})");
            $stmt->execute($ids);
            $deleted = $stmt->rowCount();

            $db->commit();

            echo json_encode([
                'success' => true,
                'deleted_count' => $deleted,
                'message' => $deleted . ' quote(s) deleted'
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
    echo json_encode([
        'success' => false,
        'error' => 'Delete failed: ' . $e->getMessage()
    ]);
}

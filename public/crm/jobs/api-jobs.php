<?php
/**
 * Jobs API Handler
 * Handles AJAX operations for jobs (bulk-delete, etc.)
 */
require_once dirname(__DIR__) . '/../loginAuth/auth.php';
require_once dirname(__DIR__) . '/includes/functions.php';

requireLogin();

header('Content-Type: application/json');

$action = $_GET['action'] ?? '';

try {
    $db = getDB();

    if ($action === 'bulk-delete') {
        $input = json_decode(file_get_contents('php://input'), true);
        $ids = array_filter(array_map('intval', $input['ids'] ?? []), function ($id) {
            return $id > 0;
        });

        if (empty($ids)) {
            throw new Exception('No valid job IDs provided');
        }

        if (count($ids) > 100) {
            throw new Exception('Maximum 100 jobs can be deleted at once');
        }

        $db->beginTransaction();
        try {
            $placeholders = implode(',', array_fill(0, count($ids), '?'));

            // Clean up activity_log references (no FK constraint)
            try {
                $db->prepare("UPDATE activity_log SET job_id = NULL WHERE job_id IN ({$placeholders})")->execute($ids);
            } catch (Exception $e) {
                // activity_log may not have job_id column — ignore
            }

            // Delete jobs (cascades: job_notes, job_photos, job_proof_of_work; SET NULL: invoices.job_id, invoice_line_items.job_id, jobs.parent_job_id)
            $stmt = $db->prepare("DELETE FROM jobs WHERE id IN ({$placeholders})");
            $stmt->execute($ids);
            $deleted = $stmt->rowCount();

            $db->commit();

            echo json_encode([
                'success' => true,
                'deleted_count' => $deleted,
                'message' => $deleted . ' job(s) deleted'
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

<?php
/**
 * Quotes API — Bulk operations
 *
 * Actions:
 * - bulk-delete: Permanently delete multiple quotes
 * - decline:     the owner declines a sent/viewed quote ({id, lost_reason?}); sends nothing (QuoteService::decline)
 * - reopen:      a declined quote goes back to sent ({id}); sends nothing (QuoteService::reopen)
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
require_once APP_ROOT . '/Core/ApiResponse.php'; // generic errors to the user, details to the log
require_once CRM_INCLUDES . '/functions.php';
require_once APP_ROOT . '/Modules/Quotes/Services/QuoteService.php';

requireLogin();
$user = getCurrentUser();
requirePermission('billing.edit');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'error' => 'Method not allowed']);
    exit;
}

header('Content-Type: application/json');

$csrfToken = $_POST['csrf_token'] ?? $_SERVER['HTTP_X_CSRF_TOKEN'] ?? '';
if (!verifyCSRFToken($csrfToken)) {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'Invalid CSRF token']);
    exit;
}

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
    } elseif ($action === 'decline' || $action === 'reopen') {
        // The same moves the quote page's Decline / Reopen make (view.php), for Sam's flow line.
        $id = (int)($input['id'] ?? 0);
        $svc = new QuoteService($db);
        $q = $id > 0 ? $svc->getWithContact($id) : null;
        if (!$q) {
            http_response_code(404);
            echo json_encode(['success' => false, 'error' => 'Quote not found']);
            exit;
        }
        $reason = (string)($input['lost_reason'] ?? '');
        $done = $action === 'decline' ? $svc->decline($id, $reason) : $svc->reopen($id);
        if (!$done) {
            echo json_encode(['success' => false, 'message' => $action === 'decline'
                ? 'That quote is not waiting for an answer any more.' : 'That quote is not declined.']);
            exit;
        }
        $new = $action === 'decline' ? 'declined' : 'sent';
        trackFieldChange('quote', $id, 'status', (string)$q['status'], $new, (int)$user['id']);
        $why = ($action === 'decline' && isset(QuoteService::LOST_REASONS[$reason])) ? ' (' . QuoteService::LOST_REASONS[$reason] . ')' : '';
        logActivityExtended((int)$user['id'], $action === 'decline' ? 'Quote declined (flow line)' : 'Quote reopened (flow line)',
            ($action === 'decline' ? 'Declined' : 'Reopened') . " by {$user['name']} from the flow line{$why}", null, null, $id);
        echo json_encode(['success' => true, 'message' => $action === 'decline'
            ? $q['quote_number'] . ' declined. Nothing was sent.' : $q['quote_number'] . ' reopened.']);
    } else {
        http_response_code(400);
        echo json_encode(['success' => false, 'error' => 'Invalid action']);
    }
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'error' => ($action === 'bulk-delete' ? 'Delete failed: ' : 'Could not save: ') . ApiResponse::message($e, 'api-quotes')
    ]);
}

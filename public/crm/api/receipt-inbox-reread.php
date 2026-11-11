<?php
/**
 * Read an emailed receipt again (ReceiptInboxRereadService) - for the ones the first read left blank.
 * Fills blank fields only, never approves or dismisses anything.
 *
 * POST:
 *   csrf_token   string (required)
 *   action       'reread' (one receipt, needs expense_id) | 'reread_unread' (every waiting emailed
 *                receipt that is still 0.00 or flagged, up to 30 per call)
 *   dry_run      '1' = report what would be filled and write nothing
 *   expense_id   int (reread)
 *
 * Returns JSON: { ok, results: [{id, status, note, fills?}], counts?, remaining? }
 */
declare(strict_types=1);
header('Content-Type: application/json');

$__dir = __DIR__;
for ($__i = 0; $__i < 5; $__i++) {
    $__dir = dirname($__dir);
    if (is_file($__dir . '/app/Core/paths.php')) { require_once $__dir . '/app/Core/paths.php'; break; }
}
unset($__dir, $__i);

require_once PUBLIC_ROOT . '/loginAuth/auth.php';
require_once CRM_INCLUDES . '/functions.php';
require_once APP_ROOT . '/Modules/Expenses/Services/ReceiptInboxRereadService.php';

requireLogin();
$user = getCurrentUser();
requirePermission('expenses.edit');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['ok' => false, 'message' => 'Method not allowed']);
    exit;
}
if (!verifyCSRFToken($_POST['csrf_token'] ?? '')) {
    http_response_code(403);
    echo json_encode(['ok' => false, 'message' => 'Invalid CSRF token']);
    exit;
}

@set_time_limit(180);   // each OCR read can take a while
$action = $_POST['action'] ?? '';
$dryRun = ($_POST['dry_run'] ?? '') === '1';
$service = new ReceiptInboxRereadService(getDB());

try {
    if ($action === 'reread') {
        $id = (int)($_POST['expense_id'] ?? 0);
        if ($id <= 0) {
            http_response_code(400);
            echo json_encode(['ok' => false, 'message' => 'Missing expense_id']);
            exit;
        }
        echo json_encode(['ok' => true, 'results' => [$service->reread($id, (int)$user['id'], $dryRun)]]);
    } elseif ($action === 'reread_unread') {
        $r = $service->rereadUnread((int)$user['id'], $dryRun);
        echo json_encode(['ok' => true] + $r);
    } else {
        http_response_code(400);
        echo json_encode(['ok' => false, 'message' => 'Unknown action']);
    }
} catch (\Throwable $e) {
    error_log('[receipt-inbox-reread] ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['ok' => false, 'message' => 'Server error']);
}

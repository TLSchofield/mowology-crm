<?php
/**
 * Approve or dismiss a pending emailed receipt (expense created with source='email_inbox',
 * status='draft'). Approving applies any edits and flips it to 'approved' so the
 * sync-ledger cron posts it to the books.
 *
 * POST:
 *   csrf_token           string (required)
 *   action               string 'approve' | 'dismiss'
 *   expense_id           int    (required)
 *   vendor_id            int    (optional, approve)
 *   expense_date         date   (optional, approve)
 *   total, gst_amount, pst_amount  float (optional, approve)
 *   accounting_category  string (optional, approve)
 *
 * Returns JSON: { ok: bool, message: string }
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
require_once APP_ROOT . '/Modules/Expenses/Services/ReceiptInboxService.php';

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

$action    = $_POST['action'] ?? '';
$expenseId = (int) ($_POST['expense_id'] ?? 0);
if ($expenseId <= 0) {
    http_response_code(400);
    echo json_encode(['ok' => false, 'message' => 'Missing expense_id']);
    exit;
}

$service = new ReceiptInboxService(getDB());

try {
    if ($action === 'dismiss') {
        $result = $service->dismiss($expenseId, (int) $user['id']);
    } elseif ($action === 'approve') {
        $edits = [
            'vendor_id'           => $_POST['vendor_id']           ?? '',
            'expense_date'        => $_POST['expense_date']        ?? '',
            'total'               => $_POST['total']               ?? '',
            'gst_amount'          => $_POST['gst_amount']          ?? '',
            'pst_amount'          => $_POST['pst_amount']          ?? '',
            'accounting_category' => $_POST['accounting_category'] ?? '',
        ];
        $result = $service->approve($expenseId, $edits, (int) $user['id']);
    } else {
        http_response_code(400);
        echo json_encode(['ok' => false, 'message' => 'Unknown action']);
        exit;
    }
    echo json_encode($result);
} catch (\Throwable $e) {
    error_log('[receipt-inbox-confirm] ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['ok' => false, 'message' => 'Server error']);
}

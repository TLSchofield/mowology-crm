<?php
/**
 * Receipt Send API — Forwards receipt to accounting email (QuickBooks inbox)
 *
 * POST only, CSRF protected, requires expenses.send permission.
 *
 * Body: {
 *   csrf_token: string,
 *   expense_id: int,          // required: which expense to send
 *   // Optional overrides:
 *   vendor: string,
 *   total: string,
 *   date: string,
 *   job_id: int,
 *   client_name: string,
 *   accounting_category: string,
 * }
 *
 * Returns: { success: bool, message: string, log_id: int|null }
 */
declare(strict_types=1);
header('Content-Type: application/json');

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

try {
    require_once PUBLIC_ROOT . '/loginAuth/auth.php';
    require_once CRM_INCLUDES . '/functions.php';
    require_once APP_ROOT . '/Services/Receipts/ReceiptService.php';

    requireLogin();
    $user = getCurrentUser();
    requirePermission('expenses.send');

    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        http_response_code(405);
        echo json_encode(['success' => false, 'error' => 'POST required']);
        exit;
    }

    $input = json_decode(file_get_contents('php://input'), true);

    if (!verifyCSRFToken($input['csrf_token'] ?? '')) {
        http_response_code(403);
        echo json_encode(['success' => false, 'error' => 'Invalid security token']);
        exit;
    }
    session_write_close();

    $action = $_GET['action'] ?? 'send';

    // Test action — sends a test email to verify accounting email config
    if ($action === 'test') {
        $db = getDB();
        $config = getReceiptForwardingConfig($db);
        if (empty($config['accounting_email'])) {
            throw new Exception('No accounting email configured. Save the email address first.');
        }
        $testResult = sendEmail(
            $config['accounting_email'],
            'Test Receipt — Mowology CRM',
            '<h2>Receipt Forwarding Test</h2>'
            . '<p>This is a test email from the Mowology CRM receipt forwarding system.</p>'
            . '<p>If you receive this, your QuickBooks receipt email is configured correctly.</p>'
            . '<p><em>Sent at ' . date('Y-m-d H:i:s') . '</em></p>',
            null,
            $config['from_name'] ?: 'Mowology Receipts'
        );
        echo json_encode([
            'success' => $testResult['success'],
            'message' => $testResult['success'] ? 'Test email sent' : 'Failed: ' . ($testResult['error'] ?? 'Unknown'),
        ]);
        exit;
    }

    $expenseId = (int)($input['expense_id'] ?? 0);
    if (!$expenseId) {
        throw new Exception('Expense ID is required');
    }

    // Load expense data
    $db = getDB();
    $stmt = $db->prepare("
        SELECT e.*, v.name AS vendor_name,
               c.first_name AS client_first, c.last_name AS client_last,
               p.address AS property_address
        FROM expenses e
        LEFT JOIN vendors v ON v.id = e.vendor_id
        LEFT JOIN contacts c ON c.id = e.contact_id
        LEFT JOIN properties p ON p.id = e.property_id
        WHERE e.id = ?
    ");
    $stmt->execute([$expenseId]);
    $expense = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$expense) {
        throw new Exception('Expense not found');
    }

    // --- Idempotency check: block duplicate forwards ---
    // Once a receipt has been forwarded to QuickBooks, refuse to re-send
    // unless the user explicitly resets the status. Prevents duplicate
    // entries in accounting from double-clicks or retries.
    if (!empty($expense['forwarded_to_accounting'])) {
        $forwardedAt = $expense['forwarded_at'] ? date('M j, Y g:ia', strtotime($expense['forwarded_at'])) : 'previously';
        echo json_encode([
            'success' => false,
            'error'   => 'This receipt was already forwarded to QuickBooks on ' . $forwardedAt . '. Open the expense to reset and re-send if needed.',
            'log_id'  => null,
        ]);
        exit;
    }

    // Build send options, allowing input overrides
    $clientName = trim(($expense['client_first'] ?? '') . ' ' . ($expense['client_last'] ?? ''));
    if (!empty($expense['property_address'])) {
        $clientName .= $clientName ? ' - ' . $expense['property_address'] : $expense['property_address'];
    }

    $opts = [
        'expense_id'          => $expenseId,
        'media_id'            => $expense['receipt_media_id'] ? (int)$expense['receipt_media_id'] : null,
        'vendor'              => $input['vendor'] ?? $expense['vendor_name'] ?? $expense['vendor_name_raw'] ?? 'Unknown',
        'subtotal'            => (string)($expense['amount'] ?? '0.00'),
        'gst_amount'          => (string)($expense['gst_amount'] ?? '0.00'),
        'total'               => $input['total'] ?? (string)$expense['total'],
        'date'                => $input['date'] ?? $expense['expense_date'],
        'job_id'              => $input['job_id'] ?? $expense['job_id'],
        'client_name'         => $input['client_name'] ?? $clientName,
        'description'         => $expense['description'] ?? '',
        'accounting_category' => $input['accounting_category'] ?? $expense['accounting_category'] ?? '',
    ];

    // If no media_id on expense, check if one was provided directly
    if (empty($opts['media_id']) && !empty($input['media_id'])) {
        $opts['media_id'] = (int)$input['media_id'];
    }

    if (empty($opts['media_id'])) {
        throw new Exception('No receipt image attached to this expense');
    }

    $result = sendReceiptToAccounting($opts);

    echo json_encode($result);

} catch (Exception $e) {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => $e->getMessage(), 'log_id' => null]);
}

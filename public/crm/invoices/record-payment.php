<?php
/**
 * Record Payment — handles single or bulk invoice payment recording.
 *
 * Accepts POST with:
 *   csrf_token      string  (required)
 *   invoice_ids[]   int[]   (required, 1-N invoice IDs)
 *   payment_method  string  (required: e_transfer|cash|cheque|credit_card|other)
 *   payment_amount  float[] (optional per-invoice override; if omitted, uses balance_due)
 *   transaction_ref string  (optional: e-Transfer confirmation # / cheque #)
 *   payment_date    string  (optional: YYYY-MM-DD, defaults to today)
 *   notes           string  (optional: internal notes)
 *
 * Returns JSON: { success: bool, recorded: int[], errors: string[], message: string }
 */
require_once dirname(__DIR__) . '/../loginAuth/auth.php';
require_once dirname(__DIR__) . '/includes/functions.php';

requireLogin();
$user = getCurrentUser();
requirePermission('billing.view');

header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'message' => 'Method not allowed']);
    exit;
}

// CSRF
if (!verifyCSRFToken($_POST['csrf_token'] ?? '')) {
    http_response_code(403);
    echo json_encode(['success' => false, 'message' => 'Invalid CSRF token']);
    exit;
}

// Parse inputs
$rawIds     = $_POST['invoice_ids'] ?? [];
$invoiceIds = array_map('intval', (array) $rawIds);
$invoiceIds = array_filter($invoiceIds, fn($id) => $id > 0);
$invoiceIds = array_values(array_unique($invoiceIds));

if (empty($invoiceIds)) {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'No invoice IDs provided']);
    exit;
}

$validMethods  = ['e_transfer', 'cash', 'cheque', 'credit_card', 'other'];
$paymentMethod = trim($_POST['payment_method'] ?? 'other');
if (!in_array($paymentMethod, $validMethods)) {
    $paymentMethod = 'other';
}

$transactionRef = trim($_POST['transaction_ref'] ?? '');
$notes          = trim($_POST['notes'] ?? '');
$paymentDate    = trim($_POST['payment_date'] ?? '');

// Validate date
if ($paymentDate && !preg_match('/^\d{4}-\d{2}-\d{2}$/', $paymentDate)) {
    $paymentDate = '';
}
$paidAt = $paymentDate ? $paymentDate . ' 12:00:00' : null; // null = NOW() in SQL

// Per-invoice amount overrides (keyed by invoice_id)
$amountOverrides = [];
if (!empty($_POST['payment_amount']) && is_array($_POST['payment_amount'])) {
    foreach ($_POST['payment_amount'] as $id => $amt) {
        $id = intval($id);
        $amt = floatval($amt);
        if ($id > 0 && $amt > 0) {
            $amountOverrides[$id] = $amt;
        }
    }
}

$db = getDB();

// Load invoices — only ones that can accept payment
$placeholders = implode(',', array_fill(0, count($invoiceIds), '?'));
$stmt = $db->prepare("
    SELECT i.id, i.invoice_number, i.balance_due, i.amount_paid, i.total, i.status,
           COALESCE(co.company_name, '') as company_name,
           COALESCE(ct.first_name,'') as contact_first,
           COALESCE(ct.last_name,'') as contact_last
    FROM invoices i
    LEFT JOIN companies co ON i.company_id = co.id
    LEFT JOIN contacts  ct ON i.contact_id = ct.id
    WHERE i.id IN ({$placeholders})
    AND   i.status IN ('sent','viewed','partial','overdue','draft')
    ORDER BY i.id
");
$stmt->execute($invoiceIds);
$invoices = $stmt->fetchAll(PDO::FETCH_ASSOC);

if (empty($invoices)) {
    http_response_code(404);
    echo json_encode(['success' => false, 'message' => 'No payable invoices found for the given IDs']);
    exit;
}

// Process each invoice
$recorded = [];
$errors   = [];

foreach ($invoices as $inv) {
    $id        = $inv['id'];
    $balanceDue = floatval($inv['balance_due']);

    // Use per-invoice override if provided, else full balance
    $payAmount = isset($amountOverrides[$id]) ? $amountOverrides[$id] : $balanceDue;

    if ($payAmount <= 0) {
        $errors[] = "Invoice {$inv['invoice_number']}: amount must be greater than zero";
        continue;
    }

    $newAmountPaid = floatval($inv['amount_paid']) + $payAmount;
    $newBalance    = max(0, $balanceDue - $payAmount);
    $newStatus     = $newBalance <= 0.005 ? 'paid' : 'partial'; // treat < 0.5¢ as paid

    try {
        $db->beginTransaction();

        $paidAtSql = $paidAt ? '?' : 'NOW()';
        $params    = $paidAt
            ? [$newAmountPaid, $newBalance, $newStatus, $paymentMethod, $transactionRef, $paidAt, $id]
            : [$newAmountPaid, $newBalance, $newStatus, $paymentMethod, $transactionRef, $id];

        $db->prepare("
            UPDATE invoices
            SET amount_paid      = ?,
                balance_due      = ?,
                status           = ?,
                payment_method   = ?,
                payment_reference= ?,
                paid_at          = {$paidAtSql}
            WHERE id = ?
        ")->execute($params);

        // Activity log
        $client = $inv['company_name']
            ?: trim("{$inv['contact_first']} {$inv['contact_last']}")
            ?: "Invoice #{$inv['invoice_number']}";
        $methodLabel = [
            'e_transfer'  => 'e-Transfer',
            'cash'        => 'Cash',
            'cheque'      => 'Cheque',
            'credit_card' => 'Credit Card',
            'other'       => 'Other',
        ][$paymentMethod] ?? ucfirst($paymentMethod);

        $detail = "Payment of " . number_format($payAmount, 2) . " recorded via {$methodLabel}";
        if ($transactionRef) {
            $detail .= " (Ref: {$transactionRef})";
        }
        if ($notes) {
            $detail .= " — {$notes}";
        }

        logActivityExtended($user['id'], 'Payment recorded', $detail, null, null, null, $id);

        $db->commit();
        $recorded[] = $id;

    } catch (PDOException $e) {
        if ($db->inTransaction()) $db->rollBack();
        error_log("record-payment.php PDO error invoice {$id}: " . $e->getMessage());
        $errors[] = "Invoice {$inv['invoice_number']}: database error";
    }
}

$totalRecorded = count($recorded);
$total         = count($invoices);

if ($totalRecorded === 0) {
    $message = 'No payments were recorded. ' . implode('; ', $errors);
    echo json_encode(['success' => false, 'message' => $message, 'recorded' => [], 'errors' => $errors]);
} else {
    $message = $totalRecorded === 1
        ? 'Payment recorded successfully.'
        : "{$totalRecorded} payments recorded successfully.";
    if (!empty($errors)) {
        $message .= ' Errors: ' . implode('; ', $errors);
    }
    echo json_encode(['success' => true, 'message' => $message, 'recorded' => $recorded, 'errors' => $errors]);
}

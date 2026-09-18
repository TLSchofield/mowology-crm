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
requirePermission('billing.edit');

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
require_once APP_ROOT . '/Modules/Accounting/Services/InvoiceReconciliationService.php';
$reconSvc = new InvoiceReconciliationService($db);

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

    // Guard against overpayment when a manual amount override is supplied.
    // A tolerance of 0.5¢ covers float rounding on exact-balance payments.
    if ($payAmount > $balanceDue + 0.005) {
        $errors[] = "Invoice {$inv['invoice_number']}: payment \$" . number_format($payAmount, 2)
            . " exceeds balance due \$" . number_format($balanceDue, 2);
        continue;
    }

    try {
        $db->beginTransaction();

        // Write through the shared allocation ledger so a later bank import can
        // link this payment to the deposit that carried it (BankImportService →
        // InvoiceReconciliationService::linkRecordedPaymentsToDeposit). Recording
        // straight onto the invoice row left the deposit orphaned as unmatched income.
        $alloc = $reconSvc->applyAllocation(
            (int)$id, (float)$payAmount, $paymentMethod, $transactionRef !== '' ? $transactionRef : null,
            $paymentDate ?: date('Y-m-d'), null, (int)$user['id']
        );
        if (!$alloc) {
            $db->rollBack();
            $errors[] = "Invoice {$inv['invoice_number']}: already settled";
            continue;
        }
        $newStatus = $alloc['status'];

        // Allow an explicit reference/method override even when the invoice already had one
        $db->prepare("
            UPDATE invoices
            SET payment_method = ?, payment_reference = ?, pdf_version = 0
            WHERE id = ?
        ")->execute([$paymentMethod, $transactionRef, $id]);

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

        // Auto-attribution: record invoice_paid when fully paid
        if ($newStatus === 'paid' && defined('APP_ROOT')) {
            $__attrSvc = APP_ROOT . '/Modules/Marketing/Services/AttributionService.php';
            if (file_exists($__attrSvc)) {
                require_once $__attrSvc;
                try {
                    AttributionService::onInvoicePaid($db, $id, $payAmount);
                } catch (\Throwable $__e) {
                    error_log('[record-payment] attribution error: ' . $__e->getMessage());
                }
            }
        }

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

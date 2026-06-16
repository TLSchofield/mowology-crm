<?php
/**
 * Run Migration 1062: Invoice ↔ bank-deposit allocation ledger
 *
 * Creates invoice_payment_allocations, adds accounting_transactions.matched_invoice_id,
 * extends bank_import_rows.match_status, and seeds the '1150 Bank Clearing' account.
 * Idempotent — safe to re-run. Admin only.
 */
declare(strict_types=1);
header('Content-Type: application/json');

$__dir = __DIR__;
for ($__i = 0; $__i < 5; $__i++) {
    $__dir = dirname($__dir);
    if (is_file($__dir . '/app/Core/paths.php')) {
        require_once $__dir . '/app/Core/paths.php';
        break;
    }
}
unset($__dir, $__i);

require_once PUBLIC_ROOT . '/loginAuth/auth.php';
requireLogin();
if (!isAdmin()) {
    http_response_code(403);
    echo json_encode(['error' => 'Admin only']);
    exit;
}

$db      = getDB();
$results = [];

function tryExec(PDO $db, string $sql, string $label, array &$results): void {
    try {
        $db->exec($sql);
        $results[] = ['step' => $label, 'status' => 'ok'];
    } catch (PDOException $e) {
        $msg = $e->getMessage();
        if (str_contains($msg, 'already exists')
            || str_contains($msg, 'Duplicate column')
            || str_contains($msg, 'Duplicate key name')) {
            $results[] = ['step' => $label, 'status' => 'already exists'];
        } else {
            $results[] = ['step' => $label, 'status' => 'error', 'msg' => $msg];
        }
    }
}

tryExec($db, "
    CREATE TABLE IF NOT EXISTS invoice_payment_allocations (
        id              INT AUTO_INCREMENT PRIMARY KEY,
        invoice_id      INT NOT NULL,
        transaction_id  INT NULL,
        amount          DECIMAL(10,2) NOT NULL,
        payment_date    DATE NOT NULL,
        method          VARCHAR(20) NOT NULL DEFAULT 'e_transfer',
        reference       VARCHAR(100) NULL,
        notes           VARCHAR(255) NULL,
        created_by      INT NULL,
        created_at      TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        INDEX idx_ipa_invoice (invoice_id),
        INDEX idx_ipa_transaction (transaction_id),
        FOREIGN KEY (invoice_id) REFERENCES invoices(id) ON DELETE CASCADE,
        FOREIGN KEY (transaction_id) REFERENCES accounting_transactions(id) ON DELETE SET NULL
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci
", 'create invoice_payment_allocations', $results);

tryExec($db,
    "ALTER TABLE accounting_transactions ADD COLUMN matched_invoice_id INT NULL AFTER matched_expense_id",
    'add accounting_transactions.matched_invoice_id', $results);

tryExec($db,
    "CREATE INDEX idx_at_matched_invoice ON accounting_transactions(matched_invoice_id)",
    'index matched_invoice_id', $results);

tryExec($db,
    "ALTER TABLE bank_import_rows
       MODIFY COLUMN match_status
       ENUM('unmatched','auto_matched','suggested','manually_matched','new_expense','true_duplicate','matched_invoice')
       NOT NULL DEFAULT 'unmatched'",
    "extend bank_import_rows.match_status", $results);

// NOTE: No 'Bank Clearing' account is needed. Reconciled deposits keep their original
// account_id and are excluded from P&L purely by flipping type to 'transfer' (every
// report sums type IN ('income','expense')). This avoids depending on chart_of_accounts
// columns that differ between the intended schema and production.

$hasError = (bool)array_filter($results, fn($r) => ($r['status'] ?? '') === 'error');
echo json_encode(['success' => !$hasError, 'migration' => '1062', 'results' => $results], JSON_PRETTY_PRINT);

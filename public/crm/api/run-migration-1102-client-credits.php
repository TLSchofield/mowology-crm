<?php
/**
 * Migration 1102 — Client prepaid-credit ledger.
 *
 * Idempotent + admin-only. Creates the client_credits table and adds
 * 'account_credit' to invoices.payment_method. Safe to run repeatedly.
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
requireLogin();
if (!isAdmin()) { http_response_code(403); echo json_encode(['error' => 'Admin only']); exit; }

$db   = getDB();
$done = [];

try {
    // ── client_credits table ──
    $hasTable = $db->query("SHOW TABLES LIKE 'client_credits'")->rowCount() > 0;
    if (!$hasTable) {
        $db->exec("
            CREATE TABLE client_credits (
                id            INT AUTO_INCREMENT PRIMARY KEY,
                client_id     INT NOT NULL,
                type          ENUM('deposit','applied','refund','adjustment') NOT NULL,
                amount        DECIMAL(10,2) NOT NULL,
                invoice_id    INT NULL,
                source_note   VARCHAR(255) NULL,
                created_by    INT NULL,
                created_at    TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                INDEX idx_cc_client (client_id),
                INDEX idx_cc_invoice (invoice_id),
                CONSTRAINT fk_cc_client  FOREIGN KEY (client_id)  REFERENCES clients(id)  ON DELETE CASCADE,
                CONSTRAINT fk_cc_invoice FOREIGN KEY (invoice_id) REFERENCES invoices(id) ON DELETE SET NULL
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci
        ");
        $done['client_credits'] = 'created';
    } else {
        $done['client_credits'] = 'already_present';
    }

    // ── invoices.payment_method enum: add 'account_credit' ──
    $col = $db->query("SHOW COLUMNS FROM invoices LIKE 'payment_method'")->fetch(PDO::FETCH_ASSOC);
    $hasAccountCredit = $col && strpos((string)$col['Type'], "'account_credit'") !== false;
    if (!$hasAccountCredit) {
        $db->exec("
            ALTER TABLE invoices
              MODIFY COLUMN payment_method ENUM('cash','cheque','e_transfer','credit_card','stripe','account_credit','other') NULL
        ");
        $done['invoices.payment_method'] = 'added account_credit';
    } else {
        $done['invoices.payment_method'] = 'already_present';
    }

    echo json_encode(['success' => true, 'migration' => '1102', 'result' => $done]);
} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => $e->getMessage(), 'done_so_far' => $done]);
}

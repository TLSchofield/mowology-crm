-- 1372 - one bank line paid by several receipts (2026-10-16, Tim: two Champion receipts rung through on one debit)
--
-- accounting_transactions.matched_expense_id stays the line's MAIN receipt, so every existing reader keeps
-- working. This table holds the OTHER receipts the same line paid. The line's own posting is retired only
-- when all of them together add up to the line exactly (LedgerService::retireLinkedBankEntries).

CREATE TABLE bank_line_receipts (
    id INT AUTO_INCREMENT PRIMARY KEY,
    transaction_id INT NOT NULL COMMENT 'accounting_transactions.id (the bank line)',
    expense_id INT NOT NULL COMMENT 'an extra receipt the line paid (the main one is matched_expense_id)',
    created_by INT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uq_blr_expense (expense_id),
    KEY idx_blr_transaction (transaction_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

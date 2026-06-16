-- Migration 1062: Invoice ↔ bank-deposit allocation ledger
-- Run on production via /crm/api/run-migration-invoice-allocations.php (admin only).
--
-- Lets an admin attach an imported Vancity deposit (accounting_transactions row,
-- reference_type='bank_import', type='income') to one or more unpaid invoices.
-- Income is recognized on the invoice rows (per-job); the deposit row's amount tracks
-- its unallocated remainder and flips to type='transfer' (Bank Clearing, excluded from
-- P&L) once fully allocated — so every dollar is counted exactly once.
--
-- Adds:
--   invoice_payment_allocations            — the auditable deposit→invoice payment ledger
--   accounting_transactions.matched_invoice_id  — convenience pointer (single-invoice match)
--   bank_import_rows.match_status += 'matched_invoice'
--   chart_of_accounts '1150 Bank Clearing' — asset/clearing account for reclassified deposits

CREATE TABLE IF NOT EXISTS invoice_payment_allocations (
    id              INT AUTO_INCREMENT PRIMARY KEY,
    invoice_id      INT NOT NULL,
    transaction_id  INT NULL COMMENT 'accounting_transactions.id of the bank deposit (NULL once deposit deleted/rolled back)',
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
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- Convenience pointer for the common single-invoice match (allocation table is source of truth)
ALTER TABLE accounting_transactions
  ADD COLUMN matched_invoice_id INT NULL AFTER matched_expense_id;

CREATE INDEX idx_at_matched_invoice ON accounting_transactions(matched_invoice_id);

-- Allow staging rows to record an invoice match
ALTER TABLE bank_import_rows
  MODIFY COLUMN match_status
    ENUM('unmatched','auto_matched','suggested','manually_matched','new_expense','true_duplicate','matched_invoice')
    NOT NULL DEFAULT 'unmatched';

-- Bank Clearing account — deposits reclassified to type='transfer' land here (asset, excluded from P&L).
-- Subqueries are nested in derived tables so MySQL allows referencing chart_of_accounts in an INSERT…SELECT.
INSERT INTO chart_of_accounts (code, name, type, subtype, normal_balance, parent_id, is_active, display_order, description)
SELECT '1150', 'Bank Clearing', 'asset', 'clearing', 'debit',
       (SELECT id FROM (SELECT id FROM chart_of_accounts WHERE code = '1000' LIMIT 1) AS p),
       1, 15, 'Imported deposits reconciled to invoices (cash-clearing, excluded from P&L)'
FROM DUAL
WHERE NOT EXISTS (SELECT 1 FROM (SELECT id FROM chart_of_accounts WHERE code = '1150' LIMIT 1) AS e);

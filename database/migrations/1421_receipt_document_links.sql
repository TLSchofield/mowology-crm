-- Migration 1421: receipt_document_links — a receipt kept as the DOCUMENT for an entry or bank line it does not itself book.
-- (Tim, 2026-10-26: "rejected receipts should be stored with a link to their expense line in case of audit".)
-- Example: receipt #16 (Midland Liquidators $53.21, printed 2025-04-29, card 7640) is the paper for bank line 5474 / journal entry #823
-- in the filed 2025 books. It is rejected (so it posts nothing) and linked here. Nothing in the ledger sync reads this table — it can
-- never retire or move an entry; it only lets the Ledger and an auditor click from the entry to the paper.
-- MySQL 5.7 safe: utf8mb4_general_ci, no IF NOT EXISTS on indexes (they are inline), idempotent CREATE TABLE IF NOT EXISTS.
CREATE TABLE IF NOT EXISTS receipt_document_links (
    id                  BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    expense_id          INT NOT NULL COMMENT 'the receipt (expenses.id); kept even when rejected',
    bank_transaction_id INT NULL COMMENT 'accounting_transactions.id of the bank line it documents',
    journal_entry_id    INT NULL COMMENT 'journal_entries.id it documents (the line''s own live entry when known)',
    relation            VARCHAR(30) NOT NULL DEFAULT 'supports' COMMENT 'supports | copy_of',
    note                VARCHAR(255) NULL,
    created_by          INT NULL,
    created_at          TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_rdl_expense_tx (expense_id, bank_transaction_id),
    KEY idx_rdl_entry (journal_entry_id),
    KEY idx_rdl_tx (bank_transaction_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

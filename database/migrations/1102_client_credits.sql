-- Migration 1102: Client prepaid-credit ledger
-- Run on production via /crm/api/run-migration-1102-client-credits.php (admin only).
--
-- Supports clients who pay a lump sum up front (e.g. a season prepayment) that should
-- be drawn down against individual invoices as they're raised, with any leftover usable
-- for other services. Scoped to `client_id` (not a single contract/plan), since the
-- balance is the client's money, not one job's.
--
-- Additive-only ledger, no mutable running-balance column: a client's balance is always
-- SUM(amount) WHERE client_id = ?. This mirrors the invoice_payment_allocations ledger
-- pattern from migration 1062 and avoids concurrent-write balance bugs.
--
-- MySQL 5.7-compatible: CREATE TABLE IF NOT EXISTS, plain MODIFY COLUMN (no IF NOT EXISTS
-- on the enum change), no DDL inside a txn.

CREATE TABLE IF NOT EXISTS client_credits (
    id            INT AUTO_INCREMENT PRIMARY KEY,
    client_id     INT NOT NULL,
    type          ENUM('deposit','applied','refund','adjustment') NOT NULL,
    amount        DECIMAL(10,2) NOT NULL COMMENT 'Positive for deposit/refund, negative for applied/adjustment-down',
    invoice_id    INT NULL COMMENT 'Set when type=applied: the invoice this credit was applied to',
    source_note   VARCHAR(255) NULL COMMENT 'e.g. "Cheque #1042, paid 2026-04-15"',
    created_by    INT NULL,
    created_at    TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_cc_client (client_id),
    INDEX idx_cc_invoice (invoice_id),
    CONSTRAINT fk_client_credits_client_id  FOREIGN KEY (client_id)  REFERENCES clients(id)  ON DELETE CASCADE,
    CONSTRAINT fk_client_credits_invoice_id FOREIGN KEY (invoice_id) REFERENCES invoices(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- Distinguish credit-offset invoices from real payments in reporting.
ALTER TABLE invoices
  MODIFY COLUMN payment_method ENUM('cash','cheque','e_transfer','credit_card','stripe','account_credit','other') NULL;

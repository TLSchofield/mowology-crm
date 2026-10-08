-- Migration 1228: Penny's income clean-up — booking log + filed GST returns
-- Date: 2026-10-07
-- Purpose: 2026 income was overstated (~$210K) because bank deposits never tied to their
--   invoices stayed type='income' next to the invoices' own income rows.
--   IncomeCleanupService proposes a fix per deposit; Tim approves; it books through the
--   existing reconciliation paths. Every booking / skip is logged here with the bank row as
--   it was (before_row), so each one can be undone exactly.
--     1. income_cleanup_log — one row per booked / skipped deposit.
--     2. gst_filings        — GST returns Tim has filed (period, basis). A change to a filed
--                             period is shown as "adjustment needed on your next return";
--                             filed numbers are never changed.
--   Creates tables only — nothing existing is changed. Safe to re-run.
-- MySQL 5.7 compatible (no JSON type/functions — before_row / detail are TEXT holding PHP json_encode output).

CREATE TABLE IF NOT EXISTS income_cleanup_log (
    id              INT AUTO_INCREMENT PRIMARY KEY,
    transaction_id  INT           NOT NULL COMMENT 'accounting_transactions.id of the bank deposit',
    bucket          VARCHAR(20)   NOT NULL COMMENT 'stripe | exact | exact_sum | needs_you | jobber',
    action          VARCHAR(30)   NOT NULL COMMENT 'link_payments | already_recorded | record_payment | stripe_payout | skip',
    amount          DECIMAL(10,2) NOT NULL,
    invoice_ids     VARCHAR(500)  NULL COMMENT 'comma list of invoices.id the deposit was tied to',
    invoice_numbers VARCHAR(500)  NULL,
    allocation_ids  VARCHAR(500)  NULL COMMENT 'comma list of invoice_payment_allocations.id linked (link_payments)',
    before_row      TEXT          NULL COMMENT 'the accounting_transactions row before booking (for undo)',
    detail          TEXT          NULL COMMENT 'what was done: Stripe fee line id, invoice row statuses, Penny''s sentence',
    status          ENUM('booked','skipped','unskipped','reversed') NOT NULL DEFAULT 'booked',
    note            VARCHAR(500)  NULL,
    booked_by       INT           NULL,
    booked_at       DATETIME      NULL,
    reversed_by     INT           NULL,
    reversed_at     DATETIME      NULL,
    INDEX idx_icl_tx (transaction_id),
    INDEX idx_icl_status (status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

CREATE TABLE IF NOT EXISTS gst_filings (
    id           INT AUTO_INCREMENT PRIMARY KEY,
    period_from  DATE          NOT NULL,
    period_to    DATE          NOT NULL,
    filed_on     DATE          NULL,
    basis        VARCHAR(20)   NOT NULL DEFAULT 'unknown' COMMENT 'ledger | invoices | accountant | unknown — where line 101 came from',
    line_101     DECIMAL(12,2) NULL COMMENT 'line 101 as filed, if known',
    notes        VARCHAR(500)  NULL,
    created_by   INT           NULL,
    created_at   TIMESTAMP     DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uq_gst_filing_period (period_from, period_to)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

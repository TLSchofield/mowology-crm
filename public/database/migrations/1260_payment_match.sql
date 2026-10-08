-- Migration 1260: payments reconciled against invoices — the unified matcher's log + Penny's cached count
-- Date: 2026-10-07
-- Purpose: PaymentMatchService (/crm/accounting/payment-match.php) matches every unmatched 2026 bank
--   deposit to the invoice(s) it paid — CRM invoices AND imported Jobber invoices / payments — and
--   books only what Tim approves, through the EXISTING paths:
--     CRM     → IncomeCleanupService (link recorded payment / already recorded / record payment;
--               logged in income_cleanup_log, undone by its reverse())
--     Jobber  → JobberLedgerService::bookMatched (one entry per deposit; the FY2025 part settles the
--               opening receivable, never 2026 income; logged in jobber_ledger_log, undone by batch)
--   This table records each approval / skip on top of those, so the page can undo it and never
--   offers a skipped deposit again until it is un-skipped.
--     1. payment_match_log      one row per booked / skipped deposit (path_ref = the underlying log)
--     2. payment_match_snapshot Penny's card line ("N deposits matched … waiting for you"), refreshed
--                               at most every few hours so the dashboard never runs the matcher itself
--   Creates tables only — nothing existing is changed. Safe to re-run.
-- MySQL 5.7 compatible (no JSON type/functions — targets / detail are TEXT holding json_encode output).

CREATE TABLE IF NOT EXISTS payment_match_log (
  id              INT AUTO_INCREMENT PRIMARY KEY,
  transaction_id  INT           NOT NULL COMMENT 'accounting_transactions.id of the bank deposit',
  confidence      VARCHAR(10)   NULL COMMENT 'high | medium | low | manual',
  source          VARCHAR(10)   NULL COMMENT 'crm | jobber',
  action          VARCHAR(30)   NOT NULL COMMENT 'link_payments | already_recorded | record_payment | jobber | skip',
  amount          DECIMAL(12,2) NOT NULL DEFAULT 0,
  fy2025_part     DECIMAL(12,2) NOT NULL DEFAULT 0 COMMENT 'paid 2025 Jobber invoices: settles the opening receivable, not 2026 income',
  targets         TEXT          NULL COMMENT 'json: what the deposit was tied to (keys, numbers, amounts)',
  signature       VARCHAR(40)   NULL,
  path            VARCHAR(20)   NULL COMMENT 'income_cleanup | jobber',
  path_ref        VARCHAR(60)   NULL COMMENT 'income_cleanup_log.id or jobber_ledger_log.batch_id',
  status          VARCHAR(12)   NOT NULL DEFAULT 'booked' COMMENT 'booked | undone | skipped | unskipped',
  note            VARCHAR(500)  NULL,
  created_by      INT           NULL,
  created_at      DATETIME      NOT NULL,
  undone_by       INT           NULL,
  undone_at       DATETIME      NULL,
  KEY idx_pml_tx (transaction_id),
  KEY idx_pml_status (status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci
  COMMENT='Deposits matched to invoices (CRM + Jobber) — approvals and skips (migration 1260)';

CREATE TABLE IF NOT EXISTS payment_match_snapshot (
  id             INT           NOT NULL PRIMARY KEY,
  computed_at    DATETIME      NOT NULL,
  waiting        INT           NOT NULL DEFAULT 0 COMMENT 'deposits with a proposal (any confidence)',
  waiting_total  DECIMAL(12,2) NOT NULL DEFAULT 0,
  high           INT           NOT NULL DEFAULT 0,
  high_total     DECIMAL(12,2) NOT NULL DEFAULT 0,
  needs_you      INT           NOT NULL DEFAULT 0,
  needs_you_total DECIMAL(12,2) NOT NULL DEFAULT 0
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci
  COMMENT='Penny card: cached payment-match counts (migration 1260)';

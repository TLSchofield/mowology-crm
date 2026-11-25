-- Migration 1239: Jobber invoice import — history, and Jan–Mar 2026 deposits booked against it
-- Date: 2026-10-07
-- Purpose: the business invoiced in Jobber until the CRM cutover (first CRM invoice paid
--   2026-02-25; Jobber billed through March 2026). JobberImportService
--   (/crm/accounting/jobber-import.php) reads a Jobber "Invoices" CSV export and keeps each
--   invoice as HISTORY in jobber_invoices — never in `invoices`, so AccountingService::
--   syncFromInvoices and LedgerSyncService never post income for them.
--   Ledger rules (each step a proposal Tim approves; logged here so a batch can be undone):
--     - Jobber invoice issued in 2026 → its revenue belongs to 2026: DR 1100 AR / CR 4900 (+ CR 2200
--       GST collected), source 'jobber_invoice' (jobber_invoices.id), dated the issue date.
--     - Jobber invoice issued in 2025 → its revenue is in the filed FY2025 numbers and its unpaid
--       part is in the opening receivable (1238): nothing is posted for the invoice itself.
--     - A Jan–Mar 2026 deposit matched to Jobber invoice(s) → DR bank / CR 1100 AR, source
--       'bank_deposit' (accounting_transactions.id — the nightly bank sync never posts it again).
--       The bank line's single-entry income keeps only the part that paid 2026-issued invoices;
--       the part that paid 2025 invoices is FY2025 revenue (filed) → the line becomes a 'transfer'
--       (all of it) or its income amount drops to the 2026 part.
--   Matching is by Jobber PAYMENT (Transaction List export), never by date alone — Jobber kept
--   issuing some invoices into June 2026: e-Transfer by its confirmation # (else amount ± 3 days),
--   cheques by exact sum on the deposit, Jobber Payments (Stripe) payouts by the charges they
--   batch, the processing fee to 6800 Bank charges.
--   Jobber Payments export: grouped by payout (po_…) = one bank credit of charges − fees, exact.
--   Quote deposits (prepayments) are flagged for Tim; booked only if he ticks them, to 2160 Customer Deposits.
--   Only 2026 touches the ledger; 2017–2025 invoices are history only (FY2025 is filed + locked).
--   Tables:
--     1. jobber_invoices       one row per Jobber invoice (jobber_number unique)
--     2. jobber_payments       one row per Jobber payment / deposit (Transaction List + Jobber Payments exports)
--     3. jobber_import_batches one row per CSV import (the column mapping used)
--     4. jobber_ledger_log     every posting / reversal / bank-line change an approval made
-- MySQL 5.7 compatible (no JSON, no generated columns, no window functions); safe to re-run.

CREATE TABLE IF NOT EXISTS jobber_invoices (
  id                INT AUTO_INCREMENT PRIMARY KEY,
  jobber_number     VARCHAR(40)   NOT NULL COMMENT 'Jobber invoice # (without the #)',
  contact_id        INT           NULL,
  company_id        INT           NULL,
  property_id       INT           NULL,
  match_how         VARCHAR(30)   NULL COMMENT 'address | email | phone | name | company | created | none',
  client_name       VARCHAR(200)  NULL,
  client_email      VARCHAR(255)  NULL,
  client_phone      VARCHAR(50)   NULL,
  service_street    VARCHAR(255)  NULL,
  service_city      VARCHAR(100)  NULL,
  service_province  VARCHAR(50)   NULL,
  service_postal    VARCHAR(20)   NULL,
  subject           VARCHAR(255)  NULL,
  job_numbers       VARCHAR(255)  NULL COMMENT 'Jobber job #s as exported ("3564, 3925") — kept for the Jobs import',
  issued_date       DATE          NULL,
  due_date          DATE          NULL,
  status            VARCHAR(20)   NOT NULL DEFAULT 'unknown' COMMENT 'paid | past_due | open | draft | bad_debt | void | unknown',
  status_raw        VARCHAR(60)   NULL,
  subtotal          DECIMAL(12,2) NOT NULL DEFAULT 0 COMMENT 'before GST',
  tax               DECIMAL(12,2) NOT NULL DEFAULT 0 COMMENT 'GST',
  tax_source        VARCHAR(20)   NOT NULL DEFAULT 'computed' COMMENT 'csv | transactions (Paid - Tax) | computed (Total x 5/105)',
  total             DECIMAL(12,2) NOT NULL DEFAULT 0,
  balance           DECIMAL(12,2) NOT NULL DEFAULT 0 COMMENT 'still owing when exported',
  paid_date         DATE          NULL,
  line_items        TEXT          NULL,
  crm_invoice_id    INT           NULL COMMENT 'recreated in the CRM as this invoice (open at cutover)',
  batch_id          VARCHAR(40)   NULL,
  imported_by       INT           NULL,
  imported_at       DATETIME      NULL,
  updated_at        DATETIME      NULL,
  UNIQUE KEY uq_jobber_number (jobber_number),
  KEY idx_ji_contact (contact_id),
  KEY idx_ji_property (property_id),
  KEY idx_ji_company (company_id),
  KEY idx_ji_issued (issued_date)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci
  COMMENT='Jobber invoices kept as history — never posted as CRM invoices (migration 1239)';

CREATE TABLE IF NOT EXISTS jobber_payments (
  id                INT AUTO_INCREMENT PRIMARY KEY,
  payment_key       VARCHAR(64)   NOT NULL COMMENT 'sha1 of the row (+ occurrence) — re-importing the same export is a no-op',
  kind              VARCHAR(10)   NOT NULL DEFAULT 'payment' COMMENT 'payment | deposit (prepayment on an estimate)',
  client_name       VARCHAR(200)  NULL,
  contact_id        INT           NULL,
  payment_date      DATE          NOT NULL,
  amount            DECIMAL(12,2) NOT NULL COMMENT 'money received (positive)',
  amount_ex_tax     DECIMAL(12,2) NULL COMMENT 'Jobber "Paid - Tax $" (positive)',
  tip               DECIMAL(12,2) NOT NULL DEFAULT 0,
  method            VARCHAR(30)   NULL COMMENT 'Cheque | e-Transfer | Jobber Payments | …',
  cheque_no         VARCHAR(40)   NULL,
  stripe_charge_id  VARCHAR(80)   NULL,
  transaction_no    VARCHAR(80)   NULL,
  confirmation_no   VARCHAR(80)   NULL,
  invoice_numbers   VARCHAR(255)  NULL COMMENT 'Jobber invoice #s the payment was applied to ("11830, 11786")',
  quote_number      VARCHAR(40)   NULL COMMENT 'deposit: the Jobber quote (estimate) it was paid against',
  payout_id         VARCHAR(80)   NULL COMMENT 'Jobber Payments: the Stripe payout (po_…) that carried it to the bank',
  fee               DECIMAL(12,2) NOT NULL DEFAULT 0 COMMENT 'Jobber Payments processing fee (to 6800 Bank charges)',
  job_number        VARCHAR(40)   NULL,
  postal_code       VARCHAR(20)   NULL,
  note              VARCHAR(255)  NULL,
  refunded_on       DATE          NULL,
  batch_id          VARCHAR(40)   NULL,
  imported_at       DATETIME      NULL,
  UNIQUE KEY uq_jp_key (payment_key),
  KEY idx_jp_date (payment_date),
  KEY idx_jp_contact (contact_id),
  KEY idx_jp_payout (payout_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci
  COMMENT='Jobber payments / deposits from the Transaction List export (migration 1239)';

CREATE TABLE IF NOT EXISTS jobber_import_batches (
  id               INT AUTO_INCREMENT PRIMARY KEY,
  batch_id         VARCHAR(40)   NOT NULL,
  filename         VARCHAR(255)  NULL,
  row_count        INT           NOT NULL DEFAULT 0,
  inserted         INT           NOT NULL DEFAULT 0,
  updated          INT           NOT NULL DEFAULT 0,
  created_contacts INT           NOT NULL DEFAULT 0,
  mapping          TEXT          NULL COMMENT 'field=header lines, as used',
  created_by       INT           NULL,
  created_at       DATETIME      NOT NULL,
  UNIQUE KEY uq_jib_batch (batch_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci
  COMMENT='Jobber CSV imports (migration 1239)';

CREATE TABLE IF NOT EXISTS jobber_ledger_log (
  id                 INT AUTO_INCREMENT PRIMARY KEY,
  batch_id           VARCHAR(40)   NOT NULL COMMENT 'one approval; the undo key',
  op                 VARCHAR(20)   NOT NULL COMMENT 'revenue | payment | fee | income | carryover | reverse | bank_line',
  jobber_invoice_id  INT           NULL,
  jobber_payment_id  INT           NULL,
  transaction_id     INT           NULL COMMENT 'accounting_transactions.id of the bank deposit',
  entry_id           INT           NULL COMMENT 'the journal entry posted (payment rows of one deposit share it); reverse: the reversal',
  target_entry_id    INT           NULL COMMENT 'reverse: the entry reversed (an earlier bank-balance-check booking of the deposit)',
  amount             DECIMAL(12,2) NOT NULL DEFAULT 0,
  entry_date         DATE          NULL,
  tx_before_type     VARCHAR(20)   NULL COMMENT 'bank_line: the deposit row before the change (undo restores it)',
  tx_before_amount   DECIMAL(12,2) NULL,
  tx_before_gst      DECIMAL(12,2) NULL,
  tx_before_status   VARCHAR(20)   NULL,
  created_by         INT           NULL,
  created_at         DATETIME      NOT NULL,
  undone_at          DATETIME      NULL,
  undone_by          INT           NULL,
  undo_entry_id      INT           NULL,
  KEY idx_jll_batch (batch_id),
  KEY idx_jll_invoice (jobber_invoice_id),
  KEY idx_jll_payment (jobber_payment_id),
  KEY idx_jll_tx (transaction_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci
  COMMENT='Jobber import ledger approvals (migration 1239)';

-- Customer deposits (prepayments on Jobber quotes, held until the job's invoice) — only if missing.
INSERT INTO chart_of_accounts (code, name, type, sub_type, normal_balance, is_system, is_active, display_order, description)
SELECT '2160', 'Customer Deposits', 'liability', 'unearned', 'credit', 0, 1, 216, 'Prepayments received before the job is invoiced (Jobber quote deposits)'
FROM DUAL WHERE NOT EXISTS (SELECT 1 FROM (SELECT id FROM chart_of_accounts WHERE code = '2160') x)
  AND NOT EXISTS (SELECT 1 FROM (SELECT id FROM chart_of_accounts WHERE LOWER(name) LIKE '%customer deposit%') y);

SELECT COUNT(*) AS jobber_invoices FROM jobber_invoices;

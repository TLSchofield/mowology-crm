-- Migration 1233: split a receipt by line + one gate for every expense change
-- Date: 2026-10-07
-- Purpose (Penny backlog items 2 and 5):
--   * expense_line_allocations — a receipt split by line: each allocation carries its own job
--     (job_plans.id), category, "For" tag, stock flag and its own share of the receipt's net,
--     GST and PST (PST only on the taxable lines when the receipt shows PST). An expense with
--     allocation rows is a SPLIT receipt: the ledger posts one debit per allocation, job
--     profitability and trip attribution read the allocations instead of the header job.
--     A separate table (not columns on expense_line_items) because the desktop save replaces
--     every line item (DELETE + INSERT, new ids) — choices kept here survive that, re-matched
--     by line id then by name — and because the money split (net / GST / PST per share) is the
--     books' record, not the receipt's printed lines.
--   * expense_change_log — ExpenseGate's audit: every change to an expense (who, source,
--     fields, before / after) from every surface (desktop, Penny's card, iOS, inbox, bank desk,
--     trip attribution, duplicates).
--   * expense_split_lessons — what Penny learns from the owner's splits (vendor + line →
--     category / stock / job) so the next receipt from that vendor is pre-split the same way.
-- No foreign keys: rows outlive line-item rewrites; the gate removes them with the expense.
-- MySQL 5.7 compatible (no JSON columns or functions, no generated columns); safe to re-run.

CREATE TABLE IF NOT EXISTS expense_line_allocations (
  id                  INT AUTO_INCREMENT PRIMARY KEY,
  expense_id          INT NOT NULL,
  line_item_id        INT NULL COMMENT 'expense_line_items.id when the share is one printed line',
  label               VARCHAR(255) NOT NULL DEFAULT '',
  sort_order          INT NOT NULL DEFAULT 0,
  job_id              INT NULL COMMENT 'job_plans.id; NULL = no job (overhead / stock)',
  accounting_category VARCHAR(50) NULL,
  asset_tag           VARCHAR(20) NULL COMMENT 'truck | equipment (the For tag)',
  is_stock            TINYINT(1) NOT NULL DEFAULT 0 COMMENT '1 = shop stock, no job',
  pst_taxable         TINYINT(1) NULL COMMENT 'owner said 1/0; NULL = worked out from the receipt',
  net_amount          DECIMAL(10,2) NOT NULL DEFAULT 0.00,
  gst_amount          DECIMAL(10,2) NOT NULL DEFAULT 0.00,
  pst_amount          DECIMAL(10,2) NOT NULL DEFAULT 0.00,
  source              VARCHAR(20) NOT NULL DEFAULT 'owner' COMMENT 'owner | penny',
  reason              VARCHAR(255) NULL,
  created_by          INT NULL,
  created_at          DATETIME NOT NULL,
  updated_at          DATETIME NULL,
  KEY idx_ela_expense (expense_id),
  KEY idx_ela_job (job_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci
  COMMENT='A receipt split by line: job / category / stock + tax per share (ExpenseSplitService)';

CREATE TABLE IF NOT EXISTS expense_change_log (
  id            INT AUTO_INCREMENT PRIMARY KEY,
  expense_id    INT NULL,
  action        VARCHAR(20) NOT NULL COMMENT 'create | update | delete | lines | split | approve | reject | forward | cancel',
  source        VARCHAR(40) NOT NULL COMMENT 'desktop_modal | penny_card | ios_save | inbox | bank_desk | trip_attribution | ...',
  actor_user_id INT NULL,
  actor_kind    VARCHAR(20) NOT NULL DEFAULT 'user' COMMENT 'user | penny | system',
  fields        VARCHAR(500) NULL,
  before_text   MEDIUMTEXT NULL COMMENT 'json_encode of the changed fields before',
  after_text    MEDIUMTEXT NULL COMMENT 'json_encode of the changed fields after',
  note          VARCHAR(255) NULL,
  created_at    DATETIME NOT NULL,
  KEY idx_ecl_expense (expense_id, created_at),
  KEY idx_ecl_source (source, created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci
  COMMENT='ExpenseGate audit: every expense change, who / what / before / after / source';

CREATE TABLE IF NOT EXISTS expense_split_lessons (
  id                  INT AUTO_INCREMENT PRIMARY KEY,
  vendor_id           INT NOT NULL DEFAULT 0,
  line_key            VARCHAR(120) NOT NULL,
  accounting_category VARCHAR(50) NULL,
  asset_tag           VARCHAR(20) NULL,
  is_stock            TINYINT(1) NOT NULL DEFAULT 0,
  to_job              TINYINT(1) NOT NULL DEFAULT 0,
  times_seen          INT NOT NULL DEFAULT 1,
  last_expense_id     INT NULL,
  updated_at          DATETIME NOT NULL,
  UNIQUE KEY uq_esl_vendor_line (vendor_id, line_key)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci
  COMMENT='Penny learns how the owner splits a vendor''s lines';

SELECT 'expense_line_allocations' AS t, COUNT(*) AS n FROM expense_line_allocations
UNION ALL SELECT 'expense_change_log', COUNT(*) FROM expense_change_log
UNION ALL SELECT 'expense_split_lessons', COUNT(*) FROM expense_split_lessons;

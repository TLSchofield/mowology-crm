-- Migration 1250: Penny's look-back review of 2026 (backlog item 7)
-- Date: 2026-10-07
-- Purpose: LookbackService re-checks every 2026 receipt, bank / card line and journal entry
--   with everything Penny knows now and stores PROPOSALS only — Tim approves or skips each one
--   on /crm/accounting/lookback.php. Approvals go through ExpenseGate / BankLineMoveService
--   (append-only journal), are logged with what is needed to undo them, and teach Penny.
--     1. lookback_proposals — one row per (family, kind, record). Re-running the scan updates
--                             open rows, never re-proposes a skipped / applied / undone one.
--     2. lookback_rules     — answers that are reused: a vendor's category from Claude (one call
--                             per vendor) or confirmed by Tim in this review.
--     3. lookback_ai_calls  — every Claude call (tokens, $) — the spend cap reads this.
--     4. ops_settings penny_lookback_budget (USD, default 15) — total AI spend for the review.
--     5. Accounts the review files to when missing (by code, never renamed):
--          1300 Due from Shareholder (asset)       — personal purchases (LedgerService::ACC_DUE_FROM_SH;
--                                                    normally already created by migration 1238)
--          1320 Income Tax Instalments Paid (asset)— 2026 corporate tax instalments ($3,690; $875/month)
--          2215 GST/HST Instalments Paid (liability, debit) — 2026 GST instalments ($3,500/month)
--        and the receipt category 'Personal' → 1300 (expense_category_accounts).
--        The 2025 income tax ($10,454) is paid off 2510 Income Tax Payable, which migration 1238
--        (FY2026 opening) created — this migration does not create a tax-payable account.
--        Not used here: 2310 (reserved for payroll source deductions), 2500 / 2510 (1238).
-- Nothing existing is changed. MySQL 5.7 compatible (no JSON type / functions: *_json columns are
-- TEXT holding PHP json_encode output); safe to re-run.

CREATE TABLE IF NOT EXISTS lookback_proposals (
  id            INT AUTO_INCREMENT PRIMARY KEY,
  scan_key      VARCHAR(80)   NOT NULL COMMENT 'family:kind:subject_type:subject_id',
  family        VARCHAR(10)   NOT NULL COMMENT 'receipt | bank | journal',
  kind          VARCHAR(30)   NOT NULL,
  subject_type  VARCHAR(10)   NOT NULL COMMENT 'expense | bank | journal',
  subject_id    INT           NOT NULL,
  txn_date      DATE          NULL,
  title         VARCHAR(255)  NOT NULL,
  before_json   TEXT          NULL COMMENT 'fields as they were when proposed (staleness check + undo)',
  after_json    TEXT          NULL COMMENT 'fields as proposed; empty = information only',
  evidence      TEXT          NULL COMMENT 'json list of reasons',
  confidence    TINYINT       NOT NULL DEFAULT 0,
  source        VARCHAR(10)   NOT NULL DEFAULT 'rules' COMMENT 'rules | learned | ai',
  amount_impact DECIMAL(12,2) NOT NULL DEFAULT 0.00,
  gst_impact    DECIMAL(12,2) NOT NULL DEFAULT 0.00 COMMENT 'change in claimable GST; negative = less ITC',
  signature     VARCHAR(20)   NULL,
  status        VARCHAR(10)   NOT NULL DEFAULT 'open' COMMENT 'open | info | applied | skipped | undone | stale | failed',
  result_note   VARCHAR(500)  NULL,
  undo_json     TEXT          NULL COMMENT 'what applying changed — how to put it back',
  decided_by    INT           NULL,
  decided_at    DATETIME      NULL,
  undone_by     INT           NULL,
  undone_at     DATETIME      NULL,
  created_at    DATETIME      NOT NULL,
  updated_at    DATETIME      NULL,
  UNIQUE KEY uq_lookback_key (scan_key),
  KEY idx_lookback_status (status, family, kind),
  KEY idx_lookback_subject (subject_type, subject_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci
  COMMENT='Penny look-back review: proposals only, Tim approves (LookbackService)';

CREATE TABLE IF NOT EXISTS lookback_rules (
  id            INT AUTO_INCREMENT PRIMARY KEY,
  rule_kind     VARCHAR(10)   NOT NULL COMMENT 'vendor',
  rule_key      VARCHAR(120)  NOT NULL COMMENT 'normalised vendor name',
  category      VARCHAR(50)   NULL,
  confidence    TINYINT       NOT NULL DEFAULT 0,
  reason        VARCHAR(500)  NULL,
  source        VARCHAR(10)   NOT NULL DEFAULT 'ai' COMMENT 'ai | owner',
  confirmations INT           NOT NULL DEFAULT 0,
  rejected      TINYINT(1)    NOT NULL DEFAULT 0 COMMENT '1 = Tim skipped it — not reused',
  created_at    DATETIME      NOT NULL,
  updated_at    DATETIME      NULL,
  UNIQUE KEY uq_lookback_rule (rule_kind, rule_key)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci
  COMMENT='Reusable answers for the look-back (AI once per vendor, or Tim)';

CREATE TABLE IF NOT EXISTS lookback_ai_calls (
  id            INT AUTO_INCREMENT PRIMARY KEY,
  call_kind     VARCHAR(10)   NOT NULL COMMENT 'vendor | payee',
  call_key      VARCHAR(120)  NOT NULL,
  model         VARCHAR(40)   NULL,
  input_tokens  INT           NOT NULL DEFAULT 0,
  output_tokens INT           NOT NULL DEFAULT 0,
  cost_usd      DECIMAL(8,4)  NOT NULL DEFAULT 0.0000,
  error         VARCHAR(255)  NULL,
  created_at    DATETIME      NOT NULL,
  KEY idx_lookback_ai_key (call_kind, call_key)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci
  COMMENT='Look-back Claude calls: tokens + cost (budget: ops_settings penny_lookback_budget)';

INSERT IGNORE INTO ops_settings (setting_key, setting_value, description)
VALUES ('penny_lookback_budget', '15', 'Penny look-back review: total Claude spend allowed, USD');

-- Accounts (inserted only when the code is missing).
INSERT INTO chart_of_accounts (code, name, type, sub_type, normal_balance, is_system, is_active, display_order, description)
SELECT '1300', 'Due from Shareholder', 'asset', 'receivable', 'debit', 0, 1, 30, 'Personal purchases paid by the company; owed by the shareholder'
FROM DUAL WHERE NOT EXISTS (SELECT 1 FROM (SELECT id FROM chart_of_accounts WHERE code = '1300') x);

INSERT INTO chart_of_accounts (code, name, type, sub_type, normal_balance, is_system, is_active, display_order, description)
SELECT '1320', 'Income Tax Instalments Paid', 'asset', 'prepaid', 'debit', 0, 1, 32, 'Corporate income-tax instalments paid to CRA (RC account) — applied against the year''s tax'
FROM DUAL WHERE NOT EXISTS (SELECT 1 FROM (SELECT id FROM chart_of_accounts WHERE code = '1320') x);

INSERT INTO chart_of_accounts (code, name, type, sub_type, normal_balance, is_system, is_active, display_order, description)
SELECT '2215', 'GST/HST Instalments Paid', 'liability', 'tax_itc', 'debit', 0, 1, 103, 'GST instalments paid to CRA (RT account) — reduce GST owing on the return'
FROM DUAL WHERE NOT EXISTS (SELECT 1 FROM (SELECT id FROM chart_of_accounts WHERE code = '2215') x);


-- Receipt category 'Personal' → Due from Shareholder (only when the map table exists — migration 1130).
INSERT IGNORE INTO expense_category_accounts (category, account_id)
SELECT 'personal', c.id FROM chart_of_accounts c WHERE c.code = '1300' ORDER BY c.id LIMIT 1;

SELECT code, name, type FROM chart_of_accounts WHERE code IN ('1300', '1320', '2215', '2510') ORDER BY code;

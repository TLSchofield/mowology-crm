-- Migration 1255: Wave payroll import + the shareholder account (2026-10-07)
-- Purpose: the accountant needs payroll in the books every month. WavePayrollImportService reads
--   Wave's "Wage & Tax Report" (one month at a time), books one journal entry per month and moves
--   the bank lines that paid it (Wave's CRA remittance debits, the employees' e-Transfers) off
--   5100 so wages are counted once. ShareholderAccountService keeps 1300 Due from Shareholder:
--   the shareholder's own transfers beyond his net pay, his repayments, personal charges, and the
--   a future clearing (dividend or bonus) entered from the accountant's figures. The updated
--   FY2025 FS cleared the loan with an $86,100 dividend: at 2025-12-31 the company owes the
--   shareholder $14 (fy_filed_balances 2025 = -14, migration 1242 — read first when present).
-- Accounts (each created only when the code is missing; an existing code is never renamed —
--   the page refuses to book if a code is named for something else). 2510 is taken by
--   Income Tax Payable (migration 1238), so source deductions are 2310 beside 2300 PST:
--     5110 Employer CPP & EI                (expense, beside 5100)
--     2310 Source deductions payable — CRA  (liability)
--     2520 Net pay clearing — Wave          (liability)
--     1300 Due from Shareholder             (asset — LedgerService::ACC_DUE_FROM_SH, seeded by 1066)
--     3400 Dividends Declared               (equity)
-- NOTE 1500 is Property, Plant & Equipment (migration 1067) — the shareholder account is 1300.
-- Needs migration 1236 (journal_entries.source_type is a VARCHAR: 'payroll_run', 'shareholder_clearing').
-- MySQL 5.7 compatible (no JSON, no generated columns, no window functions); safe to re-run.

-- ── Chart of accounts ────────────────────────────────────────────────────────
INSERT INTO chart_of_accounts (code, name, type, sub_type, normal_balance, parent_id, is_system, is_active, display_order, description)
SELECT '5110', 'Employer CPP & EI', 'expense', NULL, 'debit', p.parent_id, 0, 1, 511, 'Employer share of CPP / CPP2 / EI from the Wave payroll report'
FROM (SELECT parent_id FROM chart_of_accounts WHERE code = '5100' ORDER BY id LIMIT 1) p
WHERE NOT EXISTS (SELECT 1 FROM (SELECT id FROM chart_of_accounts WHERE code = '5110') x);

INSERT INTO chart_of_accounts (code, name, type, sub_type, normal_balance, parent_id, is_system, is_active, display_order, description)
SELECT '2310', 'Source deductions payable — CRA', 'liability', 'gov_payable', 'credit', p.parent_id, 0, 1, 231, 'Payroll withholdings + employer CPP/EI owed to CRA; Wave remits it (WAVE PYRL debits)'
FROM (SELECT parent_id FROM chart_of_accounts WHERE code = '2100' ORDER BY id LIMIT 1) p
WHERE NOT EXISTS (SELECT 1 FROM (SELECT id FROM chart_of_accounts WHERE code = '2310') x);

INSERT INTO chart_of_accounts (code, name, type, sub_type, normal_balance, parent_id, is_system, is_active, display_order, description)
SELECT '2520', 'Net pay clearing — Wave', 'liability', NULL, 'credit', p.parent_id, 0, 1, 252, 'Net pay owed to employees until their e-Transfers go out; ~0 each month'
FROM (SELECT parent_id FROM chart_of_accounts WHERE code = '2100' ORDER BY id LIMIT 1) p
WHERE NOT EXISTS (SELECT 1 FROM (SELECT id FROM chart_of_accounts WHERE code = '2520') x);

INSERT INTO chart_of_accounts (code, name, type, sub_type, normal_balance, parent_id, is_system, is_active, display_order, description)
SELECT '1300', 'Due from Shareholder', 'asset', 'shareholder_loan', 'debit', p.parent_id, 1, 1, 130, 'Shareholder loan account'
FROM (SELECT parent_id FROM chart_of_accounts WHERE code = '1100' ORDER BY id LIMIT 1) p
WHERE NOT EXISTS (SELECT 1 FROM (SELECT id FROM chart_of_accounts WHERE code = '1300') x);

INSERT INTO chart_of_accounts (code, name, type, sub_type, normal_balance, parent_id, is_system, is_active, display_order, description)
SELECT '3400', 'Dividends Declared', 'equity', NULL, 'debit', p.parent_id, 0, 1, 340, 'Dividends declared to the shareholder'
FROM (SELECT parent_id FROM chart_of_accounts WHERE code = '3200' ORDER BY id LIMIT 1) p
WHERE NOT EXISTS (SELECT 1 FROM (SELECT id FROM chart_of_accounts WHERE code = '3400') x);

UPDATE chart_of_accounts SET is_active = 1 WHERE code IN ('5110', '2310', '2520', '1300', '3400') AND is_active = 0;

-- ── Payroll runs: one per month (status preview → booked; undo puts it back to preview) ──
CREATE TABLE IF NOT EXISTS payroll_runs (
  id                     INT AUTO_INCREMENT PRIMARY KEY,
  period_month           CHAR(7) NOT NULL COMMENT 'YYYY-MM (by payday)',
  report_from            DATE NULL COMMENT 'the report''s "for all paydays during" range',
  report_to              DATE NULL,
  last_payday            DATE NOT NULL COMMENT 'the journal entry date',
  source_hash            CHAR(64) NOT NULL COMMENT 'sha256 of the report text',
  source_name            VARCHAR(190) NULL,
  is_estimate            TINYINT(1) NOT NULL DEFAULT 0 COMMENT '1 = split out of a multi-month report by paid hours',
  employee_count         INT NOT NULL DEFAULT 0,
  gross_wages            DECIMAL(12,2) NOT NULL DEFAULT 0,
  ee_fed                 DECIMAL(12,2) NOT NULL DEFAULT 0,
  ee_prov                DECIMAL(12,2) NOT NULL DEFAULT 0,
  ee_ei                  DECIMAL(12,2) NOT NULL DEFAULT 0,
  ee_cpp                 DECIMAL(12,2) NOT NULL DEFAULT 0,
  ee_cpp2                DECIMAL(12,2) NOT NULL DEFAULT 0,
  er_ei                  DECIMAL(12,2) NOT NULL DEFAULT 0,
  er_cpp                 DECIMAL(12,2) NOT NULL DEFAULT 0,
  er_cpp2                DECIMAL(12,2) NOT NULL DEFAULT 0,
  withholdings           DECIMAL(12,2) NOT NULL DEFAULT 0,
  employer_cost          DECIMAL(12,2) NOT NULL DEFAULT 0,
  net_pay                DECIMAL(12,2) NOT NULL DEFAULT 0,
  remittance             DECIMAL(12,2) NOT NULL DEFAULT 0,
  sweep_amount           DECIMAL(12,2) NULL COMMENT 'shareholder transfers beyond his net pay (DR 1300 / CR 2520)',
  warnings               TEXT NULL,
  status                 VARCHAR(12) NOT NULL DEFAULT 'preview' COMMENT 'preview | booked',
  journal_entry_id       INT NULL,
  last_reversal_entry_id INT NULL,
  created_by             INT NULL,
  created_at             DATETIME NOT NULL,
  booked_by              INT NULL,
  booked_at              DATETIME NULL,
  undone_by              INT NULL,
  undone_at              DATETIME NULL,
  KEY idx_pr_month (period_month, status),
  KEY idx_pr_hash (source_hash)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci
  COMMENT='Wave payroll by month (WavePayrollImportService, migration 1255)';

CREATE TABLE IF NOT EXISTS payroll_run_employees (
  id             INT AUTO_INCREMENT PRIMARY KEY,
  run_id         INT NOT NULL,
  employee_name  VARCHAR(120) NOT NULL COMMENT 'First Last as on the report — no SIN, no address',
  first_name     VARCHAR(60) NOT NULL,
  last_name      VARCHAR(60) NOT NULL,
  user_id        INT NULL COMMENT 'users.id matched by full name (hours check)',
  gross_wages    DECIMAL(12,2) NOT NULL DEFAULT 0,
  ee_fed         DECIMAL(12,2) NOT NULL DEFAULT 0,
  ee_prov        DECIMAL(12,2) NOT NULL DEFAULT 0,
  ee_ei          DECIMAL(12,2) NOT NULL DEFAULT 0,
  ee_cpp         DECIMAL(12,2) NOT NULL DEFAULT 0,
  ee_cpp2        DECIMAL(12,2) NOT NULL DEFAULT 0,
  er_ei          DECIMAL(12,2) NOT NULL DEFAULT 0,
  er_cpp         DECIMAL(12,2) NOT NULL DEFAULT 0,
  er_cpp2        DECIMAL(12,2) NOT NULL DEFAULT 0,
  net_pay        DECIMAL(12,2) NOT NULL DEFAULT 0,
  hours          DECIMAL(8,2) NOT NULL DEFAULT 0,
  KEY idx_pre_run (run_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

CREATE TABLE IF NOT EXISTS payroll_run_paydays (
  id                 INT AUTO_INCREMENT PRIMARY KEY,
  run_employee_id    INT NOT NULL,
  payday             DATE NOT NULL,
  period_start       DATE NOT NULL,
  period_end         DATE NOT NULL,
  regular_hours      DECIMAL(8,2) NOT NULL DEFAULT 0,
  overtime_hours     DECIMAL(8,2) NOT NULL DEFAULT 0,
  double_time_hours  DECIMAL(8,2) NOT NULL DEFAULT 0,
  vacation_hours     DECIMAL(8,2) NOT NULL DEFAULT 0,
  sick_hours         DECIMAL(8,2) NOT NULL DEFAULT 0,
  KEY idx_prp_emp (run_employee_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- ── Every bank line moved by payroll or the shareholder account (undo log) ──
CREATE TABLE IF NOT EXISTS payroll_bank_moves (
  id              INT AUTO_INCREMENT PRIMARY KEY,
  run_id          INT NULL COMMENT 'payroll_runs.id (payroll month)',
  batch_id        VARCHAR(40) NULL COMMENT 'shareholder batch (repayments / personal)',
  transaction_id  INT NOT NULL COMMENT 'accounting_transactions.id',
  role            VARCHAR(20) NOT NULL COMMENT 'remittance | net_pay | shareholder | fee | repayment | personal',
  employee_name   VARCHAR(120) NULL,
  amount          DECIMAL(12,2) NOT NULL DEFAULT 0,
  old_account_id  INT NULL,
  old_type        VARCHAR(20) NULL,
  new_account_id  INT NOT NULL,
  moved_by        INT NULL,
  moved_at        DATETIME NOT NULL,
  undone_by       INT NULL,
  undone_at       DATETIME NULL,
  KEY idx_pbm_run (run_id),
  KEY idx_pbm_batch (batch_id),
  KEY idx_pbm_tx (transaction_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci
  COMMENT='Bank lines moved by the payroll import / shareholder account (migration 1255)';

-- ── The accountant's clearing of the shareholder account (dividend or bonus) ──
CREATE TABLE IF NOT EXISTS shareholder_clearings (
  id                 INT AUTO_INCREMENT PRIMARY KEY,
  clearing_type      VARCHAR(10) NOT NULL COMMENT 'dividend | bonus',
  entry_date         DATE NOT NULL,
  amount             DECIMAL(12,2) NOT NULL COMMENT 'cleared off 1300',
  withholdings       DECIMAL(12,2) NOT NULL DEFAULT 0 COMMENT 'bonus only: source deductions on it',
  note               VARCHAR(255) NULL,
  journal_entry_id   INT NULL,
  reversal_entry_id  INT NULL,
  created_by         INT NULL,
  created_at         DATETIME NOT NULL,
  undone_by          INT NULL,
  undone_at          DATETIME NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci
  COMMENT='Shareholder account cleared from the accountant''s figures (migration 1255)';

-- The filed balance sheet's 2025-12-31 shareholder balance (shown beside the books' opening).
INSERT IGNORE INTO ops_settings (setting_key, setting_value, description)
VALUES ('shareholder_filed_opening', '-14.00', 'Due from Shareholder per the filed FY2025 balance sheet (2025-12-31); negative = the company owes him');
UPDATE ops_settings SET setting_value = '-14.00' WHERE setting_key = 'shareholder_filed_opening' AND setting_value = '86086.00';

SELECT code, name, type FROM chart_of_accounts WHERE code IN ('1300', '1500', '2310', '2520', '3400', '5100', '5110', '6800') ORDER BY code;

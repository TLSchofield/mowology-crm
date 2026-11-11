-- Migration 1238: FY2026 opening — start 2026 from the accountant's filed numbers
-- Date: 2026-10-07
-- Purpose: FY2025 is filed (Signed FS YE2025, Mowology Lawns & Landscaping Ltd.) and every 2025
--   month is locked (1237). The CRM journal's Dec-31-2025 balances don't match the filed balance
--   sheet (bank drift, Jobber-era income never journaled, the RAM loan on two accounts …).
--   Fy2026OpeningService (/crm/accounting/fy-opening.php) proposes ONE adjusting entry dated
--   2026-01-01 (source_type 'fy_opening', source_id 2025) that moves every balance-sheet account
--   from its CRM balance to the filed figure and closes every revenue / expense account, the
--   difference landing on 3200 Retained Earnings. Nothing is booked until Tim clicks Book; undo
--   is a reversal (append-only journal, 1131).
--   Changes:
--     1. fy_filed_balances — the filed balance-sheet lines, seeded with FY2025 and FY2024.
--     2. Accounts the mapping needs, only where missing: 1250 Income Tax Receivable,
--        1300 Due from Shareholder, 2500 Due to Government Agencies, 2510 Income Tax Payable,
--        2610 Loan Payable — RAM 3500HD, 3900 Opening Balance Equity (1066 / 1210 / 1236 seed
--        most of them on production already).
-- MySQL 5.7 compatible (no JSON, no generated columns, no window functions); safe to re-run.

-- ── 1. Filed balance sheets ──────────────────────────────────────────────────
CREATE TABLE IF NOT EXISTS fy_filed_balances (
  id           INT AUTO_INCREMENT PRIMARY KEY,
  fiscal_year  SMALLINT      NOT NULL COMMENT 'the year the statement closes (2025 = at Dec 31, 2025)',
  line         VARCHAR(40)   NOT NULL COMMENT 'cash | ar | income_tax_receivable | ppe | due_from_shareholder | ap | due_to_government | income_tax_payable | loan | share_capital | retained_earnings',
  label        VARCHAR(120)  NOT NULL,
  side         VARCHAR(10)   NOT NULL COMMENT 'asset | liability | equity',
  amount       DECIMAL(12,2) NOT NULL COMMENT 'on the line''s normal side (assets debit, the rest credit)',
  source       VARCHAR(80)   NOT NULL,
  created_at   TIMESTAMP     DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY uq_fy_line (fiscal_year, line)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci
  COMMENT='Filed balance sheet figures by fiscal year (migration 1238)';

INSERT IGNORE INTO fy_filed_balances (fiscal_year, line, label, side, amount, source) VALUES
  (2025, 'cash',                  'Cash',                             'asset',     11017.00, 'Signed FS YE2025'),
  (2025, 'ar',                    'Accounts receivable',              'asset',     51948.00, 'Signed FS YE2025'),
  (2025, 'income_tax_receivable', 'Income tax receivable',            'asset',         0.00, 'Signed FS YE2025'),
  (2025, 'ppe',                   'Property, plant & equipment (net)','asset',     19035.00, 'Signed FS YE2025'),
  (2025, 'due_from_shareholder',  'Due from shareholder',             'asset',     86086.00, 'Signed FS YE2025'),
  (2025, 'ap',                    'Accounts payable',                 'liability',  5848.00, 'Signed FS YE2025'),
  (2025, 'due_to_government',     'Amount due to government agencies','liability', 16432.00, 'Signed FS YE2025'),
  (2025, 'income_tax_payable',    'Income tax payable',               'liability', 10454.00, 'Signed FS YE2025'),
  (2025, 'loan',                  'Loan payable (RAM 3500HD)',        'liability', 23886.00, 'Signed FS YE2025'),
  (2025, 'share_capital',         'Share capital',                    'equity',        1.00, 'Signed FS YE2025'),
  (2025, 'retained_earnings',     'Retained earnings',                'equity',   111465.00, 'Signed FS YE2025'),
  (2024, 'cash',                  'Cash',                             'asset',      4865.00, 'Signed FS YE2025 (2024 column)'),
  (2024, 'ar',                    'Accounts receivable',              'asset',     37910.00, 'Signed FS YE2025 (2024 column)'),
  (2024, 'income_tax_receivable', 'Income tax receivable',            'asset',       466.00, 'Signed FS YE2025 (2024 column)'),
  (2024, 'ppe',                   'Property, plant & equipment (net)','asset',     26188.00, 'Signed FS YE2025 (2024 column)'),
  (2024, 'due_from_shareholder',  'Due from shareholder',             'asset',     71066.00, 'Signed FS YE2025 (2024 column)'),
  (2024, 'ap',                    'Accounts payable',                 'liability', 10806.00, 'Signed FS YE2025 (2024 column)'),
  (2024, 'due_to_government',     'Amount due to government agencies','liability', 71047.00, 'Signed FS YE2025 (2024 column)'),
  (2024, 'income_tax_payable',    'Income tax payable',               'liability',     0.00, 'Signed FS YE2025 (2024 column)'),
  (2024, 'loan',                  'Loan payable (RAM 3500HD)',        'liability', 32341.00, 'Signed FS YE2025 (2024 column)'),
  (2024, 'share_capital',         'Share capital',                    'equity',        1.00, 'Signed FS YE2025 (2024 column)'),
  (2024, 'retained_earnings',     'Retained earnings',                'equity',    26300.00, 'Signed FS YE2025 (2024 column)');

-- ── 2. Accounts the mapping needs (only where the code is free) ──────────────
INSERT INTO chart_of_accounts (code, name, type, sub_type, normal_balance, is_system, is_active, display_order, description)
SELECT '1250', 'Income Tax Receivable', 'asset', 'receivable', 'debit', 1, 1, 125, 'Corporate income tax refundable'
FROM DUAL WHERE NOT EXISTS (SELECT 1 FROM (SELECT id FROM chart_of_accounts WHERE code = '1250') x);

INSERT INTO chart_of_accounts (code, name, type, sub_type, normal_balance, is_system, is_active, display_order, description)
SELECT '1300', 'Due from Shareholder', 'asset', 'shareholder_loan', 'debit', 1, 1, 130, 'Shareholder loan account (money taken out, not yet repaid or declared)'
FROM DUAL WHERE NOT EXISTS (SELECT 1 FROM (SELECT id FROM chart_of_accounts WHERE code = '1300') x);

INSERT INTO chart_of_accounts (code, name, type, sub_type, normal_balance, is_system, is_active, display_order, description)
SELECT '2500', 'Due to Government Agencies', 'liability', 'gov_payable', 'credit', 1, 1, 250, 'GST, payroll remittances and other amounts owed to CRA / government'
FROM DUAL WHERE NOT EXISTS (SELECT 1 FROM (SELECT id FROM chart_of_accounts WHERE code = '2500') x);

INSERT INTO chart_of_accounts (code, name, type, sub_type, normal_balance, is_system, is_active, display_order, description)
SELECT '2510', 'Income Tax Payable', 'liability', 'tax_payable', 'credit', 1, 1, 251, 'Corporate income tax owed (T2)'
FROM DUAL WHERE NOT EXISTS (SELECT 1 FROM (SELECT id FROM chart_of_accounts WHERE code = '2510') x)
  AND NOT EXISTS (SELECT 1 FROM (SELECT id FROM chart_of_accounts WHERE LOWER(name) LIKE '%income tax payable%') y);

INSERT INTO chart_of_accounts (code, name, type, sub_type, normal_balance, is_system, is_active, display_order, description)
SELECT '2610', 'Loan Payable — RAM 3500HD', 'liability', 'loan', 'credit', 0, 1, 261, 'TD car loan on the Dodge Ram 3500HD. Each payment reduces this balance; the interest part goes to 6810.'
FROM DUAL WHERE NOT EXISTS (SELECT 1 FROM (SELECT id FROM chart_of_accounts WHERE code = '2610') x);

INSERT INTO chart_of_accounts (code, name, type, sub_type, normal_balance, is_system, is_active, display_order)
SELECT '3900', 'Opening Balance Equity', 'equity', 'opening', 'credit', 1, 1, 390
FROM DUAL WHERE NOT EXISTS (SELECT 1 FROM (SELECT id FROM chart_of_accounts WHERE code = '3900') x);

SELECT fiscal_year, line, amount FROM fy_filed_balances WHERE fiscal_year = 2025 ORDER BY id;
SELECT code, name, type FROM chart_of_accounts WHERE code IN ('1250', '1300', '2500', '2510', '2600', '2610', '3100', '3200', '3900') ORDER BY code;

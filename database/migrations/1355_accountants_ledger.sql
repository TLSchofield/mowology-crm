-- Migration 1355: The Accountant's Ledger, step 1 (plan docs/plans/2026-10-13-accountants-ledger.md)
-- Date: 2026-10-13
--
-- 1. An `accountant` login: users.role gains 'accountant'; RBAC role `accountant` with
--    ledger.view + ledger.propose. ledger.post is admin only. Managers get ledger.view.
--    The accountant holds NO clients.*, schedule.*, team.*, hr.*, timer.*, settings.*, billing.*,
--    expenses.* key; AccountantFence (app/Core/Auth/AccountantFence.php) also confines the login
--    to the Ledger pages, whatever a page's own gate says.
-- 2. GIFI: chart_of_accounts gains gifi_code / gifi_status ('suggested' | 'confirmed') /
--    gifi_note / gifi_confirmed_by / gifi_confirmed_at / lead_group. A shared reference table
--    gifi_codes holds the CRA codes (SHARED knowledge: no tenant data in it).
-- 3. journal_entries.entry_kind: system | manual | aje | rje | reversal. Existing rows are set by
--    source_type: manual / opening / fy_opening -> manual, adjusting (a reversal) -> reversal,
--    everything else -> system.
--
-- MySQL 5.7 compatible (no JSON, no generated columns, no ADD COLUMN IF NOT EXISTS); safe to re-run.

-- 1a. users.role gains 'accountant' (same column definition otherwise)
SET @t = (SELECT COLUMN_TYPE FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'users' AND COLUMN_NAME = 'role');
SET @sql = IF(@t LIKE '%accountant%', 'SELECT 1',
  'ALTER TABLE users MODIFY COLUMN role ENUM(''admin'',''manager'',''user'',''staff'',''technician'',''accountant'') DEFAULT ''user''');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- 1b. RBAC role + permission keys
INSERT IGNORE INTO roles (name, description) VALUES
  ('accountant', 'Outside accountant: reads the ledger and proposes entries. No client, crew, schedule, payroll or banking pages.');

INSERT IGNORE INTO permissions (`key`, description) VALUES
  ('ledger.view',    'See the Ledger: trial balance, journal, GIFI mapping, exception report'),
  ('ledger.propose', 'Propose adjusting entries (they post only when an admin approves)'),
  ('ledger.post',    'Post adjusting entries to the books and edit GIFI mappings (admin)');

INSERT IGNORE INTO role_permissions (role_id, permission_id)
  SELECT r.id, p.id FROM roles r JOIN permissions p ON p.`key` IN ('ledger.view', 'ledger.propose', 'ledger.post')
  WHERE r.name = 'admin';
INSERT IGNORE INTO role_permissions (role_id, permission_id)
  SELECT r.id, p.id FROM roles r JOIN permissions p ON p.`key` = 'ledger.view'
  WHERE r.name = 'manager';
INSERT IGNORE INTO role_permissions (role_id, permission_id)
  SELECT r.id, p.id FROM roles r JOIN permissions p ON p.`key` IN ('ledger.view', 'ledger.propose')
  WHERE r.name = 'accountant';

-- 2a. GIFI mapping on each account
SET @c = (SELECT COUNT(*) FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'chart_of_accounts' AND COLUMN_NAME = 'gifi_code');
SET @sql = IF(@c > 0, 'SELECT 1',
  'ALTER TABLE chart_of_accounts
     ADD COLUMN gifi_code CHAR(4) NULL COMMENT ''CRA GIFI code (RC4088) this account reports under'' AFTER code,
     ADD COLUMN gifi_status VARCHAR(12) NULL COMMENT ''suggested (by the system, unconfirmed) | confirmed (by a person)'' AFTER gifi_code,
     ADD COLUMN gifi_note VARCHAR(255) NULL COMMENT ''Why this code, or what to ask the accountant'' AFTER gifi_status,
     ADD COLUMN gifi_confirmed_by INT NULL AFTER gifi_note,
     ADD COLUMN gifi_confirmed_at DATETIME NULL AFTER gifi_confirmed_by,
     ADD COLUMN lead_group VARCHAR(20) NULL COMMENT ''Lead-sheet group (A cash, B receivables, AA payables, 20 revenue ...)'' AFTER gifi_confirmed_at');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- 2b. SHARED reference: the CRA GIFI codes a small service business uses.
-- Source: CRA RC4088(E) Rev. 23, "General Index of Financial Information (GIFI)", canada.ca,
-- read 2026-10-13. Required items per its "Validity check items": 2599, 3499, 3620, 8299, 9368,
-- 3849 (with a retained-earnings breakdown) and 9999. Only codes read from that page are listed.
CREATE TABLE IF NOT EXISTS gifi_codes (
  code CHAR(4) NOT NULL PRIMARY KEY,
  name VARCHAR(120) NOT NULL,
  section VARCHAR(20) NOT NULL COMMENT 'asset | liability | equity | retained | revenue | cost_of_sales | expense | tax | total',
  is_total TINYINT(1) NOT NULL DEFAULT 0,
  sort_order INT NOT NULL DEFAULT 0
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci
  COMMENT='SHARED: CRA GIFI codes (RC4088 Rev. 23); no tenant data';

INSERT IGNORE INTO gifi_codes (code, name, section, is_total, sort_order) VALUES
  ('1001', 'Cash', 'asset', 0, 1001),
  ('1002', 'Deposits in Canadian banks and institutions - Canadian currency', 'asset', 0, 1002),
  ('1060', 'Accounts receivable', 'asset', 0, 1060),
  ('1061', 'Allowance for doubtful accounts', 'asset', 0, 1061),
  ('1062', 'Trade accounts receivable', 'asset', 0, 1062),
  ('1120', 'Inventories', 'asset', 0, 1120),
  ('1180', 'Short-term investments', 'asset', 0, 1180),
  ('1300', 'Due from shareholder(s)/director(s)', 'asset', 0, 1300),
  ('1301', 'Due from individual shareholder(s)', 'asset', 0, 1301),
  ('1480', 'Other current assets', 'asset', 0, 1480),
  ('1483', 'Taxes recoverable/refundable', 'asset', 0, 1483),
  ('1484', 'Prepaid expenses', 'asset', 0, 1484),
  ('1599', 'Total current assets', 'total', 1, 1599),
  ('1740', 'Machinery, equipment, furniture, and fixtures', 'asset', 0, 1740),
  ('1741', 'Accumulated amortization of machinery, equipment, furniture, and fixtures', 'asset', 0, 1741),
  ('1742', 'Motor vehicles', 'asset', 0, 1742),
  ('1743', 'Accumulated amortization of motor vehicles', 'asset', 0, 1743),
  ('1774', 'Computer equipment/software', 'asset', 0, 1774),
  ('1775', 'Accumulated amortization of computer equipment/software', 'asset', 0, 1775),
  ('2008', 'Total tangible capital assets', 'total', 1, 2008),
  ('2009', 'Total accumulated amortization of tangible capital assets', 'total', 1, 2009),
  ('2180', 'Due from shareholder(s)/director(s) (long term)', 'asset', 0, 2180),
  ('2420', 'Other long-term assets', 'asset', 0, 2420),
  ('2599', 'Total assets', 'total', 1, 2599),
  ('2600', 'Bank overdraft', 'liability', 0, 2600),
  ('2620', 'Amounts payable and accrued liabilities', 'liability', 0, 2620),
  ('2621', 'Trade payables', 'liability', 0, 2621),
  ('2680', 'Taxes payable', 'liability', 0, 2680),
  ('2700', 'Short-term debt', 'liability', 0, 2700),
  ('2707', 'Credit card loans', 'liability', 0, 2707),
  ('2780', 'Due to shareholder(s)/director(s)', 'liability', 0, 2780),
  ('2781', 'Due to individual shareholder(s)', 'liability', 0, 2781),
  ('2920', 'Current portion of long-term liability', 'liability', 0, 2920),
  ('2960', 'Other current liabilities', 'liability', 0, 2960),
  ('2961', 'Deposits received', 'liability', 0, 2961),
  ('3139', 'Total current liabilities', 'total', 1, 3139),
  ('3140', 'Long-term debt', 'liability', 0, 3140),
  ('3260', 'Due to shareholder(s)/director(s) (long term)', 'liability', 0, 3260),
  ('3450', 'Total long-term liabilities', 'total', 1, 3450),
  ('3499', 'Total liabilities', 'total', 1, 3499),
  ('3500', 'Common shares', 'equity', 0, 3500),
  ('3541', 'Contributed surplus', 'equity', 0, 3541),
  ('3600', 'Retained earnings/deficit', 'retained', 0, 3600),
  ('3620', 'Total shareholder equity', 'total', 1, 3620),
  ('3640', 'Total liabilities and shareholder equity', 'total', 1, 3640),
  ('3660', 'Retained earnings/deficit - Start', 'retained', 0, 3660),
  ('3680', 'Net income/loss', 'retained', 0, 3680),
  ('3700', 'Dividends declared', 'retained', 0, 3700),
  ('3740', 'Other items affecting retained earnings', 'retained', 0, 3740),
  ('3849', 'Retained earnings/deficit - End', 'total', 1, 3849),
  ('8000', 'Trade sales of goods and services', 'revenue', 0, 8000),
  ('8089', 'Total sales of goods and services', 'total', 1, 8089),
  ('8090', 'Investment revenue', 'revenue', 0, 8090),
  ('8230', 'Other revenue', 'revenue', 0, 8230),
  ('8299', 'Total revenue', 'total', 1, 8299),
  ('8300', 'Opening inventory', 'cost_of_sales', 0, 8300),
  ('8320', 'Purchases/cost of materials', 'cost_of_sales', 0, 8320),
  ('8340', 'Direct wages', 'cost_of_sales', 0, 8340),
  ('8360', 'Trades and sub-contracts', 'cost_of_sales', 0, 8360),
  ('8450', 'Other direct costs', 'cost_of_sales', 0, 8450),
  ('8500', 'Closing inventory', 'cost_of_sales', 0, 8500),
  ('8518', 'Cost of sales', 'total', 1, 8518),
  ('8519', 'Gross profit/loss', 'total', 1, 8519),
  ('8520', 'Advertising and promotion', 'expense', 0, 8520),
  ('8521', 'Advertising', 'expense', 0, 8521),
  ('8523', 'Meals and entertainment', 'expense', 0, 8523),
  ('8590', 'Bad debt expense', 'expense', 0, 8590),
  ('8620', 'Employee benefits', 'expense', 0, 8620),
  ('8622', 'Employer''s portion of employee benefits', 'expense', 0, 8622),
  ('8670', 'Amortization of tangible assets', 'expense', 0, 8670),
  ('8690', 'Insurance', 'expense', 0, 8690),
  ('8710', 'Interest and bank charges', 'expense', 0, 8710),
  ('8711', 'Interest on short-term debt', 'expense', 0, 8711),
  ('8714', 'Interest on long-term debt', 'expense', 0, 8714),
  ('8715', 'Bank charges', 'expense', 0, 8715),
  ('8716', 'Credit card charges', 'expense', 0, 8716),
  ('8760', 'Business taxes, licences, and memberships', 'expense', 0, 8760),
  ('8810', 'Office expenses', 'expense', 0, 8810),
  ('8811', 'Office stationery and supplies', 'expense', 0, 8811),
  ('8860', 'Professional fees', 'expense', 0, 8860),
  ('8862', 'Accounting fees', 'expense', 0, 8862),
  ('8863', 'Consulting fees', 'expense', 0, 8863),
  ('8910', 'Rental', 'expense', 0, 8910),
  ('8914', 'Equipment rental', 'expense', 0, 8914),
  ('8960', 'Repairs and maintenance', 'expense', 0, 8960),
  ('8962', 'Repairs and maintenance - Vehicles', 'expense', 0, 8962),
  ('8964', 'Repairs and maintenance - Machinery and equipment', 'expense', 0, 8964),
  ('9060', 'Salaries and wages', 'expense', 0, 9060),
  ('9110', 'Sub-contracts', 'expense', 0, 9110),
  ('9130', 'Supplies', 'expense', 0, 9130),
  ('9131', 'Small tools', 'expense', 0, 9131),
  ('9150', 'Computer-related expenses', 'expense', 0, 9150),
  ('9180', 'Property taxes', 'expense', 0, 9180),
  ('9200', 'Travel expenses', 'expense', 0, 9200),
  ('9220', 'Utilities', 'expense', 0, 9220),
  ('9224', 'Fuel costs', 'expense', 0, 9224),
  ('9225', 'Telephone and telecommunications', 'expense', 0, 9225),
  ('9270', 'Other expenses', 'expense', 0, 9270),
  ('9275', 'Delivery, freight and express', 'expense', 0, 9275),
  ('9281', 'Vehicle expenses', 'expense', 0, 9281),
  ('9284', 'General and administrative expenses', 'expense', 0, 9284),
  ('9367', 'Total operating expenses', 'total', 1, 9367),
  ('9368', 'Total expenses', 'total', 1, 9368),
  ('9369', 'Net non-farming income', 'total', 1, 9369),
  ('9970', 'Net income/loss before taxes and extraordinary items', 'total', 1, 9970),
  ('9990', 'Current income taxes', 'tax', 0, 9990),
  ('9995', 'Future (deferred) income tax provision', 'tax', 0, 9995),
  ('9999', 'Net income/loss after taxes and extraordinary items', 'total', 1, 9999);

-- 3. Entry kind on every journal entry
SET @c = (SELECT COUNT(*) FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'journal_entries' AND COLUMN_NAME = 'entry_kind');
SET @sql = IF(@c > 0, 'SELECT 1',
  'ALTER TABLE journal_entries
     ADD COLUMN entry_kind VARCHAR(12) NOT NULL DEFAULT ''system'' COMMENT ''system | manual | aje | rje | reversal'' AFTER source_id');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

UPDATE journal_entries SET entry_kind = 'manual'
  WHERE entry_kind = 'system' AND source_type IN ('manual', 'opening', 'fy_opening');
UPDATE journal_entries SET entry_kind = 'reversal'
  WHERE entry_kind = 'system' AND source_type = 'adjusting';

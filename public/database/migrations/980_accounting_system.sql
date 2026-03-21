-- ============================================================
-- Migration 980: Accounting System — Phase 1
-- Chart of Accounts, Unified Transaction Ledger,
-- Auto-Categorization Rules, Tax Rates, Fiscal Periods
-- ============================================================

-- ── 1. Chart of Accounts ─────────────────────────────────────────────────────
-- Hierarchical account structure for a Canadian landscaping business.
-- type: asset | liability | equity | revenue | expense
-- normal_balance: debit | credit (determines how amounts affect the account)
-- is_system: 1 = cannot be deleted, only deactivated

CREATE TABLE IF NOT EXISTS chart_of_accounts (
    id             INT AUTO_INCREMENT PRIMARY KEY,
    code           VARCHAR(20)   NOT NULL,
    name           VARCHAR(120)  NOT NULL,
    type           ENUM('asset','liability','equity','revenue','expense') NOT NULL,
    sub_type       VARCHAR(60)   NULL,
    normal_balance ENUM('debit','credit') NOT NULL DEFAULT 'debit',
    parent_id      INT           NULL,
    is_system      TINYINT(1)    NOT NULL DEFAULT 0,
    is_active      TINYINT(1)    NOT NULL DEFAULT 1,
    description    TEXT          NULL,
    display_order  INT           NOT NULL DEFAULT 0,
    -- Maps legacy accounting_category strings from expenses table
    expense_category_alias VARCHAR(100) NULL,
    created_at     TIMESTAMP     DEFAULT CURRENT_TIMESTAMP,
    updated_at     TIMESTAMP     DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_type (type),
    INDEX idx_parent (parent_id),
    INDEX idx_code (code),
    INDEX idx_alias (expense_category_alias),
    FOREIGN KEY (parent_id) REFERENCES chart_of_accounts(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;


-- ── 2. Unified Transaction Ledger ─────────────────────────────────────────────
-- Every dollar in or out flows through this table.
-- Synced from invoices (income) and expenses (expense) automatically.
-- Manual entries and bank imports also land here.

CREATE TABLE IF NOT EXISTS accounting_transactions (
    id               INT AUTO_INCREMENT PRIMARY KEY,
    transaction_date DATE         NOT NULL,
    type             ENUM('income','expense','transfer','journal') NOT NULL,
    account_id       INT          NOT NULL,          -- chart_of_accounts.id
    amount           DECIMAL(10,2) NOT NULL,          -- always positive; type indicates direction
    gst_amount       DECIMAL(10,2) NOT NULL DEFAULT 0.00,
    pst_amount       DECIMAL(10,2) NOT NULL DEFAULT 0.00,
    description      TEXT         NULL,

    -- Source record linkage (for sync idempotency)
    reference_type   ENUM('invoice','expense','bank_import','manual','journal') NOT NULL DEFAULT 'manual',
    reference_id     INT          NULL,               -- invoice.id or expense.id

    -- Operational context (drives job/client/crew profitability reports)
    job_id           INT          NULL,
    contact_id       INT          NULL,
    vendor_id        INT          NULL,
    assigned_user_id INT          NULL,               -- crew member for labour cost allocation

    -- Workflow state
    status           ENUM('pending','cleared','reconciled') NOT NULL DEFAULT 'cleared',
    needs_review     TINYINT(1)   NOT NULL DEFAULT 0,  -- flag for accountant review
    is_auto_categorized TINYINT(1) NOT NULL DEFAULT 0,
    rule_id          INT          NULL,               -- transaction_rules.id that matched

    notes            TEXT         NULL,
    created_by       INT          NULL,
    created_at       TIMESTAMP    DEFAULT CURRENT_TIMESTAMP,
    updated_at       TIMESTAMP    DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,

    INDEX idx_date       (transaction_date),
    INDEX idx_type       (type),
    INDEX idx_account    (account_id),
    INDEX idx_ref        (reference_type, reference_id),
    INDEX idx_job        (job_id),
    INDEX idx_contact    (contact_id),
    INDEX idx_vendor     (vendor_id),
    INDEX idx_status     (status),
    INDEX idx_review     (needs_review),

    -- Prevent duplicate sync of the same source record
    UNIQUE KEY uq_source  (reference_type, reference_id),

    FOREIGN KEY (account_id) REFERENCES chart_of_accounts(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;


-- ── 3. Auto-Categorization Rules ──────────────────────────────────────────────
-- Rules are evaluated in priority order (lower number = higher priority).
-- When a rule matches, the transaction is assigned the rule's account_id.

CREATE TABLE IF NOT EXISTS transaction_rules (
    id                  INT AUTO_INCREMENT PRIMARY KEY,
    name                VARCHAR(120) NOT NULL,
    priority            INT          NOT NULL DEFAULT 10,
    applies_to          ENUM('income','expense','both') NOT NULL DEFAULT 'expense',

    -- Matching criteria
    condition_field     ENUM('description','vendor_name','amount_gt','amount_lt','merchant_keyword') NOT NULL,
    condition_operator  ENUM('contains','equals','starts_with','ends_with') NOT NULL DEFAULT 'contains',
    condition_value     VARCHAR(255) NOT NULL,

    -- Assignment
    account_id          INT          NOT NULL,
    transaction_type    ENUM('income','expense') NOT NULL DEFAULT 'expense',

    is_active           TINYINT(1)  NOT NULL DEFAULT 1,
    match_count         INT         NOT NULL DEFAULT 0,   -- lifetime matches (stats)
    last_matched_at     TIMESTAMP   NULL,
    created_by          INT         NULL,
    created_at          TIMESTAMP   DEFAULT CURRENT_TIMESTAMP,
    updated_at          TIMESTAMP   DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,

    INDEX idx_priority  (priority),
    INDEX idx_active    (is_active),
    FOREIGN KEY (account_id) REFERENCES chart_of_accounts(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;


-- ── 4. Tax Rates ──────────────────────────────────────────────────────────────
-- Configurable GST/PST/HST rates per province.

CREATE TABLE IF NOT EXISTS tax_rates (
    id          INT AUTO_INCREMENT PRIMARY KEY,
    name        VARCHAR(60)   NOT NULL,              -- e.g. "GST", "BC PST"
    code        VARCHAR(10)   NOT NULL,              -- e.g. "GST", "PST", "HST"
    rate        DECIMAL(6,4)  NOT NULL,              -- e.g. 0.0500 = 5%
    province    VARCHAR(2)    NOT NULL DEFAULT 'BC',
    applies_to  ENUM('all','income','expense') NOT NULL DEFAULT 'all',
    is_active   TINYINT(1)   NOT NULL DEFAULT 1,
    created_at  TIMESTAMP    DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_code (code),
    INDEX idx_active (is_active)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;


-- ── 5. Accounting Periods ─────────────────────────────────────────────────────
-- Track monthly periods for period-end close workflow.

CREATE TABLE IF NOT EXISTS accounting_periods (
    id          INT AUTO_INCREMENT PRIMARY KEY,
    year        SMALLINT      NOT NULL,
    month       TINYINT       NOT NULL,              -- 1-12
    label       VARCHAR(20)   NOT NULL,              -- e.g. "March 2026"
    status      ENUM('open','closed','locked') NOT NULL DEFAULT 'open',
    closed_at   TIMESTAMP     NULL,
    closed_by   INT           NULL,
    notes       TEXT          NULL,
    created_at  TIMESTAMP     DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uq_period (year, month)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;


-- ════════════════════════════════════════════════════════════════════════════
-- SEED DATA
-- ════════════════════════════════════════════════════════════════════════════

-- ── Tax Rates (Canada — BC) ───────────────────────────────────────────────────
INSERT IGNORE INTO tax_rates (name, code, rate, province, applies_to) VALUES
    ('Goods & Services Tax',    'GST', 0.0500, 'BC', 'all'),
    ('BC Provincial Sales Tax', 'PST', 0.0700, 'BC', 'expense');


-- ── Chart of Accounts — ASSETS ────────────────────────────────────────────────
INSERT IGNORE INTO chart_of_accounts (code, name, type, sub_type, normal_balance, parent_id, is_system, display_order, description) VALUES
('1000', 'Assets',                    'asset', 'header',          'debit', NULL, 1, 10, 'All business assets');

SET @asset_id = LAST_INSERT_ID();

INSERT IGNORE INTO chart_of_accounts (code, name, type, sub_type, normal_balance, parent_id, is_system, display_order, description) VALUES
('1010', 'Chequing Account',          'asset', 'bank',            'debit', @asset_id, 1, 11, 'Primary business chequing'),
('1020', 'Savings Account',           'asset', 'bank',            'debit', @asset_id, 0, 12, 'Business savings'),
('1100', 'Accounts Receivable',       'asset', 'receivable',      'debit', @asset_id, 1, 20, 'Invoiced but unpaid amounts'),
('1200', 'Prepaid Expenses',          'asset', 'prepaid',         'debit', @asset_id, 0, 30, 'Insurance, subscriptions paid in advance'),
('1500', 'Equipment & Tools',         'asset', 'fixed',           'debit', @asset_id, 0, 40, 'Capitalized equipment value');


-- ── Chart of Accounts — LIABILITIES ──────────────────────────────────────────
INSERT IGNORE INTO chart_of_accounts (code, name, type, sub_type, normal_balance, parent_id, is_system, display_order, description) VALUES
('2000', 'Liabilities',               'liability', 'header',      'credit', NULL, 1, 100, 'All business liabilities');

SET @liab_id = LAST_INSERT_ID();

INSERT IGNORE INTO chart_of_accounts (code, name, type, sub_type, normal_balance, parent_id, is_system, display_order, description) VALUES
('2100', 'Accounts Payable',          'liability', 'payable',     'credit', @liab_id, 0, 101, 'Amounts owed to vendors'),
('2200', 'GST/HST Collected',         'liability', 'tax_payable', 'credit', @liab_id, 1, 102, 'GST collected from customers — remit to CRA'),
('2210', 'GST/HST Input Tax Credits', 'liability', 'tax_itc',     'debit',  @liab_id, 1, 103, 'GST paid on purchases — claim as ITC from CRA'),
('2300', 'PST Payable',               'liability', 'tax_payable', 'credit', @liab_id, 0, 104, 'BC PST collected'),
('2400', 'Credit Card Payable',       'liability', 'credit_card', 'credit', @liab_id, 0, 105, 'Business credit card balance');


-- ── Chart of Accounts — EQUITY ────────────────────────────────────────────────
INSERT IGNORE INTO chart_of_accounts (code, name, type, sub_type, normal_balance, parent_id, is_system, display_order, description) VALUES
('3000', 'Equity',                    'equity', 'header',         'credit', NULL, 1, 200, 'Owner equity'),
('3100', 'Owner''s Capital',          'equity', 'capital',        'credit', NULL, 0, 201, 'Owner contributions'),
('3200', 'Retained Earnings',         'equity', 'retained',       'credit', NULL, 1, 202, 'Accumulated profits'),
('3300', 'Owner''s Draw',             'equity', 'draw',           'debit',  NULL, 0, 203, 'Owner withdrawals');


-- ── Chart of Accounts — REVENUE ───────────────────────────────────────────────
INSERT IGNORE INTO chart_of_accounts (code, name, type, sub_type, normal_balance, parent_id, is_system, display_order, description) VALUES
('4000', 'Revenue',                   'revenue', 'header',        'credit', NULL, 1, 300, 'All service revenue');

SET @rev_id = LAST_INSERT_ID();

INSERT IGNORE INTO chart_of_accounts (code, name, type, sub_type, normal_balance, parent_id, is_system, display_order, expense_category_alias, description) VALUES
('4100', 'Lawn Care Services',        'revenue', 'service',       'credit', @rev_id, 0, 301, 'lawn_care',      'Mowing, edging, general lawn maintenance'),
('4200', 'Hedge & Shrub Trimming',    'revenue', 'service',       'credit', @rev_id, 0, 302, 'hedge_trimming', 'Hedge and shrub trimming services'),
('4300', 'Snow Removal',              'revenue', 'service',       'credit', @rev_id, 0, 303, 'snow_removal',   'Winter snow and ice removal'),
('4400', 'Landscape Design',          'revenue', 'service',       'credit', @rev_id, 0, 304, 'landscape_design','Design and installation projects'),
('4500', 'Irrigation Services',       'revenue', 'service',       'credit', @rev_id, 0, 305, 'irrigation',     'Sprinkler installation and maintenance'),
('4600', 'Fertilization & Treatment', 'revenue', 'service',       'credit', @rev_id, 0, 306, 'fertilization',  'Lawn treatments and fertilization'),
('4700', 'Cleanups & Seasonal',       'revenue', 'service',       'credit', @rev_id, 0, 307, 'cleanup',        'Spring and fall cleanup services'),
('4900', 'Other Services',            'revenue', 'service',       'credit', @rev_id, 1, 399, 'other',          'Miscellaneous service revenue');


-- ── Chart of Accounts — COST OF GOODS SOLD ───────────────────────────────────
INSERT IGNORE INTO chart_of_accounts (code, name, type, sub_type, normal_balance, parent_id, is_system, display_order, description) VALUES
('5000', 'Cost of Services',          'expense', 'cogs_header',   'debit', NULL, 1, 400, 'Direct costs to deliver services');

SET @cogs_id = LAST_INSERT_ID();

INSERT IGNORE INTO chart_of_accounts (code, name, type, sub_type, normal_balance, parent_id, is_system, display_order, expense_category_alias, description) VALUES
('5100', 'Labour — Crew Wages',       'expense', 'labour',        'debit', @cogs_id, 1, 401, 'labour',          'Crew wages and payroll costs'),
('5200', 'Materials & Supplies',      'expense', 'materials',     'debit', @cogs_id, 0, 402, 'materials',       'Plants, soil, mulch, tools, hardware'),
('5300', 'Equipment Rental',          'expense', 'equipment',     'debit', @cogs_id, 0, 403, 'equipment_rental','Rented equipment for specific jobs'),
('5400', 'Subcontractors',            'expense', 'subcontractor', 'debit', @cogs_id, 0, 404, 'subcontractors',  'Payments to subcontracted workers');


-- ── Chart of Accounts — OPERATING EXPENSES ───────────────────────────────────
INSERT IGNORE INTO chart_of_accounts (code, name, type, sub_type, normal_balance, parent_id, is_system, display_order, description) VALUES
('6000', 'Operating Expenses',        'expense', 'opex_header',   'debit', NULL, 1, 500, 'Overhead and operating costs');

SET @opex_id = LAST_INSERT_ID();

INSERT IGNORE INTO chart_of_accounts (code, name, type, sub_type, normal_balance, parent_id, is_system, display_order, expense_category_alias, description) VALUES
('6100', 'Fuel & Vehicle',            'expense', 'vehicle',       'debit', @opex_id, 0, 501, 'fuel',             'Fuel purchases and vehicle expenses'),
('6110', 'Vehicle Insurance',         'expense', 'vehicle',       'debit', @opex_id, 0, 502, 'vehicle_insurance','Commercial vehicle insurance'),
('6120', 'Vehicle Maintenance',       'expense', 'vehicle',       'debit', @opex_id, 0, 503, 'vehicle_maintenance','Oil changes, tires, repairs'),
('6200', 'Equipment Maintenance',     'expense', 'equipment',     'debit', @opex_id, 0, 504, 'equipment_maintenance','Mower blades, trimmer line, small engine repair'),
('6300', 'Business Insurance',        'expense', 'insurance',     'debit', @opex_id, 0, 505, 'insurance',        'Liability and commercial insurance'),
('6400', 'Marketing & Advertising',   'expense', 'marketing',     'debit', @opex_id, 0, 506, 'marketing',        'Google Ads, Facebook, flyers, signage'),
('6500', 'Office & Administration',   'expense', 'admin',         'debit', @opex_id, 0, 507, 'office',           'Software, printing, office supplies'),
('6600', 'Professional Services',     'expense', 'professional',  'debit', @opex_id, 0, 508, 'professional_services', 'Accountant, legal fees'),
('6700', 'Utilities & Phone',         'expense', 'utilities',     'debit', @opex_id, 0, 509, 'utilities',        'Phone, internet, workspace utilities'),
('6800', 'Bank Charges & Fees',       'expense', 'banking',       'debit', @opex_id, 0, 510, 'bank_fees',        'Monthly fees, NSF charges, merchant fees'),
('6900', 'Miscellaneous Expenses',    'expense', 'misc',          'debit', @opex_id, 1, 599, 'other_expense',    'Uncategorized or one-off expenses');


-- ── Seed Auto-Categorization Rules ────────────────────────────────────────────
-- Fuel vendors → 6100 Fuel & Vehicle
INSERT IGNORE INTO transaction_rules (name, priority, applies_to, condition_field, condition_operator, condition_value, account_id, transaction_type, is_active)
SELECT 'Shell Gas Station', 1, 'expense', 'vendor_name', 'contains', 'shell', id, 'expense', 1
FROM chart_of_accounts WHERE code = '6100' LIMIT 1;

INSERT IGNORE INTO transaction_rules (name, priority, applies_to, condition_field, condition_operator, condition_value, account_id, transaction_type, is_active)
SELECT 'Esso / Imperial Oil', 2, 'expense', 'vendor_name', 'contains', 'esso', id, 'expense', 1
FROM chart_of_accounts WHERE code = '6100' LIMIT 1;

INSERT IGNORE INTO transaction_rules (name, priority, applies_to, condition_field, condition_operator, condition_value, account_id, transaction_type, is_active)
SELECT 'Petro-Canada', 3, 'expense', 'vendor_name', 'contains', 'petro', id, 'expense', 1
FROM chart_of_accounts WHERE code = '6100' LIMIT 1;

INSERT IGNORE INTO transaction_rules (name, priority, applies_to, condition_field, condition_operator, condition_value, account_id, transaction_type, is_active)
SELECT 'Chevron', 4, 'expense', 'vendor_name', 'contains', 'chevron', id, 'expense', 1
FROM chart_of_accounts WHERE code = '6100' LIMIT 1;

-- Materials vendors → 5200 Materials & Supplies
INSERT IGNORE INTO transaction_rules (name, priority, applies_to, condition_field, condition_operator, condition_value, account_id, transaction_type, is_active)
SELECT 'Home Depot', 5, 'expense', 'vendor_name', 'contains', 'home depot', id, 'expense', 1
FROM chart_of_accounts WHERE code = '5200' LIMIT 1;

INSERT IGNORE INTO transaction_rules (name, priority, applies_to, condition_field, condition_operator, condition_value, account_id, transaction_type, is_active)
SELECT 'Canadian Tire', 6, 'expense', 'vendor_name', 'contains', 'canadian tire', id, 'expense', 1
FROM chart_of_accounts WHERE code = '5200' LIMIT 1;

INSERT IGNORE INTO transaction_rules (name, priority, applies_to, condition_field, condition_operator, condition_value, account_id, transaction_type, is_active)
SELECT 'Rona', 7, 'expense', 'vendor_name', 'contains', 'rona', id, 'expense', 1
FROM chart_of_accounts WHERE code = '5200' LIMIT 1;

INSERT IGNORE INTO transaction_rules (name, priority, applies_to, condition_field, condition_operator, condition_value, account_id, transaction_type, is_active)
SELECT 'Lowes / Lowe''s', 8, 'expense', 'vendor_name', 'contains', 'lowe', id, 'expense', 1
FROM chart_of_accounts WHERE code = '5200' LIMIT 1;

-- Marketing → 6400 Marketing & Advertising
INSERT IGNORE INTO transaction_rules (name, priority, applies_to, condition_field, condition_operator, condition_value, account_id, transaction_type, is_active)
SELECT 'Google Ads', 10, 'expense', 'vendor_name', 'contains', 'google', id, 'expense', 1
FROM chart_of_accounts WHERE code = '6400' LIMIT 1;

INSERT IGNORE INTO transaction_rules (name, priority, applies_to, condition_field, condition_operator, condition_value, account_id, transaction_type, is_active)
SELECT 'Meta / Facebook Ads', 11, 'expense', 'vendor_name', 'contains', 'facebook', id, 'expense', 1
FROM chart_of_accounts WHERE code = '6400' LIMIT 1;

INSERT IGNORE INTO transaction_rules (name, priority, applies_to, condition_field, condition_operator, condition_value, account_id, transaction_type, is_active)
SELECT 'Meta Ads', 12, 'expense', 'vendor_name', 'contains', 'meta', id, 'expense', 1
FROM chart_of_accounts WHERE code = '6400' LIMIT 1;

-- Software / Office → 6500 Office & Administration
INSERT IGNORE INTO transaction_rules (name, priority, applies_to, condition_field, condition_operator, condition_value, account_id, transaction_type, is_active)
SELECT 'Intuit / QuickBooks', 15, 'expense', 'vendor_name', 'contains', 'intuit', id, 'expense', 1
FROM chart_of_accounts WHERE code = '6500' LIMIT 1;

INSERT IGNORE INTO transaction_rules (name, priority, applies_to, condition_field, condition_operator, condition_value, account_id, transaction_type, is_active)
SELECT 'Microsoft', 16, 'expense', 'vendor_name', 'contains', 'microsoft', id, 'expense', 1
FROM chart_of_accounts WHERE code = '6500' LIMIT 1;

INSERT IGNORE INTO transaction_rules (name, priority, applies_to, condition_field, condition_operator, condition_value, account_id, transaction_type, is_active)
SELECT 'Adobe', 17, 'expense', 'vendor_name', 'contains', 'adobe', id, 'expense', 1
FROM chart_of_accounts WHERE code = '6500' LIMIT 1;

-- Default income rule → 4900 Other Services
INSERT IGNORE INTO transaction_rules (name, priority, applies_to, condition_field, condition_operator, condition_value, account_id, transaction_type, is_active)
SELECT 'Default: All Invoice Income', 99, 'income', 'description', 'contains', '', id, 'income', 1
FROM chart_of_accounts WHERE code = '4900' LIMIT 1;

<?php
/**
 * Migration Runner — 980_accounting_system
 * Run once to create the accounting system tables + seed CoA and rules.
 *
 * Access: /crm/api/run-migration-980.php
 * Requires: admin login
 */
declare(strict_types=1);

if (!defined('APP_ROOT')) {
    $__dir = __DIR__;
    for ($__i = 0; $__i < 6; $__i++) {
        $__dir = dirname($__dir);
        if (is_file($__dir . '/app/Core/paths.php')) { require_once $__dir . '/app/Core/paths.php'; break; }
    }
}

require_once PUBLIC_ROOT . '/loginAuth/auth.php';
requireLogin();
$user = getCurrentUser();
if (($user['role'] ?? '') !== 'admin') {
    http_response_code(403);
    echo '<h1>403 — Admin only</h1>';
    exit;
}

$db = getDB();

$sql = <<<'SQL'
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
    expense_category_alias VARCHAR(100) NULL,
    created_at     TIMESTAMP     DEFAULT CURRENT_TIMESTAMP,
    updated_at     TIMESTAMP     DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_type (type),
    INDEX idx_parent (parent_id),
    INDEX idx_code (code),
    INDEX idx_alias (expense_category_alias),
    FOREIGN KEY (parent_id) REFERENCES chart_of_accounts(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
SQL;

$statements980 = [];

// Table: chart_of_accounts
$statements980[] = "CREATE TABLE IF NOT EXISTS chart_of_accounts (
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
    expense_category_alias VARCHAR(100) NULL,
    created_at     TIMESTAMP     DEFAULT CURRENT_TIMESTAMP,
    updated_at     TIMESTAMP     DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_type (type),
    INDEX idx_parent (parent_id),
    INDEX idx_code (code),
    INDEX idx_alias (expense_category_alias),
    FOREIGN KEY (parent_id) REFERENCES chart_of_accounts(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci";

// Table: accounting_transactions
$statements980[] = "CREATE TABLE IF NOT EXISTS accounting_transactions (
    id               INT AUTO_INCREMENT PRIMARY KEY,
    transaction_date DATE         NOT NULL,
    type             ENUM('income','expense','transfer','journal') NOT NULL,
    account_id       INT          NOT NULL,
    amount           DECIMAL(10,2) NOT NULL,
    gst_amount       DECIMAL(10,2) NOT NULL DEFAULT 0.00,
    pst_amount       DECIMAL(10,2) NOT NULL DEFAULT 0.00,
    description      TEXT         NULL,
    reference_type   ENUM('invoice','expense','bank_import','manual','journal') NOT NULL DEFAULT 'manual',
    reference_id     INT          NULL,
    job_id           INT          NULL,
    contact_id       INT          NULL,
    vendor_id        INT          NULL,
    assigned_user_id INT          NULL,
    status           ENUM('pending','cleared','reconciled') NOT NULL DEFAULT 'cleared',
    needs_review     TINYINT(1)   NOT NULL DEFAULT 0,
    is_auto_categorized TINYINT(1) NOT NULL DEFAULT 0,
    rule_id          INT          NULL,
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
    UNIQUE KEY uq_source  (reference_type, reference_id),
    FOREIGN KEY (account_id) REFERENCES chart_of_accounts(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci";

// Table: transaction_rules
$statements980[] = "CREATE TABLE IF NOT EXISTS transaction_rules (
    id                  INT AUTO_INCREMENT PRIMARY KEY,
    name                VARCHAR(120) NOT NULL,
    priority            INT          NOT NULL DEFAULT 10,
    applies_to          ENUM('income','expense','both') NOT NULL DEFAULT 'expense',
    condition_field     ENUM('description','vendor_name','amount_gt','amount_lt','merchant_keyword') NOT NULL,
    condition_operator  ENUM('contains','equals','starts_with','ends_with') NOT NULL DEFAULT 'contains',
    condition_value     VARCHAR(255) NOT NULL,
    account_id          INT          NOT NULL,
    transaction_type    ENUM('income','expense') NOT NULL DEFAULT 'expense',
    is_active           TINYINT(1)  NOT NULL DEFAULT 1,
    match_count         INT         NOT NULL DEFAULT 0,
    last_matched_at     TIMESTAMP   NULL,
    created_by          INT         NULL,
    created_at          TIMESTAMP   DEFAULT CURRENT_TIMESTAMP,
    updated_at          TIMESTAMP   DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_priority  (priority),
    INDEX idx_active    (is_active),
    FOREIGN KEY (account_id) REFERENCES chart_of_accounts(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci";

// Table: tax_rates
$statements980[] = "CREATE TABLE IF NOT EXISTS tax_rates (
    id          INT AUTO_INCREMENT PRIMARY KEY,
    name        VARCHAR(60)   NOT NULL,
    code        VARCHAR(10)   NOT NULL,
    rate        DECIMAL(6,4)  NOT NULL,
    province    VARCHAR(2)    NOT NULL DEFAULT 'BC',
    applies_to  ENUM('all','income','expense') NOT NULL DEFAULT 'all',
    is_active   TINYINT(1)   NOT NULL DEFAULT 1,
    created_at  TIMESTAMP    DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_code (code),
    INDEX idx_active (is_active)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci";

// Table: accounting_periods
$statements980[] = "CREATE TABLE IF NOT EXISTS accounting_periods (
    id          INT AUTO_INCREMENT PRIMARY KEY,
    year        SMALLINT      NOT NULL,
    month       TINYINT       NOT NULL,
    label       VARCHAR(20)   NOT NULL,
    status      ENUM('open','closed','locked') NOT NULL DEFAULT 'open',
    closed_at   TIMESTAMP     NULL,
    closed_by   INT           NULL,
    notes       TEXT          NULL,
    created_at  TIMESTAMP     DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uq_period (year, month)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci";

// Seed tax rates
$statements980[] = "INSERT IGNORE INTO tax_rates (name, code, rate, province, applies_to) VALUES
    ('Goods & Services Tax',    'GST', 0.0500, 'BC', 'all'),
    ('BC Provincial Sales Tax', 'PST', 0.0700, 'BC', 'expense')";

// Seed Chart of Accounts — Assets header
$statements980[] = "INSERT IGNORE INTO chart_of_accounts (code, name, type, sub_type, normal_balance, parent_id, is_system, display_order, description) VALUES
('1000', 'Assets', 'asset', 'header', 'debit', NULL, 1, 10, 'All business assets')";

$statements980[] = "INSERT IGNORE INTO chart_of_accounts (code, name, type, sub_type, normal_balance, parent_id, is_system, display_order, description)
SELECT '1010', 'Chequing Account', 'asset', 'bank', 'debit', id, 1, 11, 'Primary business chequing' FROM chart_of_accounts WHERE code='1000' LIMIT 1";
$statements980[] = "INSERT IGNORE INTO chart_of_accounts (code, name, type, sub_type, normal_balance, parent_id, is_system, display_order, description)
SELECT '1020', 'Savings Account', 'asset', 'bank', 'debit', id, 0, 12, 'Business savings' FROM chart_of_accounts WHERE code='1000' LIMIT 1";
$statements980[] = "INSERT IGNORE INTO chart_of_accounts (code, name, type, sub_type, normal_balance, parent_id, is_system, display_order, description)
SELECT '1100', 'Accounts Receivable', 'asset', 'receivable', 'debit', id, 1, 20, 'Invoiced but unpaid amounts' FROM chart_of_accounts WHERE code='1000' LIMIT 1";
$statements980[] = "INSERT IGNORE INTO chart_of_accounts (code, name, type, sub_type, normal_balance, parent_id, is_system, display_order, description)
SELECT '1200', 'Prepaid Expenses', 'asset', 'prepaid', 'debit', id, 0, 30, 'Insurance, subscriptions paid in advance' FROM chart_of_accounts WHERE code='1000' LIMIT 1";
$statements980[] = "INSERT IGNORE INTO chart_of_accounts (code, name, type, sub_type, normal_balance, parent_id, is_system, display_order, description)
SELECT '1500', 'Equipment & Tools', 'asset', 'fixed', 'debit', id, 0, 40, 'Capitalized equipment value' FROM chart_of_accounts WHERE code='1000' LIMIT 1";

// Liabilities
$statements980[] = "INSERT IGNORE INTO chart_of_accounts (code, name, type, sub_type, normal_balance, parent_id, is_system, display_order, description) VALUES
('2000', 'Liabilities', 'liability', 'header', 'credit', NULL, 1, 100, 'All business liabilities')";

$statements980[] = "INSERT IGNORE INTO chart_of_accounts (code, name, type, sub_type, normal_balance, parent_id, is_system, display_order, description)
SELECT '2100', 'Accounts Payable', 'liability', 'payable', 'credit', id, 0, 101, 'Amounts owed to vendors' FROM chart_of_accounts WHERE code='2000' LIMIT 1";
$statements980[] = "INSERT IGNORE INTO chart_of_accounts (code, name, type, sub_type, normal_balance, parent_id, is_system, display_order, description)
SELECT '2200', 'GST/HST Collected', 'liability', 'tax_payable', 'credit', id, 1, 102, 'GST collected from customers' FROM chart_of_accounts WHERE code='2000' LIMIT 1";
$statements980[] = "INSERT IGNORE INTO chart_of_accounts (code, name, type, sub_type, normal_balance, parent_id, is_system, display_order, description)
SELECT '2210', 'GST/HST Input Tax Credits', 'liability', 'tax_itc', 'debit', id, 1, 103, 'GST paid on purchases' FROM chart_of_accounts WHERE code='2000' LIMIT 1";
$statements980[] = "INSERT IGNORE INTO chart_of_accounts (code, name, type, sub_type, normal_balance, parent_id, is_system, display_order, description)
SELECT '2300', 'PST Payable', 'liability', 'tax_payable', 'credit', id, 0, 104, 'BC PST collected' FROM chart_of_accounts WHERE code='2000' LIMIT 1";
$statements980[] = "INSERT IGNORE INTO chart_of_accounts (code, name, type, sub_type, normal_balance, parent_id, is_system, display_order, description)
SELECT '2400', 'Credit Card Payable', 'liability', 'credit_card', 'credit', id, 0, 105, 'Business credit card balance' FROM chart_of_accounts WHERE code='2000' LIMIT 1";

// Equity
$statements980[] = "INSERT IGNORE INTO chart_of_accounts (code, name, type, sub_type, normal_balance, parent_id, is_system, display_order, description) VALUES
('3000', 'Equity', 'equity', 'header', 'credit', NULL, 1, 200, 'Owner equity'),
('3100', 'Owner''s Capital', 'equity', 'capital', 'credit', NULL, 0, 201, 'Owner contributions'),
('3200', 'Retained Earnings', 'equity', 'retained', 'credit', NULL, 1, 202, 'Accumulated profits'),
('3300', 'Owner''s Draw', 'equity', 'draw', 'debit', NULL, 0, 203, 'Owner withdrawals')";

// Revenue
$statements980[] = "INSERT IGNORE INTO chart_of_accounts (code, name, type, sub_type, normal_balance, parent_id, is_system, display_order, description) VALUES
('4000', 'Revenue', 'revenue', 'header', 'credit', NULL, 1, 300, 'All service revenue')";

$statements980[] = "INSERT IGNORE INTO chart_of_accounts (code, name, type, sub_type, normal_balance, parent_id, is_system, display_order, expense_category_alias, description)
SELECT '4100', 'Lawn Care Services', 'revenue', 'service', 'credit', id, 0, 301, 'lawn_care', 'Mowing, edging, general lawn maintenance' FROM chart_of_accounts WHERE code='4000' LIMIT 1";
$statements980[] = "INSERT IGNORE INTO chart_of_accounts (code, name, type, sub_type, normal_balance, parent_id, is_system, display_order, expense_category_alias, description)
SELECT '4200', 'Hedge & Shrub Trimming', 'revenue', 'service', 'credit', id, 0, 302, 'hedge_trimming', 'Hedge and shrub trimming services' FROM chart_of_accounts WHERE code='4000' LIMIT 1";
$statements980[] = "INSERT IGNORE INTO chart_of_accounts (code, name, type, sub_type, normal_balance, parent_id, is_system, display_order, expense_category_alias, description)
SELECT '4300', 'Snow Removal', 'revenue', 'service', 'credit', id, 0, 303, 'snow_removal', 'Winter snow and ice removal' FROM chart_of_accounts WHERE code='4000' LIMIT 1";
$statements980[] = "INSERT IGNORE INTO chart_of_accounts (code, name, type, sub_type, normal_balance, parent_id, is_system, display_order, expense_category_alias, description)
SELECT '4400', 'Landscape Design', 'revenue', 'service', 'credit', id, 0, 304, 'landscape_design', 'Design and installation projects' FROM chart_of_accounts WHERE code='4000' LIMIT 1";
$statements980[] = "INSERT IGNORE INTO chart_of_accounts (code, name, type, sub_type, normal_balance, parent_id, is_system, display_order, expense_category_alias, description)
SELECT '4500', 'Irrigation Services', 'revenue', 'service', 'credit', id, 0, 305, 'irrigation', 'Sprinkler installation and maintenance' FROM chart_of_accounts WHERE code='4000' LIMIT 1";
$statements980[] = "INSERT IGNORE INTO chart_of_accounts (code, name, type, sub_type, normal_balance, parent_id, is_system, display_order, expense_category_alias, description)
SELECT '4600', 'Fertilization & Treatment', 'revenue', 'service', 'credit', id, 0, 306, 'fertilization', 'Lawn treatments and fertilization' FROM chart_of_accounts WHERE code='4000' LIMIT 1";
$statements980[] = "INSERT IGNORE INTO chart_of_accounts (code, name, type, sub_type, normal_balance, parent_id, is_system, display_order, expense_category_alias, description)
SELECT '4700', 'Cleanups & Seasonal', 'revenue', 'service', 'credit', id, 0, 307, 'cleanup', 'Spring and fall cleanup services' FROM chart_of_accounts WHERE code='4000' LIMIT 1";
$statements980[] = "INSERT IGNORE INTO chart_of_accounts (code, name, type, sub_type, normal_balance, parent_id, is_system, display_order, expense_category_alias, description)
SELECT '4900', 'Other Services', 'revenue', 'service', 'credit', id, 1, 399, 'other', 'Miscellaneous service revenue' FROM chart_of_accounts WHERE code='4000' LIMIT 1";

// COGS
$statements980[] = "INSERT IGNORE INTO chart_of_accounts (code, name, type, sub_type, normal_balance, parent_id, is_system, display_order, description) VALUES
('5000', 'Cost of Services', 'expense', 'cogs_header', 'debit', NULL, 1, 400, 'Direct costs to deliver services')";

$statements980[] = "INSERT IGNORE INTO chart_of_accounts (code, name, type, sub_type, normal_balance, parent_id, is_system, display_order, expense_category_alias, description)
SELECT '5100', 'Labour — Crew Wages', 'expense', 'labour', 'debit', id, 1, 401, 'labour', 'Crew wages and payroll costs' FROM chart_of_accounts WHERE code='5000' LIMIT 1";
$statements980[] = "INSERT IGNORE INTO chart_of_accounts (code, name, type, sub_type, normal_balance, parent_id, is_system, display_order, expense_category_alias, description)
SELECT '5200', 'Materials & Supplies', 'expense', 'materials', 'debit', id, 0, 402, 'materials', 'Plants, soil, mulch, tools, hardware' FROM chart_of_accounts WHERE code='5000' LIMIT 1";
$statements980[] = "INSERT IGNORE INTO chart_of_accounts (code, name, type, sub_type, normal_balance, parent_id, is_system, display_order, expense_category_alias, description)
SELECT '5300', 'Equipment Rental', 'expense', 'equipment', 'debit', id, 0, 403, 'equipment_rental', 'Rented equipment for specific jobs' FROM chart_of_accounts WHERE code='5000' LIMIT 1";
$statements980[] = "INSERT IGNORE INTO chart_of_accounts (code, name, type, sub_type, normal_balance, parent_id, is_system, display_order, expense_category_alias, description)
SELECT '5400', 'Subcontractors', 'expense', 'subcontractor', 'debit', id, 0, 404, 'subcontractors', 'Payments to subcontracted workers' FROM chart_of_accounts WHERE code='5000' LIMIT 1";

// OpEx
$statements980[] = "INSERT IGNORE INTO chart_of_accounts (code, name, type, sub_type, normal_balance, parent_id, is_system, display_order, description) VALUES
('6000', 'Operating Expenses', 'expense', 'opex_header', 'debit', NULL, 1, 500, 'Overhead and operating costs')";

$statements980[] = "INSERT IGNORE INTO chart_of_accounts (code, name, type, sub_type, normal_balance, parent_id, is_system, display_order, expense_category_alias, description)
SELECT '6100', 'Fuel & Vehicle', 'expense', 'vehicle', 'debit', id, 0, 501, 'fuel', 'Fuel purchases and vehicle expenses' FROM chart_of_accounts WHERE code='6000' LIMIT 1";
$statements980[] = "INSERT IGNORE INTO chart_of_accounts (code, name, type, sub_type, normal_balance, parent_id, is_system, display_order, expense_category_alias, description)
SELECT '6110', 'Vehicle Insurance', 'expense', 'vehicle', 'debit', id, 0, 502, 'vehicle_insurance', 'Commercial vehicle insurance' FROM chart_of_accounts WHERE code='6000' LIMIT 1";
$statements980[] = "INSERT IGNORE INTO chart_of_accounts (code, name, type, sub_type, normal_balance, parent_id, is_system, display_order, expense_category_alias, description)
SELECT '6120', 'Vehicle Maintenance', 'expense', 'vehicle', 'debit', id, 0, 503, 'vehicle_maintenance', 'Oil changes, tires, repairs' FROM chart_of_accounts WHERE code='6000' LIMIT 1";
$statements980[] = "INSERT IGNORE INTO chart_of_accounts (code, name, type, sub_type, normal_balance, parent_id, is_system, display_order, expense_category_alias, description)
SELECT '6200', 'Equipment Maintenance', 'expense', 'equipment', 'debit', id, 0, 504, 'equipment_maintenance', 'Mower blades, trimmer line, small engine repair' FROM chart_of_accounts WHERE code='6000' LIMIT 1";
$statements980[] = "INSERT IGNORE INTO chart_of_accounts (code, name, type, sub_type, normal_balance, parent_id, is_system, display_order, expense_category_alias, description)
SELECT '6300', 'Business Insurance', 'expense', 'insurance', 'debit', id, 0, 505, 'insurance', 'Liability and commercial insurance' FROM chart_of_accounts WHERE code='6000' LIMIT 1";
$statements980[] = "INSERT IGNORE INTO chart_of_accounts (code, name, type, sub_type, normal_balance, parent_id, is_system, display_order, expense_category_alias, description)
SELECT '6400', 'Marketing & Advertising', 'expense', 'marketing', 'debit', id, 0, 506, 'marketing', 'Google Ads, Facebook, flyers, signage' FROM chart_of_accounts WHERE code='6000' LIMIT 1";
$statements980[] = "INSERT IGNORE INTO chart_of_accounts (code, name, type, sub_type, normal_balance, parent_id, is_system, display_order, expense_category_alias, description)
SELECT '6500', 'Office & Administration', 'expense', 'admin', 'debit', id, 0, 507, 'office', 'Software, printing, office supplies' FROM chart_of_accounts WHERE code='6000' LIMIT 1";
$statements980[] = "INSERT IGNORE INTO chart_of_accounts (code, name, type, sub_type, normal_balance, parent_id, is_system, display_order, expense_category_alias, description)
SELECT '6600', 'Professional Services', 'expense', 'professional', 'debit', id, 0, 508, 'professional_services', 'Accountant, legal fees' FROM chart_of_accounts WHERE code='6000' LIMIT 1";
$statements980[] = "INSERT IGNORE INTO chart_of_accounts (code, name, type, sub_type, normal_balance, parent_id, is_system, display_order, expense_category_alias, description)
SELECT '6700', 'Utilities & Phone', 'expense', 'utilities', 'debit', id, 0, 509, 'utilities', 'Phone, internet, workspace utilities' FROM chart_of_accounts WHERE code='6000' LIMIT 1";
$statements980[] = "INSERT IGNORE INTO chart_of_accounts (code, name, type, sub_type, normal_balance, parent_id, is_system, display_order, expense_category_alias, description)
SELECT '6800', 'Bank Charges & Fees', 'expense', 'banking', 'debit', id, 0, 510, 'bank_fees', 'Monthly fees, NSF charges, merchant fees' FROM chart_of_accounts WHERE code='6000' LIMIT 1";
$statements980[] = "INSERT IGNORE INTO chart_of_accounts (code, name, type, sub_type, normal_balance, parent_id, is_system, display_order, expense_category_alias, description)
SELECT '6900', 'Miscellaneous Expenses', 'expense', 'misc', 'debit', id, 1, 599, 'other_expense', 'Uncategorized or one-off expenses' FROM chart_of_accounts WHERE code='6000' LIMIT 1";

// Rules seed
$rules = [
    ['Shell Gas Station',            1,  'expense', 'vendor_name', 'contains', 'shell',          '6100'],
    ['Esso / Imperial Oil',          2,  'expense', 'vendor_name', 'contains', 'esso',           '6100'],
    ['Petro-Canada',                 3,  'expense', 'vendor_name', 'contains', 'petro',          '6100'],
    ['Chevron',                      4,  'expense', 'vendor_name', 'contains', 'chevron',        '6100'],
    ['Home Depot',                   5,  'expense', 'vendor_name', 'contains', 'home depot',     '5200'],
    ['Canadian Tire',                6,  'expense', 'vendor_name', 'contains', 'canadian tire',  '5200'],
    ['Rona',                         7,  'expense', 'vendor_name', 'contains', 'rona',           '5200'],
    ['Lowes',                        8,  'expense', 'vendor_name', 'contains', 'lowe',           '5200'],
    ['Google Ads',                  10,  'expense', 'vendor_name', 'contains', 'google',         '6400'],
    ['Meta / Facebook Ads',         11,  'expense', 'vendor_name', 'contains', 'facebook',       '6400'],
    ['Meta Ads',                    12,  'expense', 'vendor_name', 'contains', 'meta',           '6400'],
    ['Intuit / QuickBooks',         15,  'expense', 'vendor_name', 'contains', 'intuit',         '6500'],
    ['Microsoft',                   16,  'expense', 'vendor_name', 'contains', 'microsoft',      '6500'],
    ['Adobe',                       17,  'expense', 'vendor_name', 'contains', 'adobe',          '6500'],
    ['Default: All Invoice Income', 99,  'income',  'description', 'contains', '',               '4900'],
];
foreach ($rules as $r) {
    $statements980[] = "INSERT IGNORE INTO transaction_rules (name, priority, applies_to, condition_field, condition_operator, condition_value, account_id, transaction_type, is_active)
SELECT '" . addslashes($r[0]) . "', {$r[1]}, '{$r[2]}', '{$r[3]}', '{$r[4]}', '" . addslashes($r[5]) . "', id, '{$r[2]}', 1
FROM chart_of_accounts WHERE code = '{$r[6]}' LIMIT 1";
}

// ── Run ───────────────────────────────────────────────────────────────────────
header('Content-Type: text/html; charset=utf-8');
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="utf-8">
<title>Migration 980 — Accounting System</title>
<style>
  body { font-family: monospace; padding: 2rem; background: #0d1117; color: #c9d1d9; }
  h1   { color: #2D8659; }
  .ok  { color: #3fb950; }
  .err { color: #f85149; }
  .note{ color: #8b949e; }
</style>
</head>
<body>
<h1>Migration 980 — Mowology Accounting System</h1>
<p class="note">Running as: <?= htmlspecialchars($user['email'] ?? '') ?></p>
<hr>
<?php

$successCount = 0;
$errorCount   = 0;

foreach ($statements980 as $stmt) {
    $firstLine = trim(strtok($stmt, "\n"));
    try {
        $db->exec($stmt);
        $successCount++;
        echo "<p class=\"ok\">✓ " . htmlspecialchars($firstLine) . "</p>\n";
    } catch (PDOException $e) {
        $msg = $e->getMessage();
        if (str_contains($msg, 'already exists') ||
            str_contains($msg, 'Duplicate entry') ||
            str_contains($msg, 'SQLSTATE[42S01]')) {
            echo "<p class=\"note\">↷ " . htmlspecialchars($firstLine) . " (already exists)</p>\n";
            continue;
        }
        $errorCount++;
        echo "<p class=\"err\">✗ " . htmlspecialchars($firstLine) . "<br>" . htmlspecialchars($msg) . "</p>\n";
    }
}
?>
<hr>
<p class="<?= $errorCount > 0 ? 'err' : 'ok' ?>">
    Done — <?= $successCount ?> statements succeeded, <?= $errorCount ?> failed.
</p>
<?php if ($errorCount === 0): ?>
<p class="ok">✓ Chart of Accounts, Transaction Ledger, Rules Engine, Tax Rates — all ready.</p>
<p class="note">Next: run <a href="/crm/api/run-migration-981.php" style="color:#2D8659">Migration 981</a>, then go to the
<a href="/crm/accounting_appstack.php" style="color:#2D8659">Accounting Dashboard</a> and click <strong>Sync Ledger</strong>.</p>
<?php endif; ?>
</body>
</html>

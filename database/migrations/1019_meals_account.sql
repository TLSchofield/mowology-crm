-- Migration 1019: Add Meals & Entertainment account + align expense_category_alias values
-- with the exact strings used in the expenses UI (Title Case / slash-separated).
-- The sync code now does a case-insensitive lookup, but explicit aliases prevent any
-- edge cases and ensure the account names are meaningful in reports.

-- 1. Add 6850 Meals & Entertainment (no matching account existed before)
INSERT IGNORE INTO chart_of_accounts
    (code, name, type, sub_type, normal_balance, is_system, display_order, expense_category_alias, description)
VALUES
    ('6850', 'Meals & Entertainment', 'expense', 'entertainment', 'debit', 0, 511, 'Meals',
     'Team meals, client entertainment, business dining');

-- 2. Update existing aliases to match exact UI category strings
--    (case-insensitive lookup in code handles the mismatch, but keeping aliases consistent)
UPDATE chart_of_accounts SET expense_category_alias = 'Repairs/Maintenance' WHERE code = '6200';
UPDATE chart_of_accounts SET expense_category_alias = 'Vehicle'             WHERE code = '6100';
UPDATE chart_of_accounts SET expense_category_alias = 'Office/Admin'        WHERE code = '6500';

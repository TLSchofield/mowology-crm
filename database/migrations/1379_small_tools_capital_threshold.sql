-- 1379 — the standard capital / small-tools split (owner, 2026-10-19: "always do what is standard and ensure the
-- accountant can track and understand what is booked"). CapitalItemRule.
--   6210 Small Tools & Equipment (expense): Tools/Equipment receipts now post here …
--   … except items of capital_threshold ($500) or more EACH, which post to 1500 Equipment & Tools (CCA Class 8).
INSERT INTO chart_of_accounts (code, name, type, sub_type, normal_balance, parent_id, is_system, is_active, display_order, description)
SELECT '6210', 'Small Tools & Equipment', 'expense', 'equipment', 'debit', p.parent_id, 1, 1, 505,
       'Tools and equipment under the capital threshold each (CCA Class 12); items of $500+ each go to 1500 Equipment'
FROM (SELECT parent_id FROM chart_of_accounts WHERE code = '6200' ORDER BY id LIMIT 1) p
WHERE NOT EXISTS (SELECT 1 FROM (SELECT id FROM chart_of_accounts WHERE code = '6210') x);

UPDATE expense_category_accounts SET account_id = (SELECT id FROM (SELECT id FROM chart_of_accounts WHERE code = '6210' ORDER BY id LIMIT 1) a)
WHERE category = 'tools/equipment';

INSERT INTO ops_settings (setting_key, setting_value, description)
SELECT 'capital_threshold', '500', 'An equipment item costing this much or more EACH (before tax) is a capital asset (1500); less is Small Tools (6210)'
FROM DUAL WHERE NOT EXISTS (SELECT 1 FROM (SELECT setting_key FROM ops_settings WHERE setting_key = 'capital_threshold') x);

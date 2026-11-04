-- 1381 — a receipt filed as "Other" posts to 6900 Miscellaneous Expenses (2026-10-20). Income account 4900
-- "Other Services" carries the SERVICE-type alias 'other' (invoices for other services), and the receipt side
-- read that alias too — 3 receipts were booked as income. The code now ignores revenue accounts for receipts;
-- this row makes the receipt category's account explicit (the owner's map wins everywhere).
INSERT INTO expense_category_accounts (category, account_id, set_by)
SELECT 'other', a.id, NULL FROM (SELECT id FROM chart_of_accounts WHERE code = '6900' ORDER BY id LIMIT 1) a
WHERE NOT EXISTS (SELECT 1 FROM (SELECT category FROM expense_category_accounts WHERE category = 'other') x);

-- Migration 1365: PPE & Safety Supplies gets its own account (Tim, 2026-10-15)
-- Receipts in the 'Safety' category (earmuffs, gloves, vests…) booked to 6000 Operating Expenses — a heading
-- account that should hold no postings. They now book to 6250 PPE & Safety Supplies (GIFI suggested 9130
-- Supplies by GifiRules; the accountant confirms). Existing 2026 Safety receipts are re-posted separately
-- (append-only: reverse + post), previewed first.
-- MySQL 5.7 compatible; safe to re-run.

INSERT INTO chart_of_accounts (code, name, type, sub_type, normal_balance, parent_id, is_system, is_active, display_order, description, expense_category_alias)
SELECT '6250', 'PPE & Safety Supplies', 'expense', 'safety', 'debit', p.id, 0, 1, 504,
       'Safety gear and personal protective equipment the crew uses (hearing / eye protection, gloves, vests)', 'safety'
FROM (SELECT id FROM chart_of_accounts WHERE code = '6000' ORDER BY id LIMIT 1) p
WHERE NOT EXISTS (SELECT 1 FROM (SELECT id FROM chart_of_accounts WHERE code = '6250') x);

UPDATE expense_category_accounts m
JOIN (SELECT id FROM chart_of_accounts WHERE code = '6250' ORDER BY id LIMIT 1) c
SET m.account_id = c.id
WHERE m.category = 'safety';

INSERT INTO expense_category_accounts (category, account_id)
SELECT 'safety', c.id FROM (SELECT id FROM chart_of_accounts WHERE code = '6250' ORDER BY id LIMIT 1) c
WHERE NOT EXISTS (SELECT 1 FROM (SELECT category FROM expense_category_accounts WHERE category = 'safety') x);

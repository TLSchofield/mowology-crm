-- 1210: the Dodge Ram (RAM 3500HD) is a CAR LOAN (Tim, 2026-10-07). Payments reduce what is owed; only the
-- interest is an expense. Adds the two accounts the books were missing. 6130 "Vehicle Loan — RAM 3500HD"
-- (an expense account) stays for now — its 37 lines move to 2610 after Tim reviews the list, then it retires.
-- Idempotent: inserts only if the code is free.

INSERT INTO chart_of_accounts (code, name, type, sub_type, normal_balance, parent_id, description, display_order)
SELECT '2610', 'Loan Payable — RAM 3500HD', 'liability', NULL, 'credit', NULL,
       'TD car loan on the Dodge Ram 3500HD. Each payment reduces this balance; the interest part goes to 6810.', 261
  FROM DUAL WHERE NOT EXISTS (SELECT 1 FROM chart_of_accounts WHERE code = '2610');

INSERT INTO chart_of_accounts (code, name, type, sub_type, normal_balance, parent_id, description, display_order)
SELECT '6810', 'Interest Expense', 'expense', NULL, 'debit', NULL,
       'Interest on business loans (e.g. the RAM 3500HD car loan). Principal is not an expense.', 681
  FROM DUAL WHERE NOT EXISTS (SELECT 1 FROM chart_of_accounts WHERE code = '6810');

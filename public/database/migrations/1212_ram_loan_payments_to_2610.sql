-- 1212: move every TD car-loan payment (RAM 3500HD) onto 2610 Loan Payable (Tim, 2026-10-07: "do loan payment movement").
-- Live before: 44 bank lines of $363.08 each, Jan 2025 – Oct 2026 = $15,975.52
--   37 on 6130 "Vehicle Loan — RAM 3500HD" (expense), 5 on 2400 Credit Card Payable, 2 on 6900 Miscellaneous.
-- The whole payment goes to 2610 for now. The interest part (→ 6810) is split later from the TD loan
-- statement or by the accountant at year end. No GST on loan payments.
-- Moves the single-entry row AND its journal debit line (the bank credit line is untouched).
-- Skips any month whose accounting period is locked. Idempotent: re-running finds nothing left to move.

SET @loan := (SELECT id FROM chart_of_accounts WHERE code = '2610' LIMIT 1);
SET @old1 := (SELECT id FROM chart_of_accounts WHERE code = '6130' LIMIT 1);
SET @old2 := (SELECT id FROM chart_of_accounts WHERE code = '2400' LIMIT 1);
SET @old3 := (SELECT id FROM chart_of_accounts WHERE code = '6900' LIMIT 1);

-- 1. Journal debit lines first (needs the rows' current account to find them).
UPDATE journal_lines jl
  JOIN journal_entries je ON je.id = jl.entry_id AND je.source_type = 'bank_import'
  JOIN accounting_transactions t ON t.id = je.source_id
   SET jl.account_id = @loan, jl.gst_amount = 0
 WHERE @loan IS NOT NULL
   AND t.reference_type = 'bank_import'
   AND REPLACE(REPLACE(REPLACE(UPPER(t.description), ' ', ''), '-', ''), '(', '') LIKE '%TDONLINELOANS%'
   AND t.account_id IN (@old1, @old2, @old3)
   AND t.matched_invoice_id IS NULL AND t.matched_expense_id IS NULL
   AND jl.account_id = t.account_id
   AND jl.debit > 0
   AND NOT EXISTS (SELECT 1 FROM accounting_periods p
                    WHERE p.year = YEAR(t.transaction_date) AND p.month = MONTH(t.transaction_date)
                      AND p.status = 'locked');

-- 2. The bank lines themselves: a loan payment is a transfer to a liability, not an expense.
UPDATE accounting_transactions t
   SET t.account_id = @loan, t.type = 'transfer', t.gst_amount = 0, t.pst_amount = 0, t.needs_review = 0,
       t.notes = TRIM(CONCAT(COALESCE(t.notes, ''), ' [1212: TD car loan → 2610]'))
 WHERE @loan IS NOT NULL
   AND t.reference_type = 'bank_import'
   AND REPLACE(REPLACE(REPLACE(UPPER(t.description), ' ', ''), '-', ''), '(', '') LIKE '%TDONLINELOANS%'
   AND t.account_id IN (@old1, @old2, @old3)
   AND t.matched_invoice_id IS NULL AND t.matched_expense_id IS NULL
   AND NOT EXISTS (SELECT 1 FROM accounting_periods p
                    WHERE p.year = YEAR(t.transaction_date) AND p.month = MONTH(t.transaction_date)
                      AND p.status = 'locked');

-- 3. Future TD loan lines: point any categorisation rule for them at 2610 (the learned one said 2400).
UPDATE transaction_rules
   SET account_id = @loan
 WHERE @loan IS NOT NULL
   AND REPLACE(REPLACE(REPLACE(UPPER(condition_value), ' ', ''), '-', ''), '(', '') LIKE '%TDONLINELOAN%';

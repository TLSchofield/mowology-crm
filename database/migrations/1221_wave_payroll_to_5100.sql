-- 1221: Wave payroll (Wave PYRL) payments are labour, not Credit Card Payable (Tim, 2026-10-07). A learned rule
-- built from untouched imports had sent 7 of them (Jul–Sep 2026, $16,368.29) to 2400. Moves the bank rows AND their
-- journal debit lines (recategorise never moves journal lines — see 1212–1214). Row 36678 (2026-06-10 $2,659.27, import
-- session 38) is a duplicate of row 18402 (session 33, already on 5100) and is deliberately NOT moved: Tim decides.
-- Skips locked periods. Idempotent.

SET @lab := (SELECT id FROM chart_of_accounts WHERE code = '5100' LIMIT 1);
SET @cc  := (SELECT id FROM chart_of_accounts WHERE code = '2400' LIMIT 1);

UPDATE journal_lines jl
  JOIN journal_entries je ON je.id = jl.entry_id AND je.source_type = 'bank_import'
  JOIN accounting_transactions t ON t.id = je.source_id
   SET jl.account_id = @lab
 WHERE @lab IS NOT NULL AND @cc IS NOT NULL
   AND t.id IN (43034, 41222, 32649, 32648, 28022, 24431, 24486)
   AND t.account_id = @cc
   AND jl.account_id = @cc AND jl.debit > 0
   AND NOT EXISTS (SELECT 1 FROM accounting_periods p WHERE p.year = YEAR(t.transaction_date)
                     AND p.month = MONTH(t.transaction_date) AND p.status = 'locked');

UPDATE accounting_transactions t
   SET t.account_id = @lab, t.type = 'expense', t.needs_review = 0,
       t.notes = TRIM(CONCAT(COALESCE(t.notes, ''), ' [1221: Wave payroll → 5100]'))
 WHERE @lab IS NOT NULL AND @cc IS NOT NULL
   AND t.id IN (43034, 41222, 32649, 32648, 28022, 24431, 24486)
   AND t.account_id = @cc
   AND NOT EXISTS (SELECT 1 FROM accounting_periods p WHERE p.year = YEAR(t.transaction_date)
                     AND p.month = MONTH(t.transaction_date) AND p.status = 'locked');

-- The learned rule that did it: stop it (Penny relearns from Tim's two confirmations).
UPDATE transaction_rules SET is_active = 0
 WHERE source = 'learned' AND account_id = @cc
   AND REPLACE(UPPER(condition_value), ' ', '') LIKE '%WAVEPYRL%';

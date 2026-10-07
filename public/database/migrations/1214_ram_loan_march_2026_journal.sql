-- 1214: the last two TD car-loan payments on 6130 — March 2026 (the 6th and 20th, $363.08 each, $726.16).
-- They are in the journal but their bank-list rows no longer exist, so 1212/1213 (which match via the bank row)
-- could not find them. Moves exactly those debit lines: account 6130, March 2026, $363.08. Idempotent.

SET @loan := (SELECT id FROM chart_of_accounts WHERE code = '2610' LIMIT 1);
SET @old  := (SELECT id FROM chart_of_accounts WHERE code = '6130' LIMIT 1);

UPDATE journal_lines jl
  JOIN journal_entries je ON je.id = jl.entry_id
   SET jl.account_id = @loan, jl.gst_amount = 0
 WHERE @loan IS NOT NULL AND @old IS NOT NULL
   AND jl.account_id = @old
   AND jl.debit = 363.08
   AND je.entry_date BETWEEN '2026-03-01' AND '2026-03-31'
   AND NOT EXISTS (SELECT 1 FROM accounting_periods p WHERE p.year = 2026 AND p.month = 3 AND p.status = 'locked');

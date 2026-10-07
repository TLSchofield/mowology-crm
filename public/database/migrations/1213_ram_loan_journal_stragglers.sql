-- 1213: follow-up to 1212. Two TD loan lines (2026-09-18, 2026-10-02) had been recategorised 6130 → 6900 on the
-- bank list, but recategorising never touched their journal lines, which still debited 6130. 1212 matched journal
-- lines on the row's CURRENT account, so it missed them ($726.16 left on 6130). Moves any journal debit line of a
-- TD loan bank row that now sits on 2610 but whose line is still on an old account. Idempotent.

SET @loan := (SELECT id FROM chart_of_accounts WHERE code = '2610' LIMIT 1);

UPDATE journal_lines jl
  JOIN journal_entries je ON je.id = jl.entry_id AND je.source_type = 'bank_import'
  JOIN accounting_transactions t ON t.id = je.source_id
   SET jl.account_id = @loan, jl.gst_amount = 0
 WHERE @loan IS NOT NULL
   AND t.account_id = @loan
   AND REPLACE(REPLACE(REPLACE(UPPER(t.description), ' ', ''), '-', ''), '(', '') LIKE '%TDONLINELOANS%'
   AND jl.debit > 0
   AND jl.account_id IN (SELECT id FROM (SELECT id FROM chart_of_accounts WHERE code IN ('6130','2400','6900')) x)
   AND NOT EXISTS (SELECT 1 FROM accounting_periods p
                    WHERE p.year = YEAR(t.transaction_date) AND p.month = MONTH(t.transaction_date)
                      AND p.status = 'locked');

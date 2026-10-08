-- Migration 1242: FY2025 filed figures — Updated FS (Sit Lim CPA, June 24 2026)
-- The updated statements declare an $86,100 dividend (T5 2025) that clears the shareholder loan:
-- Due from shareholder 86,086 → 0, Due to shareholder 14, retained earnings 111,465 → 25,365.
-- The due_from_shareholder line stays on 1300; −14 (asset side) = $14 owed TO the shareholder.
-- After this runs, undo the FY2026 opening (entry #3472) and book it again.
UPDATE fy_filed_balances SET amount = -14.00, label = 'Due from (to) shareholder', source = 'Signed FS YE2025 (Updated)'
 WHERE fiscal_year = 2025 AND line = 'due_from_shareholder';
UPDATE fy_filed_balances SET amount = 25365.00, source = 'Signed FS YE2025 (Updated)'
 WHERE fiscal_year = 2025 AND line = 'retained_earnings';
UPDATE fy_filed_balances SET source = 'Signed FS YE2025 (Updated)' WHERE fiscal_year = 2025;

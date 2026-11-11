-- Migration 1361: two shareholders on the Wave payroll import, each on their own loan account (2026-10-13).
-- See WavePayrollImportService::shareholders() / matchMonth() / sweeps().
--
-- Tim (2026-10-13): e-Transfers to a shareholder are PAY up to their Wave net pay for the month; anything
-- beyond goes to THEIR shareholder loan ("same for me"). Tim Schofield -> 1300 Due from Shareholder,
-- Katrenna Marenych -> 1302 Shareholder loan - Katrenna Marenych (child of 1300, created 2026-10-13).
-- One line per shareholder, "Name=account code" (the name exactly as the Wave report prints it,
-- first then last). The older single 'payroll_shareholder_name' is used only when this is empty.
--
-- MySQL 5.7: plain INSERT, no JSON; safe to re-run (keeps a value already set).

INSERT INTO ops_settings (setting_key, setting_value, description)
SELECT 'payroll_shareholders', 'Tim Schofield=1300\nKatrenna Marenych=1302',
       'Shareholders on the Wave report and their loan accounts (Name=code per line)'
FROM DUAL
WHERE NOT EXISTS (SELECT 1 FROM ops_settings WHERE setting_key = 'payroll_shareholders');

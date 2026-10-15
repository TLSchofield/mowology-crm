-- Migration 1364: holding accounts so chequing moves only with real bank lines
-- Date: 2026-10-14 · Plan: docs/plans/2026-10-14-deposit-matcher.md
-- Purpose: a customer payment no longer posts straight to 1010 Chequing. It waits in a holding account
--   until the bank shows the money:
--     1015 Stripe clearing    — card payments (DR 1015 / CR AR); a payout moves the net to chequing
--                               (DR 1010 / CR 1015) and Stripe's fee comes out of it (DR 6800 / CR 1015).
--                               Its balance = what Stripe still holds for us.
--     1016 Undeposited funds  — cheques, e-Transfers, EFT, cash (DR 1016 / CR AR); the bank deposit
--                               moves it to chequing (DR 1010 / CR 1016). Its balance = money recorded
--                               but not yet seen in the bank (or deposits with no payment recorded).
--   Posting switch: ops_settings ledger_holding_from (a date; empty = off). Entries dated on/after it use
--   the holding accounts (LedgerService::holdingAccountFor, LedgerSyncService::bankRowToEntryArgs).
--   Nothing is reposted by this migration.
-- MySQL 5.7 compatible; safe to re-run.

INSERT INTO chart_of_accounts (code, name, type, sub_type, normal_balance, parent_id, is_system, is_active, display_order, description)
SELECT '1015', 'Stripe clearing', 'asset', 'clearing', 'debit', p.parent_id, 1, 1, 101,
       'Card payments held by Stripe until paid out to chequing (payments in, payouts and fees out); equals the Stripe balance'
FROM (SELECT parent_id FROM chart_of_accounts WHERE code = '1010' ORDER BY id LIMIT 1) p
WHERE NOT EXISTS (SELECT 1 FROM (SELECT id FROM chart_of_accounts WHERE code = '1015') x);

INSERT INTO chart_of_accounts (code, name, type, sub_type, normal_balance, parent_id, is_system, is_active, display_order, description)
SELECT '1016', 'Undeposited funds', 'asset', 'clearing', 'debit', p.parent_id, 1, 1, 102,
       'Customer payments recorded (cheque, e-Transfer, EFT, cash) and not yet in the bank; each deposit clears them'
FROM (SELECT parent_id FROM chart_of_accounts WHERE code = '1010' ORDER BY id LIMIT 1) p
WHERE NOT EXISTS (SELECT 1 FROM (SELECT id FROM chart_of_accounts WHERE code = '1016') x);

INSERT INTO ops_settings (setting_key, setting_value, description)
SELECT 'ledger_holding_from', '', 'Date from which customer payments post to 1015/1016 holding accounts instead of chequing (empty = off)'
FROM DUAL WHERE NOT EXISTS (SELECT 1 FROM (SELECT setting_key FROM ops_settings WHERE setting_key = 'ledger_holding_from') x);

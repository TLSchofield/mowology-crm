-- Migration 1276: pull-forward offers are OFF unless switched on
-- Date: 2026-10-08
-- Purpose: the "booked another day — doing it now?" sheet (mw-pull-forward.js) shipped on
--   2026-10-08 and Android crew devices lost all taps until it was disabled. It is now gated:
--     pull_forward_enabled  = '1' → on for everyone (default '0')
--     pull_forward_user_ids = '12,34' → on ONLY for these users while pull_forward_enabled is '0'
--   (switch it on for one test device first). With both off the page never loads the script's
--   behaviour, makes no request and asks for no location; the endpoint answers offer=null.
--   No row = off (VisitPullForwardService::enabledFor), so this migration only documents the keys.
-- MySQL 5.7 compatible; safe to re-run.

INSERT IGNORE INTO ops_settings (setting_key, setting_value, description) VALUES
  ('pull_forward_enabled',  '0', 'Crew "booked another day - doing it now?" sheet: 1 = on for everyone'),
  ('pull_forward_user_ids', '',  'Crew pull-forward sheet: comma-separated user ids to test with while it is off for everyone');

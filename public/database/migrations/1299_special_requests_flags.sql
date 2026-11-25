-- Migration 1299: Special requests — feature flags (OFF by default)
-- Date: 2026-10-08
-- Purpose:
--   special_requests_enabled  '0' | '1'  — master switch. '0' = every special-request path is inert:
--                             no crew script is printed, the gate never blocks, nothing is proposed,
--                             attached or sent.
--   special_requests_user_ids '' | '12,31' — while set, the CREW side (cards, gate, pushes, SMS)
--                             applies only to these users, for one-device testing. Empty = everyone.
-- MySQL 5.7 compatible; safe to re-run.

INSERT IGNORE INTO ops_settings (setting_key, setting_value, description)
VALUES ('special_requests_enabled', '0', 'Special requests on arrival: master switch (0 = off)');
INSERT IGNORE INTO ops_settings (setting_key, setting_value, description)
VALUES ('special_requests_user_ids', '', 'Special requests: crew user ids to test with (empty = everyone)');

-- Migration 1358: the crew break button (Tim, 2026-10-13). See BreakService.
-- A tapped break is a time_clock_breaks row with source 'manual' and an OPEN break_end until
-- End break / the clock-out. GPS-found breaks (migration 1318) stay as the backup.
-- MySQL 5.7: plain DDL, no IF NOT EXISTS on columns — run once from the migrations runner.
ALTER TABLE time_clock_breaks
  MODIFY break_end DATETIME NULL COMMENT 'NULL = a tapped break still running',
  MODIFY minutes INT NOT NULL DEFAULT 0,
  ADD COLUMN ended_by VARCHAR(20) NULL COMMENT 'tap | clock_out | office — how a tapped break ended' AFTER place,
  ADD COLUMN time_note VARCHAR(255) NULL COMMENT 'OfflineTime note when the tap reached the server late' AFTER ended_by,
  ADD KEY idx_tcb_open (user_id, source, break_end);

INSERT INTO ops_settings (setting_key, setting_value, description) VALUES
  ('break_reminder_minutes', '60', 'Minutes on a tapped break before the phone asks "Still on break?"'),
  ('break_button_enabled', '1', 'Crew Start break / End break button (1 = on, 0 = hidden everywhere)')
ON DUPLICATE KEY UPDATE description = VALUES(description);

-- The BC meal-break flag on Otto's card: on now that crews can tap their breaks.
INSERT INTO ops_settings (setting_key, setting_value, description) VALUES
  ('break_flags_enabled', '1', 'Otto flags shifts over 5 h with no 30-min break (BC). On since the break button, 2026-10-13.')
ON DUPLICATE KEY UPDATE setting_value = '1', description = VALUES(description);

UPDATE ops_settings SET description = 'Breaks are paid (1) or taken off paid time (0). Mowology pays breaks. BC: a meal break must be paid if the worker must stay available.'
WHERE setting_key = 'breaks_paid';

-- 1424: per-client grace + certainty gates for invoice reminders (2026-11-02)
-- Mode switch read by ReminderGate: off | shadow (log what would be held, still send) | on (enforce).
-- Starts in shadow so a week of cron runs shows what the gates would have held before any send changes.
CREATE TABLE IF NOT EXISTS invoice_reminder_holds (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  invoice_id INT UNSIGNED NOT NULL,
  reason VARCHAR(32) NOT NULL,
  detail VARCHAR(255) NOT NULL DEFAULT '',
  mode VARCHAR(8) NOT NULL,
  would_send_type VARCHAR(16) NOT NULL DEFAULT '',
  held_on DATE NOT NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY uq_invoice_day (invoice_id, held_on),
  KEY idx_held_on (held_on)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

INSERT INTO ops_settings (setting_key, setting_value, description)
SELECT 'reminder_grace_mode', 'shadow', 'Invoice reminder grace/certainty gates: off | shadow | on (ReminderGate)'
FROM DUAL WHERE NOT EXISTS (SELECT 1 FROM ops_settings WHERE setting_key = 'reminder_grace_mode');

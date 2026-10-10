-- Migration 1318: breaks found from the crew phone's GPS (owner, 2026-10-10). See BreakService.
-- Mowology pays breaks, so pay never changes; records are for honest job times and the BC rule
-- (no more than 5 hours without a 30-minute meal break). MySQL 5.7: plain DDL.
CREATE TABLE IF NOT EXISTS time_clock_breaks (
  id INT AUTO_INCREMENT PRIMARY KEY,
  entry_id INT NOT NULL COMMENT 'time_clock_entries.id',
  user_id INT NOT NULL,
  break_start DATETIME NOT NULL,
  break_end DATETIME NOT NULL,
  minutes INT NOT NULL,
  source VARCHAR(10) NOT NULL DEFAULT 'gps' COMMENT 'gps | manual',
  place VARCHAR(120) NULL COMMENT 'Where the phone was: a named place or "unnamed stop"',
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  KEY idx_tcb_entry (entry_id),
  KEY idx_tcb_user (user_id, break_start)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

ALTER TABLE time_clock_entries
  ADD COLUMN break_note VARCHAR(255) NULL COMMENT 'BreakService: why this shift breaks the BC meal-break rule (NULL = fine)';

INSERT INTO ops_settings (setting_key, setting_value, description) VALUES
  ('breaks_paid', '1', 'Breaks are paid (1) or deducted from paid time (0). Mowology pays breaks.')
ON DUPLICATE KEY UPDATE description = VALUES(description);

-- Migration 1173: Charlie (Foreman) — decision inbox log and urgent alerts
-- Date: 2026-10-06
-- Purpose:
--   charlie_inbox_actions — what Tim did from the unified inbox (which head's endpoint,
--                           which button, did it work). The inbox owns no logic: it forwards
--                           Tim's click to the head that made the proposal.
--   charlie_alerts        — the urgent interruptions sent (payment failure over the
--                           threshold, same-day weather cancellation), each sent once.
--   ops_settings charlie_urgent_payment_min — the payment-failure threshold ($500, editable).
-- MySQL 5.7 compatible.

CREATE TABLE IF NOT EXISTS charlie_inbox_actions (
  id INT AUTO_INCREMENT PRIMARY KEY,
  proposal_key VARCHAR(120) NOT NULL,
  head VARCHAR(20) NULL,
  action_label VARCHAR(80) NULL,
  batch_key VARCHAR(60) NULL,
  ok TINYINT(1) NOT NULL DEFAULT 0,
  message VARCHAR(255) NULL,
  user_id INT NULL,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  INDEX idx_charlie_inbox_key (proposal_key),
  INDEX idx_charlie_inbox_created (created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

CREATE TABLE IF NOT EXISTS charlie_alerts (
  id INT AUTO_INCREMENT PRIMARY KEY,
  alert_key VARCHAR(120) NOT NULL,
  kind VARCHAR(20) NOT NULL COMMENT 'payment | weather',
  text VARCHAR(500) NOT NULL,
  sent_at DATETIME NULL,
  channel VARCHAR(10) NULL COMMENT 'email',
  error VARCHAR(255) NULL,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY uq_charlie_alert_key (alert_key)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

INSERT INTO ops_settings (setting_key, setting_value, description)
VALUES ('charlie_urgent_payment_min', '500', 'Charlie (Foreman): email Tim at once when a payment fails for at least this many dollars')
ON DUPLICATE KEY UPDATE setting_key = setting_key;

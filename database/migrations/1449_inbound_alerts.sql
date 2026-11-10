-- Migration 1449: one push per client email (2026-11-10)
-- Purpose: a law office's balance request sat unseen because inbound mail never told anyone. InboundAlertService claims each
-- message here with INSERT IGNORE, so office@ and iCloud polls can overlap and a message still alerts once.
-- Runs only when ops_settings.inbox_alerts is on. MySQL 5.7, utf8mb4_general_ci. Safe to run twice.
CREATE TABLE IF NOT EXISTS inbound_alerts (
  message_key VARCHAR(191) NOT NULL PRIMARY KEY,
  alerted_at  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- Migration 1447: the owner's answer to "Who is this?" teaches the inbox (2026-11-10)
-- Purpose: office@ mail from people not on file waits in inbound_unplaced (1446). When the owner says whose it is, or that
-- the sender is not a client, that answer becomes a rule the poll reads: an address marked noise is not asked about again;
-- two different people confirmed at one business domain make the domain a rule for that account. decision is JSON text made
-- by PHP: exactly what the answer created, so Undo can reverse only that.
-- MySQL 5.7, utf8mb4_general_ci. Safe to run twice.
CREATE TABLE IF NOT EXISTS inbound_sender_rules (
  id          INT AUTO_INCREMENT PRIMARY KEY,
  sender_key  VARCHAR(191) NOT NULL COMMENT 'lowercase address, or @domain for a domain rule',
  verdict     VARCHAR(10)  NOT NULL COMMENT 'noise | account',
  client_id   INT NULL,
  contact_id  INT NULL,
  decided_by  INT NULL,
  created_at  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY uq_isr_sender (sender_key)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

SET @has_decision := (SELECT COUNT(*) FROM information_schema.COLUMNS
                      WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'inbound_unplaced' AND COLUMN_NAME = 'decision');
SET @sql := IF(@has_decision = 0,
    'ALTER TABLE inbound_unplaced ADD COLUMN decision TEXT NULL',
    'SELECT 1');
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

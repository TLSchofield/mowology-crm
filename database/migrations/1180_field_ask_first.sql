-- Migration 1180: Field recommendations — "Ask first" or "Send quote"
-- Date: 2026-10-06
-- Purpose: after a crew member photographs work and picks a service, the field app now
--   offers two paths. "Send quote" is the 1114 behaviour. "Ask first" emails the person
--   who decides the work (the property's on-site contact, else the site contact) a short
--   note in Tim's voice with the photos and NO price — "would you like us to do this?" —
--   and only a manager or admin can send it. When they reply, Sam's card offers
--   "Build the quote" for that observation. See docs/crm/field-ask-first-plan.md.
--
--   field_observations.intent/ask_*   which path, who was asked, when, and who sent it.
--                                     New status values (VARCHAR, no enum change):
--                                     ask_draft → asked → ask_yes → quote_created/email_sent.
--   products.field_ask_pitch          the per-service sentence ("we trim and prune…").
--   field_ask_messages                every ask sent: suggested vs final wording. Tim's
--                                     edits, with the customer's details put back as
--                                     {placeholders}, become the next draft for that service.
--
-- Code is guarded (SHOW COLUMNS / SHOW TABLES): deploying before this runs is safe and the
-- field app simply keeps the single "Send quote" path.
-- Idempotent: every ADD COLUMN / index checks information_schema first, so a re-run is a no-op.
-- MySQL 5.7 compatible: no JSON type, no generated columns, no window functions.

-- ── field_observations ──────────────────────────────────────────────────────
SET @c = (SELECT COUNT(1) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'field_observations' AND COLUMN_NAME = 'intent');
SET @sql = IF(@c = 0, 'ALTER TABLE `field_observations` ADD COLUMN `intent` VARCHAR(10) NOT NULL DEFAULT ''quote'' COMMENT ''ask = Ask first (no price) | quote = priced quote''', 'SELECT 1');
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @c = (SELECT COUNT(1) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'field_observations' AND COLUMN_NAME = 'ask_contact_id');
SET @sql = IF(@c = 0, 'ALTER TABLE `field_observations` ADD COLUMN `ask_contact_id` INT NULL COMMENT ''Who the Ask goes to: on-site contact, else the site contact''', 'SELECT 1');
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @c = (SELECT COUNT(1) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'field_observations' AND COLUMN_NAME = 'ask_role');
SET @sql = IF(@c = 0, 'ALTER TABLE `field_observations` ADD COLUMN `ask_role` VARCHAR(20) NULL COMMENT ''onsite | site''', 'SELECT 1');
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @c = (SELECT COUNT(1) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'field_observations' AND COLUMN_NAME = 'asked_at');
SET @sql = IF(@c = 0, 'ALTER TABLE `field_observations` ADD COLUMN `asked_at` DATETIME NULL COMMENT ''When the Ask email went out''', 'SELECT 1');
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @c = (SELECT COUNT(1) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'field_observations' AND COLUMN_NAME = 'replied_at');
SET @sql = IF(@c = 0, 'ALTER TABLE `field_observations` ADD COLUMN `replied_at` DATETIME NULL COMMENT ''First reply seen after asked_at (sales_messages)''', 'SELECT 1');
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @c = (SELECT COUNT(1) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'field_observations' AND COLUMN_NAME = 'ask_sent_by');
SET @sql = IF(@c = 0, 'ALTER TABLE `field_observations` ADD COLUMN `ask_sent_by` INT NULL COMMENT ''users.id of the manager/admin who sent the Ask''', 'SELECT 1');
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @c = (SELECT COUNT(1) FROM information_schema.STATISTICS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'field_observations' AND INDEX_NAME = 'idx_fo_intent_status');
SET @sql = IF(@c = 0, 'ALTER TABLE `field_observations` ADD INDEX `idx_fo_intent_status` (`intent`, `status`, `asked_at`)', 'SELECT 1');
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

-- ── products ────────────────────────────────────────────────────────────────
SET @c = (SELECT COUNT(1) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'products' AND COLUMN_NAME = 'field_ask_pitch');
SET @sql = IF(@c = 0, 'ALTER TABLE `products` ADD COLUMN `field_ask_pitch` TEXT NULL COMMENT ''Ask-first email: what we do and why now, in Tim''''s voice''', 'SELECT 1');
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

-- ── field_ask_messages ──────────────────────────────────────────────────────
CREATE TABLE IF NOT EXISTS field_ask_messages (
  id INT AUTO_INCREMENT PRIMARY KEY,
  observation_id INT NOT NULL,
  product_id INT NULL,
  contact_id INT NULL,
  to_email VARCHAR(255) NULL,
  drafted_by VARCHAR(20) NOT NULL DEFAULT 'template' COMMENT 'template | learned',
  suggested_subject VARCHAR(255) NULL,
  suggested_body TEXT NULL,
  final_subject VARCHAR(255) NULL,
  final_body TEXT NULL,
  learned_subject VARCHAR(255) NULL COMMENT 'Tim''s subject with the customer details put back as {placeholders}',
  learned_body TEXT NULL            COMMENT 'Tim''s edit with the customer details put back as {placeholders} — the next draft for this service',
  photo_count INT NOT NULL DEFAULT 0,
  status VARCHAR(20) NOT NULL DEFAULT 'sent' COMMENT 'sent | edited | failed',
  error VARCHAR(255) NULL,
  sent_by INT NULL,
  sent_at DATETIME NULL,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  INDEX idx_fam_product (product_id, status, sent_at),
  INDEX idx_fam_obs (observation_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci
  COMMENT='Ask-first emails from field recommendations, and what Tim changed';

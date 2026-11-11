-- Migration 1170: Charlie — Chief of Staff
-- Date: 2026-10-05
-- Purpose: Charlie reads every department head (Penny, Sam, Otto, Mia + the unowned
--   Work Queue items), picks the one thing that needs the owner today and builds a 7 am
--   brief. He learns from the order the owner clears things and what he waves away.
--     charlie_items     — every item a head has shown, by its stable key, and what happened to it
--     charlie_briefs    — one row per day: the brief as it stood at 7 am, and whether it was emailed
--     charlie_prefs     — learned weight per kind of item (pairwise, from the owner's order)
--     charlie_questions — "which comes first?" / "stop showing these?"
--   ops_settings charlie_owner_user_id — whose dashboard Charlie lives on and who gets the email.
--     Seeded with the first active admin; CHECK it is the owner after running.
-- Code is guarded: the card and the cron probe for charlie_items and do nothing until this runs.
-- MySQL 5.7 compatible (no JSON columns, no generated columns).

CREATE TABLE IF NOT EXISTS charlie_items (
  id INT AUTO_INCREMENT PRIMARY KEY,
  item_key VARCHAR(120) NOT NULL,
  head VARCHAR(20) NOT NULL,
  kind VARCHAR(60) NOT NULL DEFAULT '',
  text VARCHAR(500) NOT NULL DEFAULT '',
  url VARCHAR(500) NULL,
  priority TINYINT NOT NULL DEFAULT 2,
  value DECIMAL(12,2) NULL,
  since DATE NULL,
  first_seen DATETIME NOT NULL,
  last_seen DATETIME NOT NULL,
  resolved_at DATETIME NULL COMMENT 'vanished from its head = dealt with',
  opened_at DATETIME NULL COMMENT 'owner clicked it from Charlie',
  dismissed_at DATETIME NULL COMMENT 'owner said not important',
  snoozed_until DATE NULL COMMENT 'owner said later — hidden until this date',
  learned TINYINT(1) NOT NULL DEFAULT 0 COMMENT 'this occurrence already taught the ranking',
  times_top INT NOT NULL DEFAULT 0,
  UNIQUE KEY uq_charlie_item_key (item_key),
  INDEX idx_charlie_item_open (resolved_at, dismissed_at),
  INDEX idx_charlie_item_kind (kind)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

CREATE TABLE IF NOT EXISTS charlie_briefs (
  id INT AUTO_INCREMENT PRIMARY KEY,
  brief_date DATE NOT NULL,
  user_id INT NULL,
  top_key VARCHAR(120) NULL,
  payload MEDIUMTEXT NULL COMMENT 'the brief as shown, json-encoded text',
  first_acted_key VARCHAR(120) NULL,
  first_acted_at DATETIME NULL,
  opened_at DATETIME NULL COMMENT 'first time the owner saw it on the dashboard',
  emailed_at DATETIME NULL,
  email_error VARCHAR(255) NULL,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY uq_charlie_brief_date (brief_date)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

CREATE TABLE IF NOT EXISTS charlie_prefs (
  kind VARCHAR(60) NOT NULL PRIMARY KEY,
  score DECIMAL(6,3) NOT NULL DEFAULT 1.000,
  decisions INT NOT NULL DEFAULT 0,
  muted TINYINT(1) NOT NULL DEFAULT 0,
  updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

CREATE TABLE IF NOT EXISTS charlie_questions (
  id INT AUTO_INCREMENT PRIMARY KEY,
  kind VARCHAR(20) NOT NULL COMMENT 'which_first | mute',
  pair_hash CHAR(40) NOT NULL COMMENT 'sha1 of kind + item kinds — each question is asked once',
  key_a VARCHAR(120) NULL,
  key_b VARCHAR(120) NULL,
  kind_a VARCHAR(60) NULL,
  kind_b VARCHAR(60) NULL,
  text_a VARCHAR(500) NULL,
  text_b VARCHAR(500) NULL,
  status VARCHAR(20) NOT NULL DEFAULT 'open' COMMENT 'open | answered | expired',
  answer VARCHAR(20) NULL COMMENT 'a | b | mute | keep',
  answered_at DATETIME NULL,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY uq_charlie_q_pair (pair_hash),
  INDEX idx_charlie_q_status (status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

INSERT INTO ops_settings (setting_key, setting_value, description)
SELECT 'charlie_owner_user_id', MIN(id), 'Charlie (Chief of Staff): the owner whose dashboard he lives on and who gets the 7 am brief'
FROM users WHERE role = 'admin' AND is_active = 1
ON DUPLICATE KEY UPDATE setting_key = setting_key;

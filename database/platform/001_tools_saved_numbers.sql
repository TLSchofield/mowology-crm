-- Ittai PLATFORM database (not a business database), 2026-11-12.
-- Free gardener calculators on ittai.ca/tools: "Save my numbers" profiles and anonymous daily use counts.
-- Run once on the platform database named by ITTAI_PLATFORM_DB_NAME in secrets.php. MySQL 5.7-compatible.

CREATE TABLE saved_numbers (
  id                     INT UNSIGNED NOT NULL AUTO_INCREMENT,
  email                  VARCHAR(254) NOT NULL,
  email_hash             CHAR(64) NOT NULL,
  tool                   VARCHAR(40) NOT NULL,
  inputs_json            TEXT NOT NULL,
  version                TINYINT UNSIGNED NOT NULL DEFAULT 1,
  status                 ENUM('pending','confirmed') NOT NULL DEFAULT 'pending',
  confirm_token_hash     CHAR(64) NOT NULL,
  delete_token_hash      CHAR(64) NOT NULL,
  consent_save_text      TEXT NULL,
  consent_news_text      TEXT NULL,
  consent_benchmark_text TEXT NULL,
  consent_save_at        DATETIME NULL,
  consent_news_at        DATETIME NULL,
  consent_benchmark_at   DATETIME NULL,
  source_page            VARCHAR(120) NULL,
  created_at             DATETIME NOT NULL,
  confirmed_at           DATETIME NULL,
  last_used_at           DATETIME NULL,
  reminder_sent_at       DATETIME NULL,
  PRIMARY KEY (id),
  KEY idx_saved_numbers_email_hash (email_hash),
  KEY idx_saved_numbers_confirm (confirm_token_hash),
  KEY idx_saved_numbers_delete (delete_token_hash),
  KEY idx_saved_numbers_status_created (status, created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

CREATE TABLE tool_usage_daily (
  tool            VARCHAR(40) NOT NULL,
  day             DATE NOT NULL,
  calcs           INT UNSIGNED NOT NULL DEFAULT 0,
  saves_confirmed INT UNSIGNED NOT NULL DEFAULT 0,
  PRIMARY KEY (tool, day)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- Migration 1455: app vitals — what the phones report about crashes, hangs and speed (2026-11-15)
-- Purpose: iPhone and Android send one batch format to /crm/api/app-vitals.php; AppVitalsService groups events into issues
-- so Ada can say "new problem on build 640" without anyone exporting a log. Lives on the hub install (Tim, 2026-11-15:
-- one view across businesses); `business` is Tenant::key() of the install the phone talked to.
-- MySQL 5.7, utf8mb4_general_ci. Safe to run twice.
CREATE TABLE IF NOT EXISTS app_vitals_events (
  id BIGINT AUTO_INCREMENT PRIMARY KEY,
  event_id VARCHAR(64) NOT NULL COMMENT 'uuid from the phone; makes a resend harmless',
  business VARCHAR(64) NOT NULL DEFAULT '',
  user_id INT NOT NULL DEFAULT 0,
  platform VARCHAR(12) NOT NULL COMMENT 'ios | android',
  install_id VARCHAR(64) NOT NULL DEFAULT '',
  app_version VARCHAR(24) NOT NULL DEFAULT '',
  build VARCHAR(24) NOT NULL DEFAULT '',
  device VARCHAR(64) NOT NULL DEFAULT '',
  os VARCHAR(64) NOT NULL DEFAULT '',
  kind VARCHAR(24) NOT NULL,
  severity VARCHAR(8) NOT NULL DEFAULT 'info' COMMENT 'info | warn | error',
  signature CHAR(40) NULL COMMENT 'sha1 of kind + cause + frame; NULL for metric rows',
  message VARCHAR(600) NOT NULL DEFAULT '',
  breadcrumbs TEXT NULL,
  occurred_at DATETIME NOT NULL,
  received_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY uq_vitals_event (event_id),
  KEY idx_vitals_received (received_at),
  KEY idx_vitals_install_day (install_id, received_at),
  KEY idx_vitals_sig (signature)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

CREATE TABLE IF NOT EXISTS app_vitals_issues (
  signature CHAR(40) NOT NULL PRIMARY KEY,
  kind VARCHAR(24) NOT NULL,
  platform VARCHAR(12) NOT NULL,
  title VARCHAR(255) NOT NULL DEFAULT '',
  first_seen DATETIME NOT NULL,
  last_seen DATETIME NOT NULL,
  first_build VARCHAR(24) NOT NULL DEFAULT '',
  last_build VARCHAR(24) NOT NULL DEFAULT '',
  event_count INT NOT NULL DEFAULT 1,
  status VARCHAR(12) NOT NULL DEFAULT 'open' COMMENT 'open | resolved | ignored',
  KEY idx_issue_last (last_seen)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

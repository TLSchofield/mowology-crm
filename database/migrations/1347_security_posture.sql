-- Migration 1347: Ada's nightly security-posture checks (2026-10-13).
-- Purpose: store the verdicts of cron security_posture (one row per check, replaced nightly; red_since
-- keeps the time a check first turned red so Charlie emails each new red once) and the heartbeats the
-- iMac posts after each backup / composer audit (public/crm/api/backup-heartbeat.php).
-- MySQL 5.7, utf8mb4_general_ci. Idempotent.
CREATE TABLE IF NOT EXISTS backup_heartbeats (
  id INT UNSIGNED NOT NULL AUTO_INCREMENT,
  kind VARCHAR(20) NOT NULL COMMENT 'db | files | composer',
  ok TINYINT(1) NOT NULL DEFAULT 0,
  bytes BIGINT UNSIGNED NULL,
  advisories INT UNSIGNED NULL COMMENT 'composer: number of advisories',
  detail VARCHAR(500) NULL,
  finished_at DATETIME NOT NULL COMMENT 'when the Mac finished (server local time)',
  received_at DATETIME NOT NULL,
  source_ip VARCHAR(45) NULL,
  PRIMARY KEY (id),
  KEY idx_kind_finished (kind, finished_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

CREATE TABLE IF NOT EXISTS security_posture_checks (
  check_key VARCHAR(60) NOT NULL,
  name VARCHAR(120) NOT NULL,
  state VARCHAR(10) NOT NULL COMMENT 'red | amber | unknown | green',
  problem VARCHAR(40) NULL,
  evidence TEXT NULL,
  handoff TEXT NULL,
  handoff_to VARCHAR(20) NULL,
  last_worked DATETIME NULL,
  red_since DATETIME NULL COMMENT 'first run this check was red; NULL when not red',
  checked_at DATETIME NOT NULL,
  PRIMARY KEY (check_key)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

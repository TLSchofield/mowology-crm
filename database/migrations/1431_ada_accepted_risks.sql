-- Migration 1431: Ada accepted risks (2026-11-03)
-- Purpose: Tim can accept a known risk on System Health ("Accept for 90 days", with a one-line reason).
-- The check keeps its colour but leaves the counts, Charlie's brief and the urgent emails until review_by,
-- then comes back saying when it was accepted and by whom. A Critical check (auth, backups, audit chain,
-- database rights, red) can never be accepted — AdaRiskRegister decides. Released when the check clears
-- on its own ('cleared'), when Tim presses Release ('released') or a new acceptance replaces it ('replaced').
-- MySQL 5.7, utf8mb4_general_ci. Idempotent.
CREATE TABLE IF NOT EXISTS ada_accepted_risks (
  id INT UNSIGNED NOT NULL AUTO_INCREMENT,
  system_key VARCHAR(190) NOT NULL,
  reason VARCHAR(300) NOT NULL,
  accepted_by INT NULL,
  accepted_by_name VARCHAR(80) NULL,
  accepted_at DATETIME NOT NULL,
  review_by DATE NOT NULL,
  released_at DATETIME NULL,
  released_how VARCHAR(20) NULL COMMENT 'cleared | released | replaced',
  PRIMARY KEY (id),
  KEY idx_system_open (system_key, released_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

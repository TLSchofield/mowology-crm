-- Migration 1319: a ready copy of a head's brief (owner, 2026-10-10). See HeadBriefReadyService.
-- Charlie's brief takes ~7 s to build (he reads every head's desk); the iPhone's opening screen
-- reads this copy instead, built by Charlie's 15-minute cron and refreshed after each read.
-- MySQL 5.7: plain DDL.
CREATE TABLE IF NOT EXISTS head_brief_ready (
  head VARCHAR(20) NOT NULL PRIMARY KEY,
  payload MEDIUMTEXT NOT NULL COMMENT 'The team-mobile ?mode=brief JSON, exactly as served',
  built_at DATETIME NOT NULL,
  build_ms INT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

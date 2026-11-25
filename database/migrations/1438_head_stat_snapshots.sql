-- Migration 1438: head stat snapshots (2026-11-05)
-- Purpose: the Progress tab of Agent Studio shows each head's numbers over time. Numbers kept on dated rows
-- (Penny's suggestions, quotes, llm_calls) are rebuilt by week from those rows; numbers that only exist "right now"
-- (receipts waiting, Otto's open suggestions, quotes still out) have no history, so HeadProgressService::snapshot()
-- writes one row per head per stat per day here. Written by Charlie's morning brief cron and by the Progress tab
-- (first one of the day wins). Read by HeadProgressService::history(). Nothing else writes it.
-- MySQL 5.7, utf8mb4_general_ci. Idempotent.
CREATE TABLE IF NOT EXISTS head_stat_snapshots (
  id INT UNSIGNED NOT NULL AUTO_INCREMENT,
  snap_date DATE NOT NULL,
  head VARCHAR(24) NOT NULL,
  stat_key VARCHAR(48) NOT NULL,
  value DECIMAL(14,2) NULL,
  created_at DATETIME NOT NULL,
  PRIMARY KEY (id),
  UNIQUE KEY uq_head_stat_day (snap_date, head, stat_key),
  KEY idx_head_stat (head, stat_key, snap_date)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

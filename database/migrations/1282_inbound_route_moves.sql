-- Migration 1282: a log of every inbound message moved between heads
-- Date: 2026-10-08
-- Purpose: "Move to..." (Tim), "Done" and the one-off re-route of misfiled mail (admin dry run
--   + apply, /crm/api/inbound-route.php?mode=reroute) each write a row here: which message,
--   from which head to which, and why. It is the undo trail — nothing reads it to route.
-- MySQL 5.7 compatible; safe to re-run.

CREATE TABLE IF NOT EXISTS inbound_route_moves (
  id INT AUTO_INCREMENT PRIMARY KEY,
  message_key VARCHAR(191) NOT NULL,
  from_head VARCHAR(10) NULL,
  to_head VARCHAR(10) NOT NULL,
  topic VARCHAR(12) NULL,
  source VARCHAR(10) NOT NULL            COMMENT 'move | reroute | done',
  moved_by INT NULL,
  moved_at DATETIME NOT NULL,
  INDEX idx_irm_key (message_key),
  INDEX idx_irm_at (moved_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci
  COMMENT='Inbound messages moved between department heads (Tim or the re-route)';

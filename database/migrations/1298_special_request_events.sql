-- Migration 1298: Special request log — every SMS, push, gate block and queued-action pass
-- Date: 2026-10-08
-- Purpose:
--   kind: attach | sms | push | gate_block | gate_queued | ack | outcome
--   ok = 1 when the send went out / the action was accepted. body is the exact SMS / push text.
-- MySQL 5.7 compatible; safe to re-run.

CREATE TABLE IF NOT EXISTS special_request_events (
  id INT AUTO_INCREMENT PRIMARY KEY,
  request_id INT NULL,
  request_visit_id INT NULL,
  visit_id INT NULL,
  user_id INT NULL,
  kind VARCHAR(16) NOT NULL,
  ok TINYINT(1) NOT NULL DEFAULT 1,
  detail VARCHAR(255) NULL,
  body VARCHAR(500) NULL,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  INDEX idx_sre_request (request_id, created_at),
  INDEX idx_sre_visit (visit_id, created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci
  COMMENT='Special request sends and gate events (audit)';

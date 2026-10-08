-- Migration 1296: Special requests ↔ the visits they are attached to, with the crew's outcome
-- Date: 2026-10-08
-- Purpose:
--   One row per (request, visit). attached_at is the moment Tim confirmed — the server-side gate
--   (timer start / photo upload) compares an offline-queued action's own time against it, so an
--   action queued BEFORE the request existed is accepted (and logged) and never stuck.
--   status: attached | done | not_done | extra_done | cancelled
--   extra_done writes ONE extra-work line through the visit's existing extras mechanism;
--   extra_ref records it so a second tap can never bill twice.
-- MySQL 5.7 compatible; safe to re-run.

CREATE TABLE IF NOT EXISTS special_request_visits (
  id INT AUTO_INCREMENT PRIMARY KEY,
  request_id INT NOT NULL,
  visit_id INT NOT NULL,
  property_id INT NULL,
  status VARCHAR(12) NOT NULL DEFAULT 'attached' COMMENT 'attached | done | not_done | extra_done | cancelled',
  included_items TEXT NULL                       COMMENT 'One per line — this visit''s share (NULL = the request''s)',
  extra_items TEXT NULL                          COMMENT 'One per line — this visit''s extras (NULL = the request''s)',
  attached_at DATETIME NOT NULL,
  arrival_notified_at DATETIME NULL,
  outcome_reason TEXT NULL,
  extra_description VARCHAR(255) NULL,
  extra_minutes INT NULL,
  extra_amount DECIMAL(10,2) NULL,
  extra_ref VARCHAR(64) NULL                     COMMENT 'visit_extras | needs_billing (visit already invoiced)',
  extra_folded_at DATETIME NULL                  COMMENT 'When extra_minutes were added to job_visits.extras_* (once)',
  outcome_by INT NULL,
  outcome_at DATETIME NULL,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY uq_srv_request_visit (request_id, visit_id),
  INDEX idx_srv_visit (visit_id, status),
  INDEX idx_srv_request (request_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci
  COMMENT='Which visits a special request is attached to + the crew outcome';

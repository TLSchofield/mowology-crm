-- Migration 1316: every approved quote gets Sam's plain summary and a job in the Unscheduled tray
-- (owner, 2026-10-10). See QuoteApprovalService. One row per quote, so a job is never made twice.
-- MySQL 5.7: summary is plain TEXT holding JSON written by PHP (no JSON column / functions).
CREATE TABLE IF NOT EXISTS quote_approvals (
  quote_id INT NOT NULL PRIMARY KEY,
  via VARCHAR(30) NULL COMMENT 'signed online | verbal | by email | approved',
  approved_by VARCHAR(190) NULL,
  plan_id INT NULL COMMENT 'The tray job made from the approved lines (NULL for contracts / when off)',
  summary TEXT NULL COMMENT 'QuoteApprovalService::summary() as JSON — what Sam shows',
  job_note VARCHAR(255) NULL COMMENT 'What happened about the job',
  created_at DATETIME NOT NULL,
  acknowledged_at DATETIME NULL COMMENT 'Owner tapped Got it on Sam''s card',
  acknowledged_by INT NULL,
  KEY idx_quote_approvals_pending (acknowledged_at, created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

INSERT INTO ops_settings (setting_key, setting_value, description)
VALUES ('quote_auto_job_enabled', '1', 'Approved quotes become a job in the Unscheduled tray (never contracts)'),
       ('quote_auto_job_since', '2026-10-09', 'Only quotes approved on/after this date get an automatic tray job')
ON DUPLICATE KEY UPDATE description = VALUES(description);

-- Migration 1348: head_lessons — every time a person overrides a department head's answer (2026-10-13).
-- Purpose: one learning record for every head (plan docs/plans/2026-10-13-penny-upgrade-and-head-learning.md,
-- Part A1). Penny first: a changed receipt category / payment method / job / vendor / split, a bank line
-- moved to another account, a receipt linked by hand, a deposit matched or un-matched by hand, "already
-- recorded", an answer to one of her questions. Written by HeadLessons::record() (app/Services/HeadLessons.php);
-- read later by the rule learner (A2) and the scorecard (A5). Nothing reads it to change the books yet.
-- site_id: tenant-private (Ittai). MySQL 5.7, utf8mb4_general_ci. Idempotent.
CREATE TABLE IF NOT EXISTS head_lessons (
  id INT UNSIGNED NOT NULL AUTO_INCREMENT,
  site_id INT NOT NULL DEFAULT 1,
  head VARCHAR(20) NOT NULL COMMENT 'penny | sam | otto | …',
  subject_kind VARCHAR(30) NOT NULL COMMENT 'vendor | bank_wording | invoice | service_type | etransfer_sender | …',
  subject_key VARCHAR(120) NOT NULL COMMENT 'normalised: lower case, single spaces',
  field VARCHAR(40) NOT NULL COMMENT 'what was corrected: accounting_category, payment_method, account, …',
  said TEXT NULL COMMENT 'what the head (or the record before the person) said',
  correct TEXT NULL COMMENT 'what the person said was right',
  source VARCHAR(60) NOT NULL COMMENT 'the code path that recorded it',
  ref_type VARCHAR(30) NULL COMMENT 'expense | bank_line | invoice | penny_question | …',
  ref_id INT NULL,
  corrected_by INT NULL COMMENT 'users.id; NULL = the bank line / a system fact',
  reason VARCHAR(255) NULL,
  created_at DATETIME NOT NULL,
  PRIMARY KEY (id),
  KEY idx_head_subject (head, subject_kind, subject_key),
  KEY idx_created (created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

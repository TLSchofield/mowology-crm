-- Migration 1451: the accountant's questions list (Tim, 2026-11-10)
-- Purpose: Penny keeps the questions that wait for the accountant (Ricole at Sit Lim) in one list in the CRM instead of
-- in notes: a month's books-against-bank difference she cannot match to any line, with her evidence. NOTHING here sends
-- anything: the questions go to the accountant in one batch only on the owner's say-so. One row per gap (qkey), so a gap
-- is never asked twice. Written by AccountantQuestionService (bookkeeper.php mode accountant_question, admin only).
-- MySQL 5.7, utf8mb4_general_ci. Idempotent.
CREATE TABLE IF NOT EXISTS accountant_questions (
  id          INT AUTO_INCREMENT PRIMARY KEY,
  qkey        VARCHAR(60)   NOT NULL COMMENT 'one per gap, e.g. drift:2026-01',
  kind        VARCHAR(30)   NOT NULL DEFAULT 'drift',
  month       CHAR(7)       NULL,
  account     VARCHAR(80)   NULL,
  amount      DECIMAL(12,2) NULL COMMENT 'books minus bank, signed',
  question    TEXT          NOT NULL,
  evidence    TEXT          NULL,
  status      VARCHAR(12)   NOT NULL DEFAULT 'open' COMMENT 'open | dropped (asked / answered come with the batch send)',
  created_by  INT           NULL,
  created_at  DATETIME      NOT NULL,
  resolved_at DATETIME      NULL,
  UNIQUE KEY uq_aq_qkey (qkey),
  INDEX idx_aq_status (status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci
  COMMENT='Questions waiting for the accountant; never sent automatically';

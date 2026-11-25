-- Migration 1437: Mia's payment thank-yous wait for each person's best send time, and every email open/click
-- we can see is kept for send-time learning (docs/plans/2026-11-05-payment-thank-you-seasonal-ask.md).
-- MySQL 5.7 safe. No foreign keys (collation drift on prod). Code probes for these and degrades when absent.

ALTER TABLE mia_suggestions
  ADD COLUMN scheduled_at DATETIME NULL COMMENT 'queued: send at this time (SendTimeService)' AFTER decided_at,
  ADD COLUMN sent_at DATETIME NULL COMMENT 'when the email actually went' AFTER scheduled_at,
  ADD COLUMN send_error VARCHAR(255) NULL AFTER sent_at,
  MODIFY COLUMN status VARCHAR(12) NOT NULL DEFAULT 'open' COMMENT 'open | queued | sending | sent | failed | skipped | expired';

CREATE INDEX idx_mia_suggestions_queue ON mia_suggestions (status, scheduled_at);

CREATE TABLE IF NOT EXISTS contact_email_events (
  id INT AUTO_INCREMENT PRIMARY KEY,
  contact_id INT NOT NULL,
  source VARCHAR(20) NOT NULL COMMENT 'mia | (future email sources)',
  ref_id INT NOT NULL,
  kind VARCHAR(10) NOT NULL COMMENT 'open | click',
  at DATETIME NOT NULL,
  user_agent VARCHAR(255) NULL,
  sent_at DATETIME NULL,
  KEY idx_contact (contact_id),
  KEY idx_source_ref (source, ref_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

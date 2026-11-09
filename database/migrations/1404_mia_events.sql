-- Mia Phase 3.2: the events outbox. One row per thing that happened that Mia may want to look at.
-- The unique key (site, type, subject) is the dedup: the same thing cannot be queued twice.
-- No foreign keys (collation drift on production). MySQL 5.7 compatible. Comments contain no semicolons.

CREATE TABLE IF NOT EXISTS mia_events (
  id INT AUTO_INCREMENT PRIMARY KEY,
  site_id INT NOT NULL DEFAULT 1,
  event_type VARCHAR(40) NOT NULL          COMMENT 'e.g. photos.ready',
  subject_key VARCHAR(120) NOT NULL        COMMENT 'what it is about, e.g. visit:2428',
  payload TEXT NULL                        COMMENT 'JSON text: ids and counts only, never client names',
  status VARCHAR(10) NOT NULL DEFAULT 'pending' COMMENT 'pending | done | skipped | failed',
  attempts TINYINT NOT NULL DEFAULT 0,
  outcome VARCHAR(200) NULL                COMMENT 'why it was skipped or failed, or what it produced',
  action_id INT NULL                       COMMENT 'the mia_actions row it produced, if any',
  created_at DATETIME NOT NULL,
  processed_at DATETIME NULL,
  UNIQUE KEY uq_mia_events_subject (site_id, event_type, subject_key),
  INDEX idx_mia_events_status (site_id, status, created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci
  COMMENT='Mia: outbox of events waiting to be looked at';

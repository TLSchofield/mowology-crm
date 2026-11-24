-- Migration 1441: what each person picks from the search box (2026-11-06)
-- Purpose: the one search box (iPhone Search tab, owner mode) ranks what this person chose before for the same start of
-- a query first: type "lin", pick Linda Ma over Lindsay, and Linda leads next time. One row per user + query prefix +
-- result; picks counts the choices. Private to the business; nothing here is shared across businesses.
-- Written by UniversalSearchService::recordPick() only. MySQL 5.7, utf8mb4_general_ci. Idempotent.
CREATE TABLE IF NOT EXISTS search_picks (
  id          INT AUTO_INCREMENT PRIMARY KEY,
  user_id     INT          NOT NULL,
  prefix      VARCHAR(40)  NOT NULL COMMENT 'normalised query, first 1-3 words, up to 40 chars',
  kind        VARCHAR(20)  NOT NULL COMMENT 'contact | company | property | quote | invoice | job | contract | receipt | vendor | message | team',
  entity_id   INT          NOT NULL,
  picks       INT          NOT NULL DEFAULT 1,
  last_at     DATETIME     NOT NULL,
  UNIQUE KEY uq_sp (user_id, prefix, kind, entity_id),
  KEY idx_sp_user_prefix (user_id, prefix)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

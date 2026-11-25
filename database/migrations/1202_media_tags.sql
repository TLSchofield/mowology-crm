-- Migration 1202: Mia's media library — tags, photo picks, and what each photo did.
-- Date: 2026-10-06
-- Purpose:
--   media_tag_state   one row per library photo Mia has tagged: which crew photo/visit it came
--                     from, when it was tagged, and the vision pass (Claude) result + daily cap count.
--   media_usage       every time Mia uses a photo (post, Google post, campaign): channel, what it
--                     was used in, and the engagement/clicks it earned (filled in later).
--   media_tag_scores  learned weight per service|subject|stage combination (MediaPickerService).
--   contacts.photo_optout  a client's explicit photo opt-out. Tim, 2026-10-06: "use them all" —
--                     every client property photo is usable unless the client opted out.
--   ops_settings      media_vision_daily_cap = 40 (vision calls per day).
-- Tags themselves live in media_assets.tags_json (existing column), namespaced: service/…,
--   season/…, stage/…, pair/…, area/…, type/…, subject/…, quality/…, privacy/…, consent/…,
--   use/hero, used/<yyyy-mm>-<channel>.
-- MySQL 5.7 compatible. No ADD COLUMN IF NOT EXISTS: if contacts.photo_optout already exists
--   on a server, skip that one statement (the runner reports "Duplicate column" and carries on).

CREATE TABLE IF NOT EXISTS media_tag_state (
  media_id INT NOT NULL PRIMARY KEY,
  visit_photo_id INT NULL                COMMENT 'visit_photos.id it was copied from (crew photos)',
  visit_id INT NULL,
  tagged_at DATETIME NULL                COMMENT 'Context tags last written',
  vision_at DATETIME NULL                COMMENT 'Vision pass done (or given up after 3 tries)',
  vision_json TEXT NULL                  COMMENT 'What the model said (subjects, quality, privacy flags)',
  vision_error VARCHAR(255) NULL,
  vision_tries TINYINT NULL,
  vision_tokens INT NULL,
  UNIQUE KEY uq_mts_visit_photo (visit_photo_id),
  INDEX idx_mts_visit (visit_id),
  INDEX idx_mts_vision (vision_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci
  COMMENT='Mia: which library photos are tagged, and the vision pass';

CREATE TABLE IF NOT EXISTS media_usage (
  id INT AUTO_INCREMENT PRIMARY KEY,
  media_id INT NOT NULL,
  channel VARCHAR(20) NOT NULL            COMMENT 'email | instagram | facebook | gbp | web | social',
  ref_type VARCHAR(30) NOT NULL           COMMENT 'social_post | gbp_post | campaign | …',
  ref_id INT NOT NULL DEFAULT 0,
  combo VARCHAR(191) NULL                 COMMENT 'service|subject|stage at the time of use',
  used_at DATE NOT NULL,
  engagement INT NOT NULL DEFAULT 0       COMMENT 'likes + comments + shares + saves (social)',
  clicks INT NOT NULL DEFAULT 0           COMMENT 'link clicks (social, campaign emails)',
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  INDEX idx_mu_media (media_id, used_at),
  INDEX idx_mu_ref (ref_type, ref_id),
  INDEX idx_mu_combo (combo)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci
  COMMENT='Mia: which photo each post or campaign used, and how it did';

CREATE TABLE IF NOT EXISTS media_tag_scores (
  combo VARCHAR(191) NOT NULL PRIMARY KEY,
  uses INT NOT NULL DEFAULT 0,
  engagement INT NOT NULL DEFAULT 0,
  clicks INT NOT NULL DEFAULT 0,
  score DECIMAL(5,2) NOT NULL DEFAULT 0   COMMENT '-1..2, relative to the average combo',
  updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci
  COMMENT='Mia: learned weight of each service|subject|stage photo combination';

INSERT INTO ops_settings (setting_key, setting_value, description)
VALUES ('media_vision_daily_cap', '40', 'Mia: photos the vision pass may tag per day (Claude calls)')
ON DUPLICATE KEY UPDATE description = VALUES(description);

ALTER TABLE contacts
  ADD COLUMN photo_optout TINYINT(1) NOT NULL DEFAULT 0
  COMMENT 'Client asked us not to use photos of their property (consent/no on every photo)';

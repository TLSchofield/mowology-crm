-- Migration 1459: log of photos moved to another visit because their own GPS showed they were taken elsewhere
-- (Tim, 2026-11-19: approved plan). PhotoRefileService writes a row per move and per "these are fine" dismissal;
-- undo puts the media_links row back and stamps undone_at. Code is guarded: the office list shows nothing and
-- says the migration is needed until this has run.
-- Idempotent (IF NOT EXISTS). MySQL 5.7 compatible.

CREATE TABLE IF NOT EXISTS `photo_refile_log` (
  `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `media_id` INT UNSIGNED NOT NULL,
  `link_id` INT UNSIGNED NULL DEFAULT NULL,
  `action` VARCHAR(10) NOT NULL DEFAULT 'move',
  `from_visit_id` INT UNSIGNED NULL DEFAULT NULL,
  `to_visit_id` INT UNSIGNED NULL DEFAULT NULL,
  `moved_by` INT UNSIGNED NULL DEFAULT NULL,
  `distance_m` INT NULL DEFAULT NULL,
  `accuracy_m` DECIMAL(8,2) NULL DEFAULT NULL,
  `moved_at` DATETIME NOT NULL,
  `undone_at` DATETIME NULL DEFAULT NULL,
  `undone_by` INT UNSIGNED NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_prl_media` (`media_id`),
  KEY `idx_prl_to_visit` (`to_visit_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

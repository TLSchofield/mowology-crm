-- ============================================================================
-- SEO Recommendations System Database Migration
-- Path: /database/migrations/100_seo_recommendations.sql
-- Date: Feb 8, 2026
-- Description: Creates tables for GSC-driven SEO recommendation engine with
--              targeting (city/postcode/neighbourhood) and seasonal automation
-- Idempotent: Safe to run multiple times
-- ============================================================================

-- ============================================================================
-- TABLE 1: seo_targets
-- Defines geographic/demographic targeting zones for recommendations
-- ============================================================================

CREATE TABLE IF NOT EXISTS `seo_targets` (
  `id` int NOT NULL AUTO_INCREMENT PRIMARY KEY,
  `name` varchar(100) NOT NULL COMMENT 'e.g., "Vancouver Core", "V5K Postal"',
  `target_type` enum('city','postcode','neighbourhood') NOT NULL COMMENT 'Type of target',
  `canonical_slug` varchar(100) UNIQUE COMMENT 'URL slug: vancouver, v5k, kitsilano',
  `city` varchar(100) DEFAULT NULL COMMENT 'City name if applicable',
  `postcode_prefix` varchar(10) DEFAULT NULL COMMENT 'e.g., V5K, V6B (stores prefix)',
  `neighbourhood` varchar(100) DEFAULT NULL COMMENT 'e.g., Kitsilano, Mount Pleasant',
  `polygon_geojson` json DEFAULT NULL COMMENT 'Future: geo-fencing support',
  `radius_km` float DEFAULT NULL COMMENT 'Search radius from center point',
  `lat` float DEFAULT NULL COMMENT 'Latitude for radius-based matching',
  `lng` float DEFAULT NULL COMMENT 'Longitude for radius-based matching',
  `priority_weight` int DEFAULT 100 COMMENT 'Boost recommendation score by X%',
  `is_active` tinyint(1) DEFAULT 1 COMMENT 'Enable/disable this target',
  `notes` text COMMENT 'Internal notes about this target',
  `created_at` timestamp DEFAULT CURRENT_TIMESTAMP,
  `updated_at` timestamp DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  KEY `idx_target_type` (`target_type`),
  KEY `idx_canonical_slug` (`canonical_slug`),
  KEY `idx_is_active` (`is_active`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='Geographic targeting zones for SEO recommendations';

-- ============================================================================
-- TABLE 2: seo_seasons
-- Defines seasonal campaign windows and automatic priority boosters
-- ============================================================================

CREATE TABLE IF NOT EXISTS `seo_seasons` (
  `id` int NOT NULL AUTO_INCREMENT PRIMARY KEY,
  `season_key` varchar(50) UNIQUE COMMENT 'e.g., spring-cleanup, winter-stormprep',
  `label` varchar(100) COMMENT 'e.g., Spring Cleanup Season',
  `start_month` int DEFAULT 1 COMMENT 'Start month (1-12)',
  `start_day` int DEFAULT 1 COMMENT 'Start day of month',
  `end_month` int DEFAULT 12 COMMENT 'End month (1-12)',
  `end_day` int DEFAULT 31 COMMENT 'End day of month',
  `services_json` json COMMENT 'Array of service types: ["spring-cleanup","aeration"]',
  `default_offer_text` text COMMENT 'CTA text for this season',
  `priority_boost` int DEFAULT 20 COMMENT 'Boost recommendation score by X% during season',
  `is_active` tinyint(1) DEFAULT 1 COMMENT 'Enable/disable this season',
  `notes` text COMMENT 'Internal notes',
  `created_at` timestamp DEFAULT CURRENT_TIMESTAMP,
  `updated_at` timestamp DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  KEY `idx_season_key` (`season_key`),
  KEY `idx_is_active` (`is_active`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='Seasonal campaign automation rules';

-- ============================================================================
-- TABLE 3: seo_recommendations
-- Main table for scored recommendations generated from GSC data
-- ============================================================================

CREATE TABLE IF NOT EXISTS `seo_recommendations` (
  `id` int NOT NULL AUTO_INCREMENT PRIMARY KEY,
  `query_text` varchar(255) NOT NULL COMMENT 'Search query from GSC',
  `search_volume` int DEFAULT NULL COMMENT 'Impressions (search volume) last 28 days',
  `clicks` int DEFAULT NULL COMMENT 'Clicks last 28 days',
  `ctr` float DEFAULT NULL COMMENT 'Click-through rate percentage',
  `avg_position` float DEFAULT NULL COMMENT 'Average ranking position',
  `target_page_url` varchar(512) DEFAULT NULL COMMENT 'Existing page that matches query',
  `suggested_slug` varchar(255) DEFAULT NULL COMMENT 'e.g., spring-cleanup-vancouver',
  `rec_type` enum('create_page','improve_page','title_meta','internal_links','add_photos','schema','seasonal') DEFAULT 'create_page' COMMENT 'Type of recommendation',
  `priority_score` float DEFAULT 0 COMMENT 'Calculated score (0-100)',
  `target_id` int DEFAULT NULL COMMENT 'FK to seo_targets (city/postcode/neighbourhood)',
  `season_id` int DEFAULT NULL COMMENT 'FK to seo_seasons (if seasonal)',
  `reason` text COMMENT 'Explanation of scoring/recommendation',
  `status` enum('new','accepted','applied','done','ignored') DEFAULT 'new' COMMENT 'Workflow status',
  `applied_at` timestamp NULL COMMENT 'When applied to draft',
  `applied_by` int DEFAULT NULL COMMENT 'FK to users (who applied)',
  `notes` text COMMENT 'User notes/comments',
  `created_at` timestamp DEFAULT CURRENT_TIMESTAMP,
  `updated_at` timestamp DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  UNIQUE KEY `unique_rec` (`query_text`, `rec_type`, `suggested_slug`) COMMENT 'Prevent duplicates',
  KEY `idx_status` (`status`),
  KEY `idx_priority_score` (`priority_score` DESC),
  KEY `idx_target_id` (`target_id`),
  KEY `idx_season_id` (`season_id`),
  KEY `idx_rec_type` (`rec_type`),
  KEY `idx_created_at` (`created_at` DESC),
  CONSTRAINT `fk_seo_rec_target` FOREIGN KEY (`target_id`) REFERENCES `seo_targets` (`id`) ON DELETE SET NULL,
  CONSTRAINT `fk_seo_rec_season` FOREIGN KEY (`season_id`) REFERENCES `seo_seasons` (`id`) ON DELETE SET NULL,
  CONSTRAINT `fk_seo_rec_applied_by` FOREIGN KEY (`applied_by`) REFERENCES `users` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='SEO recommendations scored from GSC data';

-- ============================================================================
-- TABLE 4: seo_page_drafts
-- Stores AI-generated page drafts until manually published
-- ============================================================================

CREATE TABLE IF NOT EXISTS `seo_page_drafts` (
  `id` int NOT NULL AUTO_INCREMENT PRIMARY KEY,
  `recommendation_id` int DEFAULT NULL COMMENT 'FK to seo_recommendations',
  `slug` varchar(255) UNIQUE COMMENT 'URL slug derived from query/target',
  `title` varchar(255) COMMENT 'SEO title (60 char recommended)',
  `meta_description` varchar(160) COMMENT 'Meta description (160 char limit)',
  `h1` varchar(255) COMMENT 'Main heading (H1 tag)',
  `intro_text` text COMMENT 'Opening paragraph',
  `sections_json` json COMMENT 'Array of content blocks: {heading, content, cta}',
  `internal_links_json` json COMMENT 'Suggested linking: [{anchor, url, context}]',
  `images_json` json COMMENT 'Array of media: {media_id, alt_text, caption, position}',
  `schema_json` json COMMENT 'JSON-LD structured data (LocalBusiness/Service)',
  `target_id` int DEFAULT NULL COMMENT 'FK to seo_targets',
  `season_id` int DEFAULT NULL COMMENT 'FK to seo_seasons',
  `status` enum('draft','review','scheduled','published') DEFAULT 'draft' COMMENT 'Publishing workflow',
  `seo_score` int DEFAULT NULL COMMENT 'Basic readability + keyword optimization score',
  `created_by` int DEFAULT NULL COMMENT 'FK to users (who generated)',
  `published_at` timestamp NULL COMMENT 'When published (if ever)',
  `published_url` varchar(512) DEFAULT NULL COMMENT 'Live URL after publishing',
  `created_at` timestamp DEFAULT CURRENT_TIMESTAMP,
  `updated_at` timestamp DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  KEY `idx_slug` (`slug`),
  KEY `idx_status` (`status`),
  KEY `idx_target_id` (`target_id`),
  KEY `idx_season_id` (`season_id`),
  KEY `idx_created_by` (`created_by`),
  KEY `idx_created_at` (`created_at` DESC),
  CONSTRAINT `fk_seo_draft_rec` FOREIGN KEY (`recommendation_id`) REFERENCES `seo_recommendations` (`id`) ON DELETE SET NULL,
  CONSTRAINT `fk_seo_draft_target` FOREIGN KEY (`target_id`) REFERENCES `seo_targets` (`id`) ON DELETE SET NULL,
  CONSTRAINT `fk_seo_draft_season` FOREIGN KEY (`season_id`) REFERENCES `seo_seasons` (`id`) ON DELETE SET NULL,
  CONSTRAINT `fk_seo_draft_created_by` FOREIGN KEY (`created_by`) REFERENCES `users` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='AI-generated SEO page drafts';

-- ============================================================================
-- TABLE 5: seo_recommendations_audit
-- Action logging for accountability and troubleshooting
-- ============================================================================

CREATE TABLE IF NOT EXISTS `seo_recommendations_audit` (
  `id` int NOT NULL AUTO_INCREMENT PRIMARY KEY,
  `recommendation_id` int NOT NULL COMMENT 'FK to seo_recommendations',
  `action` varchar(50) COMMENT 'Action performed: generated, accepted, applied, done, ignored, scored',
  `user_id` int DEFAULT NULL COMMENT 'FK to users (who performed action)',
  `old_status` varchar(50) COMMENT 'Previous status',
  `new_status` varchar(50) COMMENT 'New status',
  `old_score` float DEFAULT NULL COMMENT 'Previous priority score',
  `new_score` float DEFAULT NULL COMMENT 'New priority score',
  `details` text COMMENT 'Additional context (JSON if needed)',
  `notes` text COMMENT 'User-provided notes',
  `ip_address` varchar(45) DEFAULT NULL COMMENT 'IP address of actor',
  `created_at` timestamp DEFAULT CURRENT_TIMESTAMP,
  KEY `idx_recommendation_id` (`recommendation_id`),
  KEY `idx_user_id` (`user_id`),
  KEY `idx_action` (`action`),
  KEY `idx_created_at` (`created_at` DESC),
  CONSTRAINT `fk_seo_audit_rec` FOREIGN KEY (`recommendation_id`) REFERENCES `seo_recommendations` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_seo_audit_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='Audit trail for SEO recommendation actions';

-- ============================================================================
-- SAMPLE DATA (Optional — remove if not needed for production)
-- ============================================================================

-- Sample targets
INSERT IGNORE INTO `seo_targets` (`name`, `target_type`, `canonical_slug`, `city`, `postcode_prefix`, `priority_weight`, `is_active`)
VALUES
  ('Vancouver Core', 'city', 'vancouver', 'Vancouver', NULL, 100, 1),
  ('Burnaby', 'city', 'burnaby', 'Burnaby', NULL, 90, 1),
  ('Richmond', 'city', 'richmond', 'Richmond', NULL, 80, 1),
  ('V5K Postal', 'postcode', 'v5k', NULL, 'V5K', 110, 1),
  ('V6B Postal', 'postcode', 'v6b', NULL, 'V6B', 110, 1),
  ('Kitsilano', 'neighbourhood', 'kitsilano', 'Vancouver', NULL, 105, 1),
  ('Mount Pleasant', 'neighbourhood', 'mount-pleasant', 'Vancouver', NULL, 105, 1);

-- Sample seasons
INSERT IGNORE INTO `seo_seasons` (`season_key`, `label`, `start_month`, `start_day`, `end_month`, `end_day`, `services_json`, `default_offer_text`, `priority_boost`, `is_active`)
VALUES
  ('spring-cleanup', 'Spring Cleanup Season', 3, 15, 5, 31, '["spring-cleanup","aeration","thatch"]', 'Spring cleanup special — get your lawn ready!', 30, 1),
  ('summer-maintenance', 'Summer Maintenance', 6, 1, 8, 31, '["weekly-mowing","hedge-trimming","maintenance"]', 'Keep your property beautiful all summer', 20, 1),
  ('fall-cleanup', 'Fall Cleanup Season', 9, 1, 11, 15, '["leaf-cleanup","gutter-cleaning","winterization"]', 'Prepare for winter with fall cleanup', 25, 1),
  ('winter-stormprep', 'Winter Storm Preparation', 11, 15, 2, 28, '["snow-removal","de-icing","storm-cleanup"]', 'Winter storm preparation available', 35, 1),
  ('strata-maintenance', 'Strata & Commercial', 1, 1, 12, 31, '["strata-maintenance","commercial-grounds"]', 'Professional strata property maintenance', 15, 1);

-- ============================================================================
-- INDICES & CONSTRAINTS (Summary)
-- ============================================================================

-- All primary and foreign keys are defined in CREATE TABLE statements above.
-- Additional indexes on frequently-queried columns for performance:
--   - seo_recommendations: status, priority_score DESC, target_id, season_id, rec_type, created_at DESC
--   - seo_page_drafts: slug, status, target_id, season_id, created_at DESC
--   - seo_recommendations_audit: recommendation_id, user_id, action, created_at DESC

-- ============================================================================
-- END MIGRATION
-- ============================================================================

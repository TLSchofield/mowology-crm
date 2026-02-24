-- ============================================================================
-- Migration 506: Service Types — Configurable service type catalog
-- ============================================================================
-- Purpose: Replace hardcoded service type arrays across the codebase with a
--          DB-driven table. Existing hardcoded arrays remain as fallbacks so
--          this migration is fully non-breaking on partial rollout.
--
-- MySQL 5.7 compatible — no JSON functions, no window functions
-- Run outside of a transaction (DDL auto-commits in MySQL)
-- ============================================================================

CREATE TABLE IF NOT EXISTS `service_types` (
    `id`              int NOT NULL AUTO_INCREMENT,
    `slug`            varchar(100) NOT NULL COMMENT 'Machine key, e.g. lawn_care (used in DB columns)',
    `label`           varchar(100) NOT NULL COMMENT 'Human label, e.g. Lawn Care',
    `color`           varchar(7) NOT NULL DEFAULT '#455A64' COMMENT 'Hex color for job cards',
    `icon`            varchar(50) NULL COMMENT 'Feather icon name, e.g. scissors',
    `is_active`       tinyint(1) NOT NULL DEFAULT 1,
    `show_in_jobflow` tinyint(1) NOT NULL DEFAULT 0 COMMENT 'Show on public quote form (jobFlow)',
    `sort_order`      int NOT NULL DEFAULT 0,
    `created_at`      timestamp NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at`      timestamp NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    UNIQUE KEY `uq_st_slug` (`slug`),
    KEY `idx_st_active` (`is_active`),
    KEY `idx_st_sort` (`sort_order`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci
COMMENT='Configurable service type catalog — replaces hardcoded PHP arrays';


-- ============================================================================
-- Seed: All existing hardcoded service types (consolidated from 6+ PHP arrays)
-- ============================================================================
-- Slugs match existing DB column values in job_plans.service_type etc.
-- show_in_jobflow=1 for the 5 types accepted by jobFlow/helpers/validators.php

INSERT IGNORE INTO `service_types` (slug, label, color, icon, is_active, show_in_jobflow, sort_order)
VALUES
    ('lawn_care',          'Lawn Care',          '#2E7D32', 'scissors',      1, 1, 10),
    ('maintenance',        'Maintenance',         '#2D8659', 'tool',          1, 1, 20),
    ('cleanup',            'Cleanup',             '#455A64', 'trash-2',       1, 1, 30),
    ('snow_removal',       'Snow Removal',        '#1565C0', 'cloud-snow',    1, 1, 40),
    ('hedge_trimming',     'Hedge Trimming',      '#6A1B9A', 'git-branch',    1, 1, 50),
    ('landscaping',        'Landscaping',         '#2D8659', 'map',           1, 0, 60),
    ('garden_maintenance', 'Garden Maintenance',  '#EF6C00', 'feather',       1, 0, 70),
    ('seasonal_cleanup',   'Seasonal Cleanup',    '#455A64', 'wind',          1, 0, 80);

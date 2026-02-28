-- Migration 503: Fertilizer Bundle Pre-Sell — AAA Flow
-- Adds columns to support pre-sold fertilizer programs with smart seasonal scheduling,
-- auto-calculated materials, completion notifications, and rich customer portal cards.
-- Run once. All ALTER TABLE uses stored-procedure guard pattern for safety.

-- ─────────────────────────────────────────────────────────────────
-- product_bundles: seasonal schedule + application count
-- ─────────────────────────────────────────────────────────────────
DROP PROCEDURE IF EXISTS _add_bundle_application_count;
DELIMITER $$
CREATE PROCEDURE _add_bundle_application_count()
BEGIN
    IF NOT EXISTS (
        SELECT 1 FROM information_schema.COLUMNS
        WHERE TABLE_SCHEMA = DATABASE()
          AND TABLE_NAME   = 'product_bundles'
          AND COLUMN_NAME  = 'application_count'
    ) THEN
        ALTER TABLE product_bundles
            ADD COLUMN application_count INT DEFAULT NULL
                COMMENT 'Number of visits in this bundle (e.g., 3 for Spring/Summer/Fall)';
    END IF;
END$$
DELIMITER ;
CALL _add_bundle_application_count();
DROP PROCEDURE IF EXISTS _add_bundle_application_count;

DROP PROCEDURE IF EXISTS _add_bundle_seasonal_schedule;
DELIMITER $$
CREATE PROCEDURE _add_bundle_seasonal_schedule()
BEGIN
    IF NOT EXISTS (
        SELECT 1 FROM information_schema.COLUMNS
        WHERE TABLE_SCHEMA = DATABASE()
          AND TABLE_NAME   = 'product_bundles'
          AND COLUMN_NAME  = 'seasonal_schedule'
    ) THEN
        ALTER TABLE product_bundles
            ADD COLUMN seasonal_schedule TEXT DEFAULT NULL
                COMMENT 'JSON: [{"month":4,"label":"Spring Feed"},{"month":7,"label":"Summer Feed"},{"month":9,"label":"Fall Prep"}]';
    END IF;
END$$
DELIMITER ;
CALL _add_bundle_seasonal_schedule();
DROP PROCEDURE IF EXISTS _add_bundle_seasonal_schedule;

-- ─────────────────────────────────────────────────────────────────
-- job_plans: prepaid bundle flags + application counter
-- ─────────────────────────────────────────────────────────────────
DROP PROCEDURE IF EXISTS _add_plan_prepaid_flag;
DELIMITER $$
CREATE PROCEDURE _add_plan_prepaid_flag()
BEGIN
    IF NOT EXISTS (
        SELECT 1 FROM information_schema.COLUMNS
        WHERE TABLE_SCHEMA = DATABASE()
          AND TABLE_NAME   = 'job_plans'
          AND COLUMN_NAME  = 'is_prepaid_bundle'
    ) THEN
        ALTER TABLE job_plans
            ADD COLUMN is_prepaid_bundle TINYINT(1) NOT NULL DEFAULT 0
                COMMENT 'Plan was sold as a pre-paid bundle — no per-visit invoice generated';
    END IF;
END$$
DELIMITER ;
CALL _add_plan_prepaid_flag();
DROP PROCEDURE IF EXISTS _add_plan_prepaid_flag;

DROP PROCEDURE IF EXISTS _add_plan_source_bundle_id;
DELIMITER $$
CREATE PROCEDURE _add_plan_source_bundle_id()
BEGIN
    IF NOT EXISTS (
        SELECT 1 FROM information_schema.COLUMNS
        WHERE TABLE_SCHEMA = DATABASE()
          AND TABLE_NAME   = 'job_plans'
          AND COLUMN_NAME  = 'source_bundle_id'
    ) THEN
        ALTER TABLE job_plans
            ADD COLUMN source_bundle_id INT DEFAULT NULL
                COMMENT 'FK to product_bundles — set when plan created from a presold bundle';
    END IF;
END$$
DELIMITER ;
CALL _add_plan_source_bundle_id();
DROP PROCEDURE IF EXISTS _add_plan_source_bundle_id;

DROP PROCEDURE IF EXISTS _add_plan_bundle_apps_used;
DELIMITER $$
CREATE PROCEDURE _add_plan_bundle_apps_used()
BEGIN
    IF NOT EXISTS (
        SELECT 1 FROM information_schema.COLUMNS
        WHERE TABLE_SCHEMA = DATABASE()
          AND TABLE_NAME   = 'job_plans'
          AND COLUMN_NAME  = 'bundle_applications_used'
    ) THEN
        ALTER TABLE job_plans
            ADD COLUMN bundle_applications_used INT NOT NULL DEFAULT 0
                COMMENT 'Incremented each time a prepaid visit completes; tracks bundle usage';
    END IF;
END$$
DELIMITER ;
CALL _add_plan_bundle_apps_used();
DROP PROCEDURE IF EXISTS _add_plan_bundle_apps_used;

-- ─────────────────────────────────────────────────────────────────
-- plan_line_items: link to product for icon/rate lookups
-- ─────────────────────────────────────────────────────────────────
DROP PROCEDURE IF EXISTS _add_plan_line_item_product_id;
DELIMITER $$
CREATE PROCEDURE _add_plan_line_item_product_id()
BEGIN
    IF NOT EXISTS (
        SELECT 1 FROM information_schema.COLUMNS
        WHERE TABLE_SCHEMA = DATABASE()
          AND TABLE_NAME   = 'plan_line_items'
          AND COLUMN_NAME  = 'product_id'
    ) THEN
        ALTER TABLE plan_line_items
            ADD COLUMN product_id INT DEFAULT NULL
                COMMENT 'FK to products — enables icon_base_path, application_rate, application_notes lookups for portal card';
    END IF;
END$$
DELIMITER ;
CALL _add_plan_line_item_product_id();
DROP PROCEDURE IF EXISTS _add_plan_line_item_product_id;

-- ─────────────────────────────────────────────────────────────────
-- job_visits: notification de-dupe guard
-- ─────────────────────────────────────────────────────────────────
DROP PROCEDURE IF EXISTS _add_visit_notification_sent_at;
DELIMITER $$
CREATE PROCEDURE _add_visit_notification_sent_at()
BEGIN
    IF NOT EXISTS (
        SELECT 1 FROM information_schema.COLUMNS
        WHERE TABLE_SCHEMA = DATABASE()
          AND TABLE_NAME   = 'job_visits'
          AND COLUMN_NAME  = 'notification_sent_at'
    ) THEN
        ALTER TABLE job_visits
            ADD COLUMN notification_sent_at TIMESTAMP NULL DEFAULT NULL
                COMMENT 'Set when fertilizer completion email+SMS fires — prevents duplicate notifications';
    END IF;
END$$
DELIMITER ;
CALL _add_visit_notification_sent_at();
DROP PROCEDURE IF EXISTS _add_visit_notification_sent_at;

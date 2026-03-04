-- ============================================================================
-- Migration 801: Geofence multi-zone schema upgrade
-- ============================================================================
-- Fixes:
--   1. job_geofences.zone_type       — add column if missing (arrival_border | work_zone)
--   2. job_geofences.plan_id         — make nullable (NULL = arrival_border/property-level)
--   3. job_location_samples.work_zone_id — add column for per-work-zone classification
--   4. job_visits.transit_seconds    — add column (in-border, out-of-work-zone time)
--   5. visit_zone_sessions           — create table for per-zone time summaries
--
-- Idempotent — safe to run multiple times.
-- MySQL 5.7 compatible (uses stored procedure + information_schema checks).
-- ============================================================================

SET FOREIGN_KEY_CHECKS = 0;

DELIMITER //

DROP PROCEDURE IF EXISTS _geo_m801 //
CREATE PROCEDURE _geo_m801()
BEGIN

    -- ----------------------------------------------------------------
    -- 1. Add zone_type to job_geofences if missing
    -- ----------------------------------------------------------------
    IF NOT EXISTS (
        SELECT 1 FROM information_schema.COLUMNS
        WHERE TABLE_SCHEMA = DATABASE()
          AND TABLE_NAME   = 'job_geofences'
          AND COLUMN_NAME  = 'zone_type'
    ) THEN
        ALTER TABLE job_geofences
            ADD COLUMN zone_type VARCHAR(30) NOT NULL DEFAULT 'work_zone'
            COMMENT 'arrival_border | work_zone'
            AFTER property_id;
        -- Back-fill existing rows (they were all work zones before this column existed)
        UPDATE job_geofences SET zone_type = 'work_zone' WHERE zone_type = '';
    END IF;

    -- ----------------------------------------------------------------
    -- 2. Make plan_id nullable — required for arrival_border zones
    --    (property-level, not tied to any specific plan)
    -- ----------------------------------------------------------------
    IF EXISTS (
        SELECT 1 FROM information_schema.COLUMNS
        WHERE TABLE_SCHEMA = DATABASE()
          AND TABLE_NAME   = 'job_geofences'
          AND COLUMN_NAME  = 'plan_id'
          AND IS_NULLABLE  = 'NO'
    ) THEN
        -- Note: MySQL allows NULL values in FK columns — the FK constraint
        -- continues to enforce referential integrity for non-NULL values.
        -- No need to drop/re-add the FK.
        ALTER TABLE job_geofences
            MODIFY COLUMN plan_id INT NULL DEFAULT NULL
            COMMENT 'NULL for arrival_border (property-level); FK to job_plans for work_zone';
    END IF;

    -- ----------------------------------------------------------------
    -- 3. Add work_zone_id to job_location_samples if missing
    --    Records which work zone each GPS sample fell inside (NULL = transit)
    -- ----------------------------------------------------------------
    IF NOT EXISTS (
        SELECT 1 FROM information_schema.COLUMNS
        WHERE TABLE_SCHEMA = DATABASE()
          AND TABLE_NAME   = 'job_location_samples'
          AND COLUMN_NAME  = 'work_zone_id'
    ) THEN
        ALTER TABLE job_location_samples
            ADD COLUMN work_zone_id INT NULL DEFAULT NULL
            COMMENT 'FK to job_geofences.id — which work zone this sample fell in (NULL = transit)'
            AFTER geofence_id;
    END IF;

    -- ----------------------------------------------------------------
    -- 4. Add transit_seconds to job_visits if missing
    --    Seconds inside arrival border but outside any named work zone
    -- ----------------------------------------------------------------
    IF NOT EXISTS (
        SELECT 1 FROM information_schema.COLUMNS
        WHERE TABLE_SCHEMA = DATABASE()
          AND TABLE_NAME   = 'job_visits'
          AND COLUMN_NAME  = 'transit_seconds'
    ) THEN
        ALTER TABLE job_visits
            ADD COLUMN transit_seconds INT NOT NULL DEFAULT 0
            COMMENT 'Seconds inside arrival border but outside any work zone (transit time)';
    END IF;

END //
CALL _geo_m801() //
DROP PROCEDURE IF EXISTS _geo_m801 //

DELIMITER ;

-- ----------------------------------------------------------------
-- 5. Create visit_zone_sessions if it doesn't exist
--    Per-visit per-zone time summary (2-min dwell filter applied)
-- ----------------------------------------------------------------
CREATE TABLE IF NOT EXISTS visit_zone_sessions (
    id              INT AUTO_INCREMENT PRIMARY KEY,
    visit_id        INT NOT NULL,
    zone_id         INT NOT NULL COMMENT 'FK to job_geofences.id',
    zone_label      VARCHAR(100) NULL,
    plan_id         INT NULL COMMENT 'Denormalised from work zone — which plan this zone belongs to',

    in_seconds      INT NOT NULL DEFAULT 0  COMMENT 'Seconds spent in zone (2-min dwell min applied)',
    entry_count     SMALLINT NOT NULL DEFAULT 0,
    exit_count      SMALLINT NOT NULL DEFAULT 0,
    crew_count      TINYINT NOT NULL DEFAULT 1,

    computed_at     TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,

    UNIQUE KEY uk_vzs_visit_zone (visit_id, zone_id),
    INDEX idx_vzs_visit  (visit_id),
    INDEX idx_vzs_zone   (zone_id),
    INDEX idx_vzs_plan   (plan_id),

    FOREIGN KEY (visit_id) REFERENCES job_visits(id)    ON DELETE CASCADE,
    FOREIGN KEY (zone_id)  REFERENCES job_geofences(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci
  COMMENT='Per-visit per-zone time summary (2-min minimum dwell filter applied).';

SET FOREIGN_KEY_CHECKS = 1;

-- End of migration 801

-- ═══════════════════════════════════════════════════════════════
-- Migration 1008 — Performance indexes for Sprint Ⓓ
-- ═══════════════════════════════════════════════════════════════
--
-- Adds three missing indexes identified in the Capacitor app
-- performance audit (April 2026). All three are additive and safe
-- to apply repeatedly (via IF NOT EXISTS-equivalent logic below, or
-- a one-shot run using the existing run-migration-*.php runners).
--
-- 1. property_measurements(property_id, measurement_type)
--    Sprint Ⓓ D1 refactored getCalendarStops() to batch-fetch lawn
--    measurements via a SUM/GROUP BY on this table. Without this
--    composite index, MySQL full-scans the table on every call.
--
-- 2. calendar_stop_crew(stop_id)
--    getCalendarStops() joins the stop_crew junction table on stop_id
--    for multi-crew assignments. With no index, this is a full scan
--    per lookup.
--
-- 3. crew_location_history(crew_id, timestamp)
--    Used by the WorkManager GPS sync dedup check in tracking-sync.
--    Dedup query: "has this point already been stored?" runs 100+
--    times per sync batch.
--
-- Rollback:
--   DROP INDEX idx_pm_property_type ON property_measurements;
--   DROP INDEX idx_csc_stop        ON calendar_stop_crew;
--   DROP INDEX idx_clh_crew_time   ON crew_location_history;
--
-- ─────────────────────────────────────────────────────────────────

-- MySQL 5.7 doesn't support CREATE INDEX IF NOT EXISTS directly.
-- Use a stored procedure wrapper that checks information_schema so
-- the migration is idempotent.
DELIMITER $$

DROP PROCEDURE IF EXISTS mw_add_index_if_missing$$

CREATE PROCEDURE mw_add_index_if_missing(
    IN p_table VARCHAR(64),
    IN p_index VARCHAR(64),
    IN p_ddl   TEXT
)
BEGIN
    DECLARE idx_exists INT DEFAULT 0;
    SELECT COUNT(*)
    INTO   idx_exists
    FROM   information_schema.statistics
    WHERE  table_schema = DATABASE()
      AND  table_name   = p_table
      AND  index_name   = p_index;

    IF idx_exists = 0 THEN
        SET @sql = p_ddl;
        PREPARE stmt FROM @sql;
        EXECUTE stmt;
        DEALLOCATE PREPARE stmt;
    END IF;
END$$

DELIMITER ;

-- 1) property_measurements — composite (property_id, measurement_type)
CALL mw_add_index_if_missing(
    'property_measurements',
    'idx_pm_property_type',
    'CREATE INDEX idx_pm_property_type ON property_measurements (property_id, measurement_type)'
);

-- 2) calendar_stop_crew — single-column on stop_id
CALL mw_add_index_if_missing(
    'calendar_stop_crew',
    'idx_csc_stop',
    'CREATE INDEX idx_csc_stop ON calendar_stop_crew (stop_id)'
);

-- 3) crew_location_history — composite (crew_id, timestamp)
-- Covers dedup + "latest fix" + "history for user" queries.
CALL mw_add_index_if_missing(
    'crew_location_history',
    'idx_clh_crew_time',
    'CREATE INDEX idx_clh_crew_time ON crew_location_history (crew_id, timestamp)'
);

DROP PROCEDURE IF EXISTS mw_add_index_if_missing;

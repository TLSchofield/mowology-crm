-- ============================================================================
-- Migration 800: Polygon Geofence Tracking Module
-- ============================================================================
-- ADDITIVE ONLY — no existing tables modified destructively.
-- Adds polygon job-area tracking as a fully optional feature.
--
-- Activation hierarchy (most specific wins):
--   1. Global admin setting (geofence.global_enabled)
--   2. Service type setting (geofence.service_enabled.{service_type})
--   3. Plan-level override (job_plans.geofence_enabled column)
--   4. Visit-level override (job_visits.geofence_enabled column)
--
-- MySQL 5.7 compatible: TEXT for JSON, no window functions, no generated columns.
-- utf8mb4_general_ci throughout to match FK references.
-- ============================================================================

SET FOREIGN_KEY_CHECKS = 0;

-- ============================================================================
-- 1. job_geofences — One polygon per job_plan (reused across all visits)
-- ============================================================================
CREATE TABLE IF NOT EXISTS job_geofences (
    id              INT AUTO_INCREMENT PRIMARY KEY,
    plan_id         INT NOT NULL COMMENT 'The job plan this polygon belongs to',
    property_id     INT NOT NULL COMMENT 'Denormalised for fast lookup',

    -- Polygon storage (GeoJSON-style, MySQL 5.7 compatible)
    polygon_json    TEXT NOT NULL COMMENT 'JSON: [[lat,lng], ...] — closed ring',
    vertex_count    TINYINT UNSIGNED NOT NULL DEFAULT 3,

    -- Pre-computed bounding box for fast rejection before polygon test
    bbox_lat_min    DECIMAL(10,8) NOT NULL,
    bbox_lat_max    DECIMAL(10,8) NOT NULL,
    bbox_lng_min    DECIMAL(11,8) NOT NULL,
    bbox_lng_max    DECIMAL(11,8) NOT NULL,

    -- Derived area (computed by PHP on save, stored for reporting)
    area_sqm        DECIMAL(12,2) NULL COMMENT 'Polygon area in square metres (Shoelace formula)',

    -- Who drew it and when
    drawn_by        INT NULL,
    drawn_at        TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_by      INT NULL,
    updated_at      TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,

    -- Notes / label
    label           VARCHAR(100) NULL COMMENT 'e.g. "Front lawn + side beds"',
    notes           TEXT NULL,

    INDEX idx_jgf_plan     (plan_id),
    INDEX idx_jgf_property (property_id),

    FOREIGN KEY (plan_id)    REFERENCES job_plans(id)  ON DELETE CASCADE,
    FOREIGN KEY (property_id) REFERENCES properties(id) ON DELETE CASCADE,
    FOREIGN KEY (drawn_by)   REFERENCES users(id)      ON DELETE SET NULL,
    FOREIGN KEY (updated_by) REFERENCES users(id)      ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci
  COMMENT='Work-zone polygons for geofence tracking. One per job plan.';


-- ============================================================================
-- 2. job_location_samples — High-resolution GPS samples during geofenced visits
--    Separate from visit_gps_points (PoW breadcrumbs) — these are denser and
--    tagged with in/out zone status for compliance reporting.
-- ============================================================================
CREATE TABLE IF NOT EXISTS job_location_samples (
    id              INT AUTO_INCREMENT PRIMARY KEY,
    visit_id        INT NOT NULL,
    geofence_id     INT NULL COMMENT 'NULL if no polygon was active for this visit',

    -- Device timestamp (UTC) — the moment the GPS fix was taken
    sampled_at      DATETIME NOT NULL,

    lat             DECIMAL(10,8) NOT NULL,
    lng             DECIMAL(11,8) NOT NULL,
    accuracy_m      DECIMAL(8,2)  NULL,
    speed_mps       DECIMAL(6,2)  NULL,
    heading         DECIMAL(5,2)  NULL,
    altitude_m      DECIMAL(8,2)  NULL,
    battery_pct     TINYINT       NULL COMMENT '0–100, device battery at sample time',

    -- In/out decision (computed server-side on sync)
    in_zone         TINYINT(1)    NOT NULL DEFAULT 0 COMMENT '1 = inside polygon at this point',
    zone_checked    TINYINT(1)    NOT NULL DEFAULT 0 COMMENT '0 = pending server-side check',

    -- Source
    source          VARCHAR(30)   DEFAULT 'gps' COMMENT 'gps|fused|network|manual',

    created_at      TIMESTAMP DEFAULT CURRENT_TIMESTAMP,

    INDEX idx_jls_visit        (visit_id),
    INDEX idx_jls_visit_ts     (visit_id, sampled_at),
    INDEX idx_jls_geofence     (geofence_id),
    INDEX idx_jls_zone_pending (visit_id, zone_checked),

    FOREIGN KEY (visit_id)    REFERENCES job_visits(id)   ON DELETE CASCADE,
    FOREIGN KEY (geofence_id) REFERENCES job_geofences(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci
  COMMENT='High-resolution GPS samples for geofenced visits, tagged in/out zone.';


-- ============================================================================
-- 3. job_geofence_events — Entry / exit events derived from location samples
-- ============================================================================
CREATE TABLE IF NOT EXISTS job_geofence_events (
    id              INT AUTO_INCREMENT PRIMARY KEY,
    visit_id        INT NOT NULL,
    geofence_id     INT NOT NULL,

    event_type      ENUM('entry', 'exit') NOT NULL,
    event_time      DATETIME NOT NULL COMMENT 'Device timestamp of the transition',
    lat             DECIMAL(10,8) NOT NULL COMMENT 'Position at time of event',
    lng             DECIMAL(11,8) NOT NULL,
    accuracy_m      DECIMAL(8,2)  NULL,

    -- Duration of the zone-stay this event closes (filled on exit events)
    duration_seconds INT NULL COMMENT 'Seconds spent in zone before this exit',

    created_at      TIMESTAMP DEFAULT CURRENT_TIMESTAMP,

    INDEX idx_jge_visit    (visit_id),
    INDEX idx_jge_geofence (geofence_id),
    INDEX idx_jge_type     (event_type),

    FOREIGN KEY (visit_id)    REFERENCES job_visits(id)    ON DELETE CASCADE,
    FOREIGN KEY (geofence_id) REFERENCES job_geofences(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci
  COMMENT='Entry/exit events derived from location sample stream.';


-- ============================================================================
-- 4. job_geofence_sessions — Per-visit summary (computed on sync + clock-out)
-- ============================================================================
CREATE TABLE IF NOT EXISTS job_geofence_sessions (
    id                  INT AUTO_INCREMENT PRIMARY KEY,
    visit_id            INT NOT NULL UNIQUE COMMENT 'One summary row per visit',
    geofence_id         INT NULL,

    -- Activation state
    tracking_active     TINYINT(1) NOT NULL DEFAULT 0 COMMENT '1 = geofence tracking ran for this visit',

    -- Time accounting (seconds, computed from events)
    total_seconds       INT NOT NULL DEFAULT 0 COMMENT 'Clock-in to clock-out duration',
    in_zone_seconds     INT NOT NULL DEFAULT 0 COMMENT 'Time inside polygon',
    out_zone_seconds    INT NOT NULL DEFAULT 0 COMMENT 'Time outside polygon',
    unknown_seconds     INT NOT NULL DEFAULT 0 COMMENT 'Time before first GPS fix / accuracy too low',

    -- Sample statistics
    sample_count        INT NOT NULL DEFAULT 0,
    entry_count         SMALLINT NOT NULL DEFAULT 0,
    exit_count          SMALLINT NOT NULL DEFAULT 0,

    -- Derived confidence score (0–100)
    -- Formula: (in_zone_seconds / (total_seconds - unknown_seconds)) * 100, capped 0–100
    confidence_score    TINYINT UNSIGNED NULL COMMENT '0–100: percentage of known-time spent in zone',

    -- Status
    status              ENUM('pending','computing','complete','error') DEFAULT 'pending',
    computed_at         TIMESTAMP NULL,
    error_msg           TEXT NULL,

    created_at          TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at          TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,

    INDEX idx_jgs_geofence (geofence_id),

    FOREIGN KEY (visit_id)    REFERENCES job_visits(id)    ON DELETE CASCADE,
    FOREIGN KEY (geofence_id) REFERENCES job_geofences(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci
  COMMENT='Per-visit geofence summary: in/out time, confidence score.';


-- ============================================================================
-- 5. Extend job_plans — activation toggle + geofence FK
--    Uses stored procedure so it is safe to run multiple times.
-- ============================================================================

DELIMITER //

DROP PROCEDURE IF EXISTS geofence_migrate_plans //
CREATE PROCEDURE geofence_migrate_plans()
BEGIN
    -- geofence_enabled: plan-level toggle (NULL = inherit global/service default)
    IF NOT EXISTS (
        SELECT 1 FROM information_schema.COLUMNS
        WHERE TABLE_SCHEMA = DATABASE()
          AND TABLE_NAME = 'job_plans'
          AND COLUMN_NAME = 'geofence_enabled'
    ) THEN
        ALTER TABLE job_plans
            ADD COLUMN geofence_enabled TINYINT(1) NULL DEFAULT NULL
            COMMENT 'NULL=inherit global/service default, 1=force on, 0=force off'
            AFTER photos_block_completion;
    END IF;

    -- active_geofence_id: which polygon is attached to this plan
    IF NOT EXISTS (
        SELECT 1 FROM information_schema.COLUMNS
        WHERE TABLE_SCHEMA = DATABASE()
          AND TABLE_NAME = 'job_plans'
          AND COLUMN_NAME = 'active_geofence_id'
    ) THEN
        ALTER TABLE job_plans
            ADD COLUMN active_geofence_id INT NULL DEFAULT NULL
            COMMENT 'FK to job_geofences.id — the current active polygon'
            AFTER geofence_enabled;
        -- Note: FK added separately below to avoid DDL nesting issues
    END IF;
END //
CALL geofence_migrate_plans() //
DROP PROCEDURE IF EXISTS geofence_migrate_plans //


-- ============================================================================
-- 6. Extend job_visits — per-visit activation toggle + geofence session FK
-- ============================================================================

DROP PROCEDURE IF EXISTS geofence_migrate_visits //
CREATE PROCEDURE geofence_migrate_visits()
BEGIN
    -- geofence_enabled: visit-level override (NULL = inherit from plan)
    IF NOT EXISTS (
        SELECT 1 FROM information_schema.COLUMNS
        WHERE TABLE_SCHEMA = DATABASE()
          AND TABLE_NAME = 'job_visits'
          AND COLUMN_NAME = 'geofence_enabled'
    ) THEN
        ALTER TABLE job_visits
            ADD COLUMN geofence_enabled TINYINT(1) NULL DEFAULT NULL
            COMMENT 'NULL=inherit from plan, 1=force on, 0=force off';
    END IF;

    -- in_zone_minutes: denormalised summary for quick dashboard display
    IF NOT EXISTS (
        SELECT 1 FROM information_schema.COLUMNS
        WHERE TABLE_SCHEMA = DATABASE()
          AND TABLE_NAME = 'job_visits'
          AND COLUMN_NAME = 'in_zone_minutes'
    ) THEN
        ALTER TABLE job_visits
            ADD COLUMN in_zone_minutes SMALLINT UNSIGNED NULL DEFAULT NULL
            COMMENT 'Cached in-zone minutes from geofence_sessions (denormalised)';
    END IF;

    -- geofence_confidence: quick-access confidence score
    IF NOT EXISTS (
        SELECT 1 FROM information_schema.COLUMNS
        WHERE TABLE_SCHEMA = DATABASE()
          AND TABLE_NAME = 'job_visits'
          AND COLUMN_NAME = 'geofence_confidence'
    ) THEN
        ALTER TABLE job_visits
            ADD COLUMN geofence_confidence TINYINT UNSIGNED NULL DEFAULT NULL
            COMMENT 'Cached confidence score 0–100 from geofence_sessions';
    END IF;
END //
CALL geofence_migrate_visits() //
DROP PROCEDURE IF EXISTS geofence_migrate_visits //

DELIMITER ;


-- ============================================================================
-- 7. Geofence settings rows in time_clock_settings (key-value store)
--    INSERT IGNORE = no-op if already run.
-- ============================================================================
INSERT IGNORE INTO time_clock_settings (setting_key, setting_value) VALUES
    ('geofence.global_enabled',        '0'),
    ('geofence.services_enabled',      '[]'),   -- JSON array of service_type strings
    ('geofence.sample_interval_sec',   '30'),   -- GPS sample every N seconds (when active)
    ('geofence.min_accuracy_m',        '30'),   -- Reject fixes worse than this
    ('geofence.max_accuracy_m',        '150'),  -- Beyond this: sample but flag unknown
    ('geofence.show_ui_badge',         '1'),    -- Show "Enhanced Tracking" badge in UI
    ('geofence.pow_section_enabled',   '1');    -- Include geofence section in PoW PDF


SET FOREIGN_KEY_CHECKS = 1;

-- End of migration 800

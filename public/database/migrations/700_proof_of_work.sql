-- ============================================================================
-- Migration 700: Proof of Work System
-- ============================================================================
-- Extends job_visits with full PoW fields (GPS track, PDF, locking, materials,
-- service-specific sections, audit log) and creates supporting tables.
--
-- MySQL 5.7 compatible: TEXT for JSON storage, no window functions,
-- no generated columns. utf8mb4_general_ci throughout.
-- ============================================================================

SET FOREIGN_KEY_CHECKS = 0;

-- ============================================================================
-- 1. visit_gps_points — GPS breadcrumb trail for each visit
-- ============================================================================
CREATE TABLE IF NOT EXISTS visit_gps_points (
    id          INT AUTO_INCREMENT PRIMARY KEY,
    visit_id    INT NOT NULL,
    ts          DATETIME NOT NULL COMMENT 'Device timestamp (UTC)',
    lat         DECIMAL(10,8) NOT NULL,
    lng         DECIMAL(11,8) NOT NULL,
    accuracy_m  DECIMAL(8,2) NULL COMMENT 'Horizontal accuracy in metres',
    speed_mps   DECIMAL(6,2) NULL COMMENT 'Speed in m/s (null if unavailable)',
    heading     DECIMAL(5,2) NULL COMMENT 'Bearing 0–360 (null if unavailable)',
    altitude_m  DECIMAL(8,2) NULL,
    source      VARCHAR(30) DEFAULT 'gps' COMMENT 'gps|fused|network|manual',
    created_at  TIMESTAMP DEFAULT CURRENT_TIMESTAMP,

    INDEX idx_vgp_visit     (visit_id),
    INDEX idx_vgp_visit_ts  (visit_id, ts),

    FOREIGN KEY (visit_id) REFERENCES job_visits(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- ============================================================================
-- 2. visit_audit_log — Immutable record of every PoW action
-- ============================================================================
CREATE TABLE IF NOT EXISTS visit_audit_log (
    id           INT AUTO_INCREMENT PRIMARY KEY,
    visit_id     INT NOT NULL,
    user_id      INT NOT NULL,
    action       VARCHAR(60) NOT NULL
                 COMMENT 'start|complete|lock|unlock|generate_pdf|regenerate_pdf|email_sent|photo_upload|checklist_saved',
    payload_json TEXT NULL COMMENT 'JSON blob with before/after values or metadata',
    ip_address   VARCHAR(45) NULL,
    created_at   TIMESTAMP DEFAULT CURRENT_TIMESTAMP,

    INDEX idx_val_visit   (visit_id),
    INDEX idx_val_action  (action),
    INDEX idx_val_user    (user_id),
    INDEX idx_val_created (created_at),

    FOREIGN KEY (visit_id) REFERENCES job_visits(id) ON DELETE CASCADE,
    FOREIGN KEY (user_id)  REFERENCES users(id)      ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- ============================================================================
-- 3. pow_email_log — Record every "Email PoW" action
-- ============================================================================
CREATE TABLE IF NOT EXISTS pow_email_log (
    id          INT AUTO_INCREMENT PRIMARY KEY,
    visit_id    INT NOT NULL,
    sent_by     INT NOT NULL,
    recipient   VARCHAR(255) NOT NULL,
    sent_at     TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    status      ENUM('sent','failed') DEFAULT 'sent',
    error_msg   TEXT NULL,

    INDEX idx_pel_visit (visit_id),
    FOREIGN KEY (visit_id) REFERENCES job_visits(id) ON DELETE CASCADE,
    FOREIGN KEY (sent_by)  REFERENCES users(id)      ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- ============================================================================
-- 4. Extend job_visits with PoW fields (safe — uses IF NOT EXISTS pattern)
-- ============================================================================

DELIMITER //

DROP PROCEDURE IF EXISTS pow_migrate_visits//

CREATE PROCEDURE pow_migrate_visits()
BEGIN
    -- service_type (copy from plan at visit creation)
    IF NOT EXISTS (SELECT 1 FROM information_schema.COLUMNS
        WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'job_visits'
        AND COLUMN_NAME = 'service_type') THEN
        ALTER TABLE job_visits ADD COLUMN service_type VARCHAR(100) NULL
            COMMENT 'fertilizer|salt|snow|general|etc — copied from plan';
    END IF;

    -- materials_json — structured materials used
    IF NOT EXISTS (SELECT 1 FROM information_schema.COLUMNS
        WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'job_visits'
        AND COLUMN_NAME = 'materials_json') THEN
        ALTER TABLE job_visits ADD COLUMN materials_json TEXT NULL
            COMMENT 'JSON: [{"name":"Winterizer 26-0-6","qty":2.5,"unit":"kg","rate_per_unit":18.00}]';
    END IF;

    -- checklist_json — the template-driven checklist (separate from old checklist_completed)
    IF NOT EXISTS (SELECT 1 FROM information_schema.COLUMNS
        WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'job_visits'
        AND COLUMN_NAME = 'checklist_json') THEN
        ALTER TABLE job_visits ADD COLUMN checklist_json TEXT NULL
            COMMENT 'JSON: [{"item":"Inspect turf","checked":true,"note":""}]';
    END IF;

    -- service_data_json — service-type-specific fields
    IF NOT EXISTS (SELECT 1 FROM information_schema.COLUMNS
        WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'job_visits'
        AND COLUMN_NAME = 'service_data_json') THEN
        ALTER TABLE job_visits ADD COLUMN service_data_json TEXT NULL
            COMMENT 'JSON: fertilizer={product,rate,area,weather_note}; salt={qty_kg,area,temp_c,priority_zones}; snow={depth_cm,equipment,hazards}';
    END IF;

    -- signature_path
    IF NOT EXISTS (SELECT 1 FROM information_schema.COLUMNS
        WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'job_visits'
        AND COLUMN_NAME = 'signature_path') THEN
        ALTER TABLE job_visits ADD COLUMN signature_path VARCHAR(255) NULL
            COMMENT 'Relative path to client signature PNG';
    END IF;

    -- pdf_path
    IF NOT EXISTS (SELECT 1 FROM information_schema.COLUMNS
        WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'job_visits'
        AND COLUMN_NAME = 'pdf_path') THEN
        ALTER TABLE job_visits ADD COLUMN pdf_path VARCHAR(255) NULL
            COMMENT 'Relative path to generated PoW PDF';
    END IF;

    -- pdf_generated_at
    IF NOT EXISTS (SELECT 1 FROM information_schema.COLUMNS
        WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'job_visits'
        AND COLUMN_NAME = 'pdf_generated_at') THEN
        ALTER TABLE job_visits ADD COLUMN pdf_generated_at TIMESTAMP NULL;
    END IF;

    -- map_snapshot_path
    IF NOT EXISTS (SELECT 1 FROM information_schema.COLUMNS
        WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'job_visits'
        AND COLUMN_NAME = 'map_snapshot_path') THEN
        ALTER TABLE job_visits ADD COLUMN map_snapshot_path VARCHAR(255) NULL
            COMMENT 'Relative path to cached static map PNG';
    END IF;

    -- map_snapshot_hash — invalidation hash based on point count+bbox
    IF NOT EXISTS (SELECT 1 FROM information_schema.COLUMNS
        WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'job_visits'
        AND COLUMN_NAME = 'map_snapshot_hash') THEN
        ALTER TABLE job_visits ADD COLUMN map_snapshot_hash VARCHAR(64) NULL;
    END IF;

    -- locked_at — success-state lock
    IF NOT EXISTS (SELECT 1 FROM information_schema.COLUMNS
        WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'job_visits'
        AND COLUMN_NAME = 'locked_at') THEN
        ALTER TABLE job_visits ADD COLUMN locked_at TIMESTAMP NULL
            COMMENT 'Non-null = PoW locked; edits require admin Unlock action';
    END IF;

    -- locked_by
    IF NOT EXISTS (SELECT 1 FROM information_schema.COLUMNS
        WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'job_visits'
        AND COLUMN_NAME = 'locked_by') THEN
        ALTER TABLE job_visits ADD COLUMN locked_by INT NULL;
    END IF;

    -- distance_m — computed from GPS track (haversine sum)
    IF NOT EXISTS (SELECT 1 FROM information_schema.COLUMNS
        WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'job_visits'
        AND COLUMN_NAME = 'distance_m') THEN
        ALTER TABLE job_visits ADD COLUMN distance_m INT NULL
            COMMENT 'Total distance walked in metres (computed on PDF generation)';
    END IF;

    -- gps_points_count
    IF NOT EXISTS (SELECT 1 FROM information_schema.COLUMNS
        WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'job_visits'
        AND COLUMN_NAME = 'gps_points_count') THEN
        ALTER TABLE job_visits ADD COLUMN gps_points_count INT DEFAULT 0;
    END IF;

    -- confidence_box_json — Client Confidence Box
    IF NOT EXISTS (SELECT 1 FROM information_schema.COLUMNS
        WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'job_visits'
        AND COLUMN_NAME = 'confidence_box_json') THEN
        ALTER TABLE job_visits ADD COLUMN confidence_box_json TEXT NULL
            COMMENT 'JSON: {done:[],next:[],upsell:""}';
    END IF;

    -- Add FK for locked_by if not exists
    -- (soft reference — no FK to avoid complications if user deleted)
END//

DELIMITER ;

CALL pow_migrate_visits();
DROP PROCEDURE IF EXISTS pow_migrate_visits;

-- ============================================================================
-- 5. Add index on job_visits.locked_at for admin queries
-- ============================================================================
-- MySQL 5.7: CREATE INDEX IF NOT EXISTS not supported, so guard with procedure
DELIMITER //
CREATE PROCEDURE pow_add_visit_indexes()
BEGIN
    IF NOT EXISTS (SELECT 1 FROM information_schema.STATISTICS
        WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'job_visits'
        AND INDEX_NAME = 'idx_jv_locked') THEN
        ALTER TABLE job_visits ADD INDEX idx_jv_locked (locked_at);
    END IF;
    IF NOT EXISTS (SELECT 1 FROM information_schema.STATISTICS
        WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'job_visits'
        AND INDEX_NAME = 'idx_jv_service_type') THEN
        ALTER TABLE job_visits ADD INDEX idx_jv_service_type (service_type);
    END IF;
END//
DELIMITER ;
CALL pow_add_visit_indexes();
DROP PROCEDURE IF EXISTS pow_add_visit_indexes;

SET FOREIGN_KEY_CHECKS = 1;

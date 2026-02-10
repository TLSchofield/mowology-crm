/**
 * Migration: Create GSC Sync History Table
 * Purpose: Track all GSC data pulls to monitor sync history
 * Version: 110
 */

-- Create GSC sync history table
CREATE TABLE IF NOT EXISTS gsc_sync_history (
    id INT AUTO_INCREMENT PRIMARY KEY,
    property_id INT NOT NULL,
    sync_type ENUM('manual', 'cron', 'api') NOT NULL DEFAULT 'cron',
    status ENUM('pending', 'success', 'failed', 'partial') NOT NULL DEFAULT 'pending',
    rows_processed INT DEFAULT 0,
    rows_inserted INT DEFAULT 0,
    rows_updated INT DEFAULT 0,
    error_message TEXT,
    started_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    completed_at TIMESTAMP NULL,
    initiated_by_user_id INT,
    notes TEXT,

    FOREIGN KEY (property_id) REFERENCES gsc_properties(id) ON DELETE CASCADE,
    FOREIGN KEY (initiated_by_user_id) REFERENCES users(id) ON DELETE SET NULL,

    INDEX idx_property_date (property_id, started_at DESC),
    INDEX idx_status (status),
    INDEX idx_sync_type (sync_type)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Add column to gsc_snapshots to link to sync history
ALTER TABLE gsc_snapshots ADD COLUMN sync_history_id INT AFTER property_id;
ALTER TABLE gsc_snapshots ADD FOREIGN KEY (sync_history_id) REFERENCES gsc_sync_history(id) ON DELETE SET NULL;

-- Create view to calculate duration for reporting (MySQL 5.7 compatible)
CREATE OR REPLACE VIEW gsc_sync_history_with_duration AS
SELECT
    gsh.*,
    TIMESTAMPDIFF(SECOND, gsh.started_at, COALESCE(gsh.completed_at, NOW())) as duration_seconds
FROM gsc_sync_history gsh;

-- Migration 1044: Add cluster references to existing tables
-- ALTER TABLE only — run after 1043_property_clusters.sql

-- calendar_stops: which cluster this stop belongs to (NULL = solo stop)
ALTER TABLE calendar_stops
    ADD COLUMN cluster_id INT NULL AFTER notes,
    ADD INDEX idx_cluster (cluster_id),
    ADD FOREIGN KEY fk_cs_cluster (cluster_id) REFERENCES property_clusters(id) ON DELETE SET NULL;

-- job_time_entries: link back to the cluster session that generated this entry,
-- and track how the duration was sourced so the UI can show a breakdown.
ALTER TABLE job_time_entries
    ADD COLUMN cluster_session_id INT NULL AFTER visit_id,
    ADD COLUMN time_source ENUM('gps','manual','cluster_apportioned','travel_apportioned','photo_inferred','estimated') NOT NULL DEFAULT 'gps' AFTER auto_started,
    ADD INDEX idx_cluster_session (cluster_session_id),
    ADD FOREIGN KEY fk_jte_cluster_session (cluster_session_id) REFERENCES cluster_sessions(id) ON DELETE SET NULL;

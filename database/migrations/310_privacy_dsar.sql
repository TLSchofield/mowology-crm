-- ============================================================
-- Migration 310: PIPEDA Privacy Compliance
-- ============================================================
-- Adds DSAR (data subject access request) tracking and
-- configurable data retention policies.
-- Run via: /crm/api/run-migration-310.php

-- Privacy / DSAR requests (anyone can submit; contact_id may be NULL)
CREATE TABLE IF NOT EXISTS privacy_requests (
    id              INT AUTO_INCREMENT PRIMARY KEY,
    contact_id      INT NULL,
    request_type    ENUM('access','deletion','correction') NOT NULL,
    status          ENUM('pending','in_progress','completed','rejected') NOT NULL DEFAULT 'pending',
    requester_name  VARCHAR(255) NOT NULL,
    requester_email VARCHAR(255) NOT NULL,
    notes           TEXT NULL,
    handled_by      INT NULL,
    completed_at    TIMESTAMP NULL,
    export_path     VARCHAR(500) NULL,
    created_at      TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at      TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,

    INDEX idx_pr_status  (status),
    INDEX idx_pr_contact (contact_id),
    INDEX idx_pr_email   (requester_email),
    INDEX idx_pr_created (created_at),

    FOREIGN KEY (contact_id) REFERENCES contacts(id) ON DELETE SET NULL,
    FOREIGN KEY (handled_by) REFERENCES users(id)    ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- Configurable data retention policies per data type
CREATE TABLE IF NOT EXISTS data_retention_settings (
    id             INT AUTO_INCREMENT PRIMARY KEY,
    data_type      VARCHAR(50)  NOT NULL,
    retention_days INT          NOT NULL,
    description    TEXT         NULL,
    updated_by     INT          NULL,
    updated_at     TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,

    UNIQUE KEY uq_data_type (data_type),
    FOREIGN KEY (updated_by) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- Seed default retention periods
INSERT IGNORE INTO data_retention_settings (data_type, retention_days, description) VALUES
    ('crew_location_history', 90,   'Raw GPS location pings for crew members (data minimisation)'),
    ('visit_gps_points',      180,  'GPS breadcrumb trail recorded during job visits'),
    ('visit_audit_log',       730,  'Immutable audit log of all visit actions (2-year legal hold)');

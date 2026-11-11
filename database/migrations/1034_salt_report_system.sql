-- ============================================================================
-- Migration 1034: Winter Service (Salt/Snow) Report System
-- ============================================================================
-- Purpose: Adds infrastructure for legally defensible proof-of-work documents
--          covering salt application and snow removal visits. Each visit
--          generates a PDF containing an authoritative weather snapshot, GPS
--          breadcrumb map, photos, and a chain-of-custody log.
--
-- Adds:
--   1. salt_application service type (alongside existing snow_removal)
--   2. salt_weather_decisions — immutable 2pm weather go-decision record
--   3. salt_run_reports       — report lifecycle (PDF path, delivery status)
--   4. invoice_attachments    — generic invoice→document linker
--
-- MySQL 5.7 compatible — no JSON functions, no window functions, no IF NOT EXISTS on ALTER
-- Run OUTSIDE a transaction (DDL auto-commits in MySQL)
-- ============================================================================

-- ----------------------------------------------------------------------------
-- 1. Add salt_application service type
-- ----------------------------------------------------------------------------
INSERT IGNORE INTO `service_types` (slug, label, color, icon, is_active, show_in_jobflow, sort_order)
VALUES ('salt_application', 'Salt Application', '#1565C0', 'droplet', 1, 0, 45);


-- ----------------------------------------------------------------------------
-- 2. salt_weather_decisions
-- Immutable record of the 2pm go-decision that authorized winter service.
-- Written by the weather_schedule_guard cron when the overnight low trigger
-- fires. Stores the full raw API response for legal non-repudiation.
-- ----------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `salt_weather_decisions` (
    `id`                  INT          NOT NULL AUTO_INCREMENT,
    `visit_id`            INT          NOT NULL,
    `property_id`         INT          NOT NULL,
    `decision_at`         DATETIME     NOT NULL COMMENT 'UTC timestamp when the cron captured this decision',
    `trigger_date`        DATE         NOT NULL COMMENT 'The overnight date being forecast (e.g. the Saturday night)',
    `overnight_low_c`     DECIMAL(5,2) NOT NULL COMMENT 'Forecast overnight low in Celsius',
    `trigger_threshold_c` DECIMAL(5,2) NOT NULL DEFAULT 0.00 COMMENT 'Property-specific or default threshold',
    `weather_condition`   VARCHAR(150) NULL COMMENT 'Human-readable condition e.g. "Partly cloudy with freezing drizzle"',
    `data_source`         VARCHAR(100) NULL COMMENT 'e.g. Environment Canada',
    `source_station`      VARCHAR(150) NULL COMMENT 'Weather station identifier / location',
    `source_url`          VARCHAR(500) NULL COMMENT 'Verbatim API URL used — included in PDF for verification',
    `raw_api_response`    LONGTEXT     NULL COMMENT 'Full JSON response body stored verbatim for legal evidence',
    `created_at`          TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    UNIQUE KEY `uq_visit_decision` (`visit_id`, `trigger_date`),
    KEY `idx_swd_property_date` (`property_id`, `trigger_date`),
    KEY `idx_swd_visit` (`visit_id`),
    CONSTRAINT `fk_swd_visit` FOREIGN KEY (`visit_id`) REFERENCES `job_visits` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci
COMMENT='Immutable weather go-decision record per winter-service visit';


-- ----------------------------------------------------------------------------
-- 3. salt_run_reports
-- Tracks the full lifecycle of each winter service report PDF.
-- One row per visit — covers snow removal and/or salt application.
-- ----------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `salt_run_reports` (
    `id`                  INT           NOT NULL AUTO_INCREMENT,
    `report_number`       VARCHAR(20)   NOT NULL COMMENT 'SAL-YYYY-NNNN series',
    `visit_id`            INT           NOT NULL,
    `decision_id`         INT           NULL COMMENT 'FK to salt_weather_decisions',

    -- Materials
    `salt_product`        VARCHAR(100)  NULL COMMENT 'Road Salt | Calcium Chloride | Mag Chloride | Ice Melt | Custom',
    `application_notes`   TEXT          NULL,

    -- PDF
    `pdf_path`            VARCHAR(500)  NULL COMMENT 'Relative path from STORAGE_ROOT',
    `pdf_hash`            VARCHAR(64)   NULL COMMENT 'SHA-256 of PDF file bytes for integrity verification',
    `pdf_generated_at`    DATETIME      NULL,
    `generated_by`        INT           NULL COMMENT 'FK to users (or NULL for auto-generation)',

    -- Portal delivery
    `portal_available_at` DATETIME      NULL COMMENT 'When the PDF became visible in the client portal',

    -- Invoice linkage
    `invoice_id`          INT           NULL,
    `invoice_attached_at` DATETIME      NULL,

    -- PM email delivery
    `pm_email_sent_at`    DATETIME      NULL,
    `pm_email_recipient`  VARCHAR(255)  NULL,
    `pm_email_status`     VARCHAR(20)   NULL COMMENT 'sent | failed | bounced',

    -- Timestamps
    `created_at`          TIMESTAMP     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at`          TIMESTAMP     NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,

    PRIMARY KEY (`id`),
    UNIQUE KEY `uq_srr_visit` (`visit_id`),
    UNIQUE KEY `uq_srr_report_number` (`report_number`),
    KEY `idx_srr_decision` (`decision_id`),
    KEY `idx_srr_invoice` (`invoice_id`),
    CONSTRAINT `fk_srr_visit`    FOREIGN KEY (`visit_id`)    REFERENCES `job_visits` (`id`) ON DELETE CASCADE,
    CONSTRAINT `fk_srr_decision` FOREIGN KEY (`decision_id`) REFERENCES `salt_weather_decisions` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci
COMMENT='Winter service (salt/snow) report lifecycle tracker — one row per visit';


-- ----------------------------------------------------------------------------
-- 4. invoice_attachments
-- Generic invoice → external PDF document linker.
-- Used initially for salt reports; designed to support PoW, inspection
-- reports, or any other document type attached to an invoice.
-- ----------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `invoice_attachments` (
    `id`            INT          NOT NULL AUTO_INCREMENT,
    `invoice_id`    INT          NOT NULL,
    `document_type` VARCHAR(50)  NOT NULL COMMENT 'salt_report | pow | inspection | custom',
    `document_id`   INT          NOT NULL COMMENT 'PK in the source table (e.g. salt_run_reports.id)',
    `pdf_path`      VARCHAR(500) NOT NULL COMMENT 'Relative path from STORAGE_ROOT',
    `label`         VARCHAR(255) NULL  COMMENT 'Human label shown in portal/invoice e.g. "Salt Application Record — Jan 15"',
    `attached_at`   DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `attached_by`   INT          NULL COMMENT 'FK to users',
    PRIMARY KEY (`id`),
    KEY `idx_ia_invoice` (`invoice_id`),
    CONSTRAINT `fk_ia_invoice` FOREIGN KEY (`invoice_id`) REFERENCES `invoices` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci
COMMENT='Generic invoice → external PDF document linker';

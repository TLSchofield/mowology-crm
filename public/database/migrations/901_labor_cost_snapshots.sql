-- Migration 901: Labor Cost Snapshots + Visit Margin Tracking
-- ─────────────────────────────────────────────────────────────
-- Adds columns to job_visits to snapshot labor cost at time of completion
-- so profitability remains accurate even after hourly rate changes.
-- Also creates visit_margin_snapshots for full per-visit margin records.

-- ── job_visits: labor snapshot columns ────────────────────────────────────────
ALTER TABLE job_visits
    ADD COLUMN labor_rate_snapshot  DECIMAL(8,2)  NULL
        COMMENT 'users.hourly_rate at time of completion'
        AFTER actual_duration_minutes,
    ADD COLUMN labor_cost_snapshot  DECIMAL(10,2) NULL
        COMMENT 'labor_rate_snapshot × (actual_duration_minutes / 60)'
        AFTER labor_rate_snapshot,
    ADD COLUMN drive_time_minutes   INT           NULL
        COMMENT 'Estimated drive time to this stop (from route optimizer)'
        AFTER labor_cost_snapshot,
    ADD COLUMN drive_cost_snapshot  DECIMAL(10,2) NULL
        COMMENT 'drive_time_minutes × cost_per_minute_driving at time of completion'
        AFTER drive_time_minutes,
    ADD COLUMN rollover_count       TINYINT       NOT NULL DEFAULT 0
        COMMENT 'How many times this visit rolled forward'
        AFTER drive_cost_snapshot,
    ADD COLUMN original_scheduled_date DATE        NULL
        COMMENT 'Original date before rollover'
        AFTER rollover_count;

-- ── visit_margin_snapshots: full per-visit margin record ──────────────────────
-- Computed on visit completion by VisitCompletionService.
-- One row per visit. Safe to re-compute (INSERT ... ON DUPLICATE KEY UPDATE).
CREATE TABLE IF NOT EXISTS visit_margin_snapshots (
    id               INT AUTO_INCREMENT PRIMARY KEY,
    visit_id         INT          NOT NULL,
    plan_id          INT          NULL,
    contact_id       INT          NULL,
    property_id      INT          NULL,
    visit_date       DATE         NULL,
    service_type     VARCHAR(60)  NULL,

    -- Revenue
    quoted_amount    DECIMAL(10,2) NULL COMMENT 'invoice line amount for this visit',

    -- Costs
    labor_cost       DECIMAL(10,2) NULL COMMENT 'labor_rate × actual_minutes/60',
    material_cost    DECIMAL(10,2) NULL COMMENT 'sum of expense line items for this visit',
    drive_cost       DECIMAL(10,2) NULL COMMENT 'drive_time × cost/min',
    other_cost       DECIMAL(10,2) NULL COMMENT 'any other attributed costs',

    -- Margin
    gross_margin     DECIMAL(10,2) NULL COMMENT 'quoted - labor - material - drive - other',
    margin_pct       DECIMAL(5,2)  NULL COMMENT 'gross_margin / quoted_amount * 100',

    -- Meta
    computed_at      TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    notes            VARCHAR(255) NULL,

    UNIQUE KEY uk_visit (visit_id),
    INDEX idx_visit_date    (visit_date),
    INDEX idx_contact       (contact_id),
    INDEX idx_service_type  (service_type),
    INDEX idx_margin_pct    (margin_pct)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- ── ops_settings: drive cost rate ────────────────────────────────────────────
-- Cost per minute driving (vehicle + fuel estimate). $0.75/min ≈ $45/hr.
INSERT INTO ops_settings (setting_key, setting_value, description)
VALUES ('drive_cost_per_minute', '0.75', 'Vehicle cost per driving minute (fuel + depreciation) in dollars')
ON DUPLICATE KEY UPDATE setting_key = setting_key;

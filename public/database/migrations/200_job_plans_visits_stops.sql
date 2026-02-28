-- ============================================================================
-- Migration 200: Job Plans / Visits / Calendar Stops
-- ============================================================================
-- Replaces the single `jobs` table with a three-table model:
--   job_plans     — service agreement / contract (what the client bought)
--   calendar_stops — one card per property per day per crew (routing/drag-drop)
--   job_visits     — one occurrence of work (reality + attachments)
-- Plus: visit_notes, visit_photos, plan_notes
--
-- MySQL 5.7 compatible: TEXT instead of JSON, no window functions, no generated columns
-- All tables use utf8mb4_general_ci to match existing FK references
-- ============================================================================

SET FOREIGN_KEY_CHECKS = 0;

-- ============================================================================
-- 1. job_plans — The service agreement / contract
-- ============================================================================
CREATE TABLE IF NOT EXISTS job_plans (
    id INT AUTO_INCREMENT PRIMARY KEY,
    plan_number VARCHAR(50) NOT NULL UNIQUE COMMENT 'PLN-YYYY-NNNN',

    -- Relationships
    quote_id INT NULL,
    property_id INT NOT NULL,
    company_id INT NOT NULL,

    -- What
    title VARCHAR(255) NOT NULL,
    description TEXT NULL,
    service_type VARCHAR(100) NOT NULL,
    service_package_id INT NULL,
    billing_template_id INT NULL,

    -- Pricing
    pricing_model ENUM('per_visit', 'monthly_flat', 'seasonal', 'custom') DEFAULT 'per_visit',
    price_per_visit DECIMAL(10,2) NULL,
    monthly_flat_price DECIMAL(10,2) NULL,
    seasonal_price DECIMAL(10,2) NULL,
    estimated_amount DECIMAL(10,2) NULL COMMENT 'Legacy compat: total estimated value',

    -- Proof-of-work template (copied from service_package at creation)
    checklist_template TEXT NULL COMMENT 'JSON array: ["Mow front", "Mow back", "Edge", "Blow"]',
    photo_types_required TEXT NULL COMMENT 'JSON array: ["before", "after"]',
    gps_enforcement VARCHAR(20) DEFAULT 'optional' COMMENT 'optional|required|disabled',
    checklist_blocks_completion TINYINT(1) DEFAULT 0,
    photos_block_completion TINYINT(1) DEFAULT 0,

    -- Recurrence rules
    is_recurring TINYINT(1) DEFAULT 0,
    recurrence_pattern ENUM('weekly', 'biweekly', 'monthly', 'custom') NULL,
    recurrence_interval INT DEFAULT 1 COMMENT 'Every X units (e.g., 2 for every-2-weeks)',
    recurrence_interval_unit ENUM('days', 'weeks', 'months') DEFAULT 'weeks',
    recurrence_day_of_week TINYINT NULL COMMENT '0=Sunday .. 6=Saturday',
    plan_start_date DATE NULL,
    plan_end_date DATE NULL COMMENT 'NULL = ongoing until cancelled',
    season_start VARCHAR(5) NULL COMMENT 'MM-DD recurring season start (e.g., 04-01)',
    season_end VARCHAR(5) NULL COMMENT 'MM-DD recurring season end (e.g., 10-31)',
    blackout_dates TEXT NULL COMMENT 'JSON array: ["2026-07-01", "2026-12-25"]',

    -- Crew defaults
    default_crew_id INT NULL,
    default_crew_size INT DEFAULT 1,
    estimated_duration_minutes INT DEFAULT 60,
    default_time_start TIME NULL,
    default_time_end TIME NULL,

    -- Visit generation control
    horizon_days INT DEFAULT 28 COMMENT 'Generate visits this many days ahead',
    visits_generated_through DATE NULL COMMENT 'Last date visits were generated to',

    -- Status
    status ENUM('active', 'paused', 'cancelled', 'completed') DEFAULT 'active',
    status_changed_at TIMESTAMP NULL,
    paused_at TIMESTAMP NULL,
    paused_reason TEXT NULL,

    -- Audit
    created_by INT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,

    -- Indexes
    INDEX idx_plan_number (plan_number),
    INDEX idx_quote (quote_id),
    INDEX idx_property (property_id),
    INDEX idx_company (company_id),
    INDEX idx_status (status),
    INDEX idx_service_type (service_type),
    INDEX idx_service_package (service_package_id),
    INDEX idx_recurring (is_recurring),
    INDEX idx_start_date (plan_start_date),
    INDEX idx_default_crew (default_crew_id),

    -- Foreign keys
    FOREIGN KEY (quote_id) REFERENCES quotes(id) ON DELETE SET NULL,
    FOREIGN KEY (property_id) REFERENCES properties(id) ON DELETE CASCADE,
    FOREIGN KEY (company_id) REFERENCES companies(id) ON DELETE CASCADE,
    FOREIGN KEY (service_package_id) REFERENCES service_packages(id) ON DELETE SET NULL,
    FOREIGN KEY (billing_template_id) REFERENCES billing_templates(id) ON DELETE SET NULL,
    FOREIGN KEY (default_crew_id) REFERENCES users(id) ON DELETE SET NULL,
    FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;


-- ============================================================================
-- 2. calendar_stops — One card per property per day per crew
-- ============================================================================
CREATE TABLE IF NOT EXISTS calendar_stops (
    id INT AUTO_INCREMENT PRIMARY KEY,
    property_id INT NOT NULL,
    crew_id INT NULL COMMENT 'NULL = unassigned stop',
    stop_date DATE NOT NULL,
    route_order INT DEFAULT 0 COMMENT 'Drag-drop ordering within day+crew',
    estimated_arrival TIME NULL,
    estimated_departure TIME NULL,
    notes TEXT NULL,
    status ENUM('scheduled', 'en_route', 'in_progress', 'completed', 'skipped') DEFAULT 'scheduled',

    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,

    -- One stop per property per day per crew
    UNIQUE KEY uk_property_date_crew (property_id, stop_date, crew_id),
    INDEX idx_date (stop_date),
    INDEX idx_crew_date (crew_id, stop_date),
    INDEX idx_status (status),
    INDEX idx_route (stop_date, crew_id, route_order),

    FOREIGN KEY (property_id) REFERENCES properties(id) ON DELETE CASCADE,
    FOREIGN KEY (crew_id) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;


-- ============================================================================
-- 3. job_visits — One occurrence of work
-- ============================================================================
CREATE TABLE IF NOT EXISTS job_visits (
    id INT AUTO_INCREMENT PRIMARY KEY,
    visit_number VARCHAR(60) NOT NULL UNIQUE COMMENT 'PLN-2026-0001-V001',
    plan_id INT NOT NULL,
    stop_id INT NULL COMMENT 'Link to calendar_stops for routing/grouping',

    -- Schedule
    scheduled_date DATE NOT NULL,
    scheduled_time_start TIME NULL,
    scheduled_time_end TIME NULL,
    sequence_index INT DEFAULT 1 COMMENT 'Occurrence number within plan (1, 2, 3...)',

    -- Crew
    assigned_crew_id INT NULL,
    actual_crew_count INT NULL,

    -- Status
    status ENUM('scheduled', 'in_progress', 'completed', 'skipped', 'weather', 'cancelled') DEFAULT 'scheduled',
    status_changed_at TIMESTAMP NULL,
    started_at TIMESTAMP NULL,
    completed_at TIMESTAMP NULL,

    -- Completion data
    actual_duration_minutes INT NULL,
    completion_notes TEXT NULL,
    actual_amount DECIMAL(10,2) NULL,
    materials_used TEXT NULL COMMENT 'JSON: [{"name":"Fertilizer","qty":2,"cost":15.00}]',

    -- Invoice
    is_invoiced TINYINT(1) DEFAULT 0,
    invoice_id INT NULL,

    -- Proof of work (per-visit instance — template lives on job_plans)
    checklist_completed TEXT NULL COMMENT 'JSON: {"Mow front":true,"Edge":false}',
    checklist_completed_at TIMESTAMP NULL,
    checklist_completed_by INT NULL,
    gps_arrival_lat DECIMAL(10,8) NULL,
    gps_arrival_lng DECIMAL(11,8) NULL,
    gps_departure_lat DECIMAL(10,8) NULL,
    gps_departure_lng DECIMAL(11,8) NULL,
    gps_confirmed_at TIMESTAMP NULL,
    proof_complete TINYINT(1) DEFAULT 0,

    -- Audit
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,

    -- Dedup: one visit per plan per date per sequence
    UNIQUE KEY uk_plan_date_seq (plan_id, scheduled_date, sequence_index),
    INDEX idx_plan (plan_id),
    INDEX idx_stop (stop_id),
    INDEX idx_date (scheduled_date),
    INDEX idx_status (status),
    INDEX idx_crew (assigned_crew_id),
    INDEX idx_invoice (invoice_id),
    INDEX idx_visit_number (visit_number),
    INDEX idx_invoiced (is_invoiced),

    FOREIGN KEY (plan_id) REFERENCES job_plans(id) ON DELETE CASCADE,
    FOREIGN KEY (stop_id) REFERENCES calendar_stops(id) ON DELETE SET NULL,
    FOREIGN KEY (assigned_crew_id) REFERENCES users(id) ON DELETE SET NULL,
    FOREIGN KEY (invoice_id) REFERENCES invoices(id) ON DELETE SET NULL,
    FOREIGN KEY (checklist_completed_by) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;


-- ============================================================================
-- 4. visit_notes — Per-visit notes (replaces job_notes)
-- ============================================================================
CREATE TABLE IF NOT EXISTS visit_notes (
    id INT AUTO_INCREMENT PRIMARY KEY,
    visit_id INT NOT NULL,
    note_type ENUM('general', 'customer_request', 'issue', 'follow_up', 'internal') DEFAULT 'general',
    content TEXT NOT NULL,
    is_visible_to_customer TINYINT(1) DEFAULT 0,
    created_by INT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,

    INDEX idx_visit (visit_id),
    INDEX idx_created (created_at),
    FOREIGN KEY (visit_id) REFERENCES job_visits(id) ON DELETE CASCADE,
    FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;


-- ============================================================================
-- 5. visit_photos — Per-visit photos (replaces job_photos)
-- ============================================================================
CREATE TABLE IF NOT EXISTS visit_photos (
    id INT AUTO_INCREMENT PRIMARY KEY,
    visit_id INT NOT NULL,
    photo_type ENUM('before', 'during', 'after', 'issue', 'other') DEFAULT 'after',
    filename VARCHAR(255) NOT NULL,
    original_filename VARCHAR(255) NULL,
    file_size INT NULL,
    mime_type VARCHAR(100) NULL,
    caption TEXT NULL,
    uploaded_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    uploaded_by INT NULL,

    INDEX idx_visit (visit_id),
    INDEX idx_type (photo_type),
    FOREIGN KEY (visit_id) REFERENCES job_visits(id) ON DELETE CASCADE,
    FOREIGN KEY (uploaded_by) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;


-- ============================================================================
-- 6. plan_notes — Plan-level notes (scope, instructions, not visit-specific)
-- ============================================================================
CREATE TABLE IF NOT EXISTS plan_notes (
    id INT AUTO_INCREMENT PRIMARY KEY,
    plan_id INT NOT NULL,
    note_type ENUM('general', 'customer_request', 'issue', 'follow_up', 'internal') DEFAULT 'general',
    content TEXT NOT NULL,
    is_visible_to_customer TINYINT(1) DEFAULT 0,
    created_by INT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,

    INDEX idx_plan (plan_id),
    INDEX idx_created (created_at),
    FOREIGN KEY (plan_id) REFERENCES job_plans(id) ON DELETE CASCADE,
    FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;


-- ============================================================================
-- 7. ALTER dependent tables — Add plan_id / visit_id columns
-- ============================================================================

-- activity_log: soft reference (no FK) — no job_id column in this table
ALTER TABLE activity_log
    ADD COLUMN plan_id INT NULL,
    ADD COLUMN visit_id INT NULL,
    ADD INDEX idx_plan (plan_id),
    ADD INDEX idx_visit (visit_id);

-- invoices: soft reference (no FK defined in original)
ALTER TABLE invoices
    ADD COLUMN plan_id INT NULL AFTER job_id,
    ADD COLUMN visit_id INT NULL AFTER plan_id,
    ADD INDEX idx_plan (plan_id),
    ADD INDEX idx_visit (visit_id);

-- invoice_line_items: soft reference
ALTER TABLE invoice_line_items
    ADD COLUMN visit_id INT NULL AFTER job_id,
    ADD INDEX idx_visit (visit_id);

-- job_time_entries: hard FK
ALTER TABLE job_time_entries
    ADD COLUMN visit_id INT NULL AFTER job_id,
    ADD INDEX idx_visit (visit_id),
    ADD CONSTRAINT fk_jte_visit FOREIGN KEY (visit_id) REFERENCES job_visits(id) ON DELETE CASCADE;

-- crew_location_history: has FK to jobs.id, add visit_id
ALTER TABLE crew_location_history
    ADD COLUMN visit_id INT NULL AFTER job_id,
    ADD INDEX idx_visit_loc (visit_id);

-- roi_attribution: soft reference
ALTER TABLE roi_attribution
    ADD COLUMN plan_id INT NULL AFTER job_id,
    ADD INDEX idx_plan_roi (plan_id);


SET FOREIGN_KEY_CHECKS = 1;

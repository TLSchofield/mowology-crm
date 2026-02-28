-- Mowology CRM - Job System Migration
-- Phase 5: Quote Management, Jobs, and Invoicing
-- Run this migration to add job system tables

-- ============================================
-- 1. ENHANCE QUOTES TABLE
-- ============================================

-- Add new columns to existing quotes table
ALTER TABLE quotes
    ADD COLUMN IF NOT EXISTS property_id INT AFTER client_id,
    ADD COLUMN IF NOT EXISTS title VARCHAR(255) AFTER property_id,
    ADD COLUMN IF NOT EXISTS subtotal DECIMAL(10,2) AFTER amount,
    ADD COLUMN IF NOT EXISTS tax_rate DECIMAL(5,4) DEFAULT 0.05 AFTER subtotal,
    ADD COLUMN IF NOT EXISTS tax_amount DECIMAL(10,2) AFTER tax_rate,
    ADD COLUMN IF NOT EXISTS terms TEXT AFTER description,
    ADD COLUMN IF NOT EXISTS notes_internal TEXT AFTER terms,
    ADD COLUMN IF NOT EXISTS notes_customer TEXT AFTER notes_internal,
    ADD COLUMN IF NOT EXISTS sent_at TIMESTAMP NULL AFTER notes_customer,
    ADD COLUMN IF NOT EXISTS sent_via ENUM('email', 'sms', 'both') NULL AFTER sent_at,
    ADD COLUMN IF NOT EXISTS viewed_at TIMESTAMP NULL AFTER sent_via,
    ADD COLUMN IF NOT EXISTS view_count INT DEFAULT 0 AFTER viewed_at,
    ADD COLUMN IF NOT EXISTS accepted_at TIMESTAMP NULL AFTER view_count,
    ADD COLUMN IF NOT EXISTS accepted_by_name VARCHAR(100) AFTER accepted_at,
    ADD COLUMN IF NOT EXISTS accepted_by_email VARCHAR(255) AFTER accepted_by_name,
    ADD COLUMN IF NOT EXISTS accepted_ip_address VARCHAR(45) AFTER accepted_by_email,
    ADD COLUMN IF NOT EXISTS signature_data MEDIUMTEXT AFTER accepted_ip_address,
    ADD COLUMN IF NOT EXISTS signature_timestamp TIMESTAMP NULL AFTER signature_data,
    ADD COLUMN IF NOT EXISTS access_token VARCHAR(64) UNIQUE AFTER signature_timestamp,
    ADD COLUMN IF NOT EXISTS token_expires_at TIMESTAMP NULL AFTER access_token,
    ADD COLUMN IF NOT EXISTS pdf_filename VARCHAR(255) AFTER token_expires_at;

-- Add index for access token lookups
CREATE INDEX IF NOT EXISTS idx_quotes_access_token ON quotes(access_token);

-- ============================================
-- 2. QUOTE LINE ITEMS TABLE
-- ============================================

CREATE TABLE IF NOT EXISTS quote_line_items (
    id INT AUTO_INCREMENT PRIMARY KEY,
    quote_id INT NOT NULL,
    service_type VARCHAR(100) NOT NULL,
    description TEXT,
    quantity DECIMAL(10,2) DEFAULT 1.00,
    unit_type ENUM('each', 'sqft', 'hour', 'visit', 'season', 'month') DEFAULT 'each',
    unit_price DECIMAL(10,2) NOT NULL,
    line_total DECIMAL(10,2) NOT NULL,
    sort_order INT DEFAULT 0,
    is_optional BOOLEAN DEFAULT FALSE,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,

    INDEX idx_quote (quote_id),
    FOREIGN KEY (quote_id) REFERENCES quotes(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================
-- 3. JOBS TABLE
-- ============================================

CREATE TABLE IF NOT EXISTS jobs (
    id INT AUTO_INCREMENT PRIMARY KEY,
    job_number VARCHAR(50) UNIQUE NOT NULL,

    -- Relationships
    quote_id INT,
    property_id INT NOT NULL,
    company_id INT NOT NULL,

    -- Job details
    title VARCHAR(255) NOT NULL,
    description TEXT,
    service_type VARCHAR(100) NOT NULL,

    -- Scheduling
    job_type ENUM('one_time', 'recurring') DEFAULT 'one_time',
    scheduled_date DATE,
    scheduled_time_start TIME,
    scheduled_time_end TIME,
    estimated_duration_minutes INT DEFAULT 60,

    -- Recurring settings (NULL for one-time jobs)
    recurrence_pattern ENUM('weekly', 'biweekly', 'monthly') NULL,
    recurrence_day_of_week TINYINT NULL COMMENT '0=Sunday, 6=Saturday',
    recurrence_end_date DATE NULL,
    parent_job_id INT NULL COMMENT 'For recurring instances, links to template job',

    -- Status workflow
    status ENUM('scheduled', 'in_progress', 'completed', 'cancelled', 'on_hold') DEFAULT 'scheduled',
    status_changed_at TIMESTAMP NULL,

    -- Assignment
    assigned_to INT COMMENT 'user_id of assigned staff',
    assigned_at TIMESTAMP NULL,

    -- Completion
    started_at TIMESTAMP NULL,
    completed_at TIMESTAMP NULL,
    completion_notes TEXT,

    -- Pricing
    estimated_amount DECIMAL(10,2),
    actual_amount DECIMAL(10,2),

    -- Timestamps
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    created_by INT,

    INDEX idx_quote (quote_id),
    INDEX idx_property (property_id),
    INDEX idx_company (company_id),
    INDEX idx_status (status),
    INDEX idx_scheduled_date (scheduled_date),
    INDEX idx_assigned_to (assigned_to),
    INDEX idx_parent_job (parent_job_id),
    INDEX idx_job_number (job_number),

    FOREIGN KEY (quote_id) REFERENCES quotes(id) ON DELETE SET NULL,
    FOREIGN KEY (assigned_to) REFERENCES users(id) ON DELETE SET NULL,
    FOREIGN KEY (parent_job_id) REFERENCES jobs(id) ON DELETE SET NULL,
    FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================
-- 4. JOB NOTES TABLE
-- ============================================

CREATE TABLE IF NOT EXISTS job_notes (
    id INT AUTO_INCREMENT PRIMARY KEY,
    job_id INT NOT NULL,
    note_type ENUM('general', 'customer_request', 'issue', 'follow_up', 'internal') DEFAULT 'general',
    content TEXT NOT NULL,
    is_visible_to_customer BOOLEAN DEFAULT FALSE,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    created_by INT,

    INDEX idx_job (job_id),
    INDEX idx_created (created_at),
    FOREIGN KEY (job_id) REFERENCES jobs(id) ON DELETE CASCADE,
    FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================
-- 5. JOB PHOTOS TABLE
-- ============================================

CREATE TABLE IF NOT EXISTS job_photos (
    id INT AUTO_INCREMENT PRIMARY KEY,
    job_id INT NOT NULL,
    photo_type ENUM('before', 'during', 'after', 'issue', 'other') DEFAULT 'after',
    filename VARCHAR(255) NOT NULL,
    original_filename VARCHAR(255),
    file_size INT,
    mime_type VARCHAR(100),
    caption TEXT,
    uploaded_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    uploaded_by INT,

    INDEX idx_job (job_id),
    INDEX idx_type (photo_type),
    FOREIGN KEY (job_id) REFERENCES jobs(id) ON DELETE CASCADE,
    FOREIGN KEY (uploaded_by) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================
-- 6. INVOICES TABLE
-- ============================================

CREATE TABLE IF NOT EXISTS invoices (
    id INT AUTO_INCREMENT PRIMARY KEY,
    invoice_number VARCHAR(50) UNIQUE NOT NULL,

    -- Relationships
    company_id INT NOT NULL,
    property_id INT,
    job_id INT,

    -- Invoice details
    issue_date DATE NOT NULL,
    due_date DATE NOT NULL,

    -- Amounts
    subtotal DECIMAL(10,2) NOT NULL,
    tax_rate DECIMAL(5,4) DEFAULT 0.05,
    tax_amount DECIMAL(10,2) NOT NULL,
    total DECIMAL(10,2) NOT NULL,
    amount_paid DECIMAL(10,2) DEFAULT 0.00,
    balance_due DECIMAL(10,2) NOT NULL,

    -- Status
    status ENUM('draft', 'sent', 'viewed', 'paid', 'partial', 'overdue', 'cancelled') DEFAULT 'draft',

    -- Payment tracking
    sent_at TIMESTAMP NULL,
    viewed_at TIMESTAMP NULL,
    paid_at TIMESTAMP NULL,
    payment_method ENUM('cash', 'cheque', 'e_transfer', 'credit_card', 'other') NULL,
    payment_reference VARCHAR(100),

    -- Notes
    notes TEXT,
    internal_notes TEXT,

    -- Customer access
    access_token VARCHAR(64) UNIQUE,
    token_expires_at TIMESTAMP NULL,

    -- PDF
    pdf_filename VARCHAR(255),

    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    created_by INT,

    INDEX idx_company (company_id),
    INDEX idx_job (job_id),
    INDEX idx_status (status),
    INDEX idx_due_date (due_date),
    INDEX idx_invoice_number (invoice_number),
    INDEX idx_access_token (access_token),

    FOREIGN KEY (job_id) REFERENCES jobs(id) ON DELETE SET NULL,
    FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================
-- 7. INVOICE LINE ITEMS TABLE
-- ============================================

CREATE TABLE IF NOT EXISTS invoice_line_items (
    id INT AUTO_INCREMENT PRIMARY KEY,
    invoice_id INT NOT NULL,
    description TEXT NOT NULL,
    quantity DECIMAL(10,2) DEFAULT 1.00,
    unit_price DECIMAL(10,2) NOT NULL,
    line_total DECIMAL(10,2) NOT NULL,
    job_id INT NULL,
    sort_order INT DEFAULT 0,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,

    INDEX idx_invoice (invoice_id),
    FOREIGN KEY (invoice_id) REFERENCES invoices(id) ON DELETE CASCADE,
    FOREIGN KEY (job_id) REFERENCES jobs(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================
-- 8. UPDATE ACTIVITY LOG FOR JOB TRACKING
-- ============================================

-- Add job_id column to activity_log if not exists
ALTER TABLE activity_log
    ADD COLUMN IF NOT EXISTS job_id INT AFTER client_id,
    ADD COLUMN IF NOT EXISTS quote_id INT AFTER job_id,
    ADD COLUMN IF NOT EXISTS invoice_id INT AFTER quote_id;

-- Add indexes for the new columns
CREATE INDEX IF NOT EXISTS idx_activity_job ON activity_log(job_id);
CREATE INDEX IF NOT EXISTS idx_activity_quote ON activity_log(quote_id);
CREATE INDEX IF NOT EXISTS idx_activity_invoice ON activity_log(invoice_id);

-- ============================================
-- 9. SERVICE TEMPLATES TABLE (for quick quote creation)
-- ============================================

CREATE TABLE IF NOT EXISTS service_templates (
    id INT AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(100) NOT NULL,
    service_type VARCHAR(100) NOT NULL,
    description TEXT,
    default_price DECIMAL(10,2),
    unit_type ENUM('each', 'sqft', 'hour', 'visit', 'season', 'month') DEFAULT 'each',
    is_active BOOLEAN DEFAULT TRUE,
    sort_order INT DEFAULT 0,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,

    INDEX idx_active (is_active),
    INDEX idx_service_type (service_type)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Insert default service templates
INSERT INTO service_templates (name, service_type, description, default_price, unit_type, sort_order) VALUES
('Lawn Mowing - Small', 'lawn_care', 'Regular lawn mowing for small properties (up to 2,000 sq ft)', 45.00, 'visit', 1),
('Lawn Mowing - Medium', 'lawn_care', 'Regular lawn mowing for medium properties (2,000-5,000 sq ft)', 65.00, 'visit', 2),
('Lawn Mowing - Large', 'lawn_care', 'Regular lawn mowing for large properties (5,000+ sq ft)', 85.00, 'visit', 3),
('Hedge Trimming', 'landscaping', 'Professional hedge and shrub trimming', 75.00, 'hour', 4),
('Garden Maintenance', 'landscaping', 'Weeding, pruning, and garden bed maintenance', 65.00, 'hour', 5),
('Spring Cleanup', 'seasonal_cleanup', 'Complete spring yard cleanup including debris removal', 150.00, 'each', 6),
('Fall Cleanup', 'seasonal_cleanup', 'Complete fall cleanup including leaf removal', 175.00, 'each', 7),
('Snow Removal - Residential', 'snow_removal', 'Driveway and walkway snow clearing', 45.00, 'visit', 8),
('Snow Removal - Seasonal Contract', 'snow_removal', 'Unlimited snow removal for the winter season', 450.00, 'season', 9),
('Lawn Aeration', 'lawn_care', 'Core aeration to improve lawn health', 120.00, 'each', 10),
('Fertilizer Application', 'lawn_care', 'Professional fertilizer application', 75.00, 'each', 11),
('Mulch Installation', 'landscaping', 'Garden bed mulch installation (per cubic yard)', 85.00, 'each', 12);

-- Migration 950: Purchase Task System
-- Expands the existing tasks table with purchase-specific columns.
-- Creates task_items table for purchase line items.
-- Enhances vendor_locations with operating hours/contact info.
-- Links expenses and job_time_entries to purchase tasks.
--
-- Run via: /crm/api/run-migration-950.php (admin only)
-- All ALTERs are idempotent (column-existence checks in runner).

-- ═══════════════════════════════════════════════════════════════════════════════
-- 1. ALTER tasks — Add purchase-specific columns
-- ═══════════════════════════════════════════════════════════════════════════════

ALTER TABLE tasks ADD COLUMN task_type ENUM('general','purchase') NOT NULL DEFAULT 'general' AFTER status;
ALTER TABLE tasks ADD COLUMN task_number VARCHAR(60) NULL AFTER task_type;
ALTER TABLE tasks ADD COLUMN vendor_id INT NULL AFTER invoice_id;
ALTER TABLE tasks ADD COLUMN vendor_location_id INT NULL AFTER vendor_id;
ALTER TABLE tasks ADD COLUMN procurement_mode ENUM('on_route','asap','truck_kit') NULL AFTER vendor_location_id;
ALTER TABLE tasks ADD COLUMN requested_date DATE NULL AFTER procurement_mode;
ALTER TABLE tasks ADD COLUMN scheduled_date DATE NULL AFTER requested_date;
ALTER TABLE tasks ADD COLUMN purchase_status ENUM('requested','assigned','en_route','at_vendor','purchased','delivered','verified') NULL AFTER scheduled_date;

-- GPS tracking
ALTER TABLE tasks ADD COLUMN departure_lat DECIMAL(10,8) NULL AFTER purchase_status;
ALTER TABLE tasks ADD COLUMN departure_lng DECIMAL(11,8) NULL AFTER departure_lat;
ALTER TABLE tasks ADD COLUMN arrival_lat DECIMAL(10,8) NULL AFTER departure_lng;
ALTER TABLE tasks ADD COLUMN arrival_lng DECIMAL(11,8) NULL AFTER arrival_lat;
ALTER TABLE tasks ADD COLUMN delivery_lat DECIMAL(10,8) NULL AFTER arrival_lng;
ALTER TABLE tasks ADD COLUMN delivery_lng DECIMAL(11,8) NULL AFTER delivery_lat;

-- Timestamps
ALTER TABLE tasks ADD COLUMN started_at TIMESTAMP NULL AFTER delivery_lng;
ALTER TABLE tasks ADD COLUMN arrived_at TIMESTAMP NULL AFTER started_at;
ALTER TABLE tasks ADD COLUMN purchased_at TIMESTAMP NULL AFTER arrived_at;
ALTER TABLE tasks ADD COLUMN delivered_at TIMESTAMP NULL AFTER purchased_at;
ALTER TABLE tasks ADD COLUMN verified_at TIMESTAMP NULL AFTER delivered_at;

-- Cost tracking
ALTER TABLE tasks ADD COLUMN estimated_total DECIMAL(10,2) NULL AFTER verified_at;
ALTER TABLE tasks ADD COLUMN actual_total DECIMAL(10,2) NULL AFTER estimated_total;
ALTER TABLE tasks ADD COLUMN expense_id INT NULL AFTER actual_total;

-- Time tracking
ALTER TABLE tasks ADD COLUMN drive_time_minutes INT NULL AFTER expense_id;
ALTER TABLE tasks ADD COLUMN shop_time_minutes INT NULL AFTER drive_time_minutes;
ALTER TABLE tasks ADD COLUMN total_time_minutes INT NULL AFTER shop_time_minutes;

-- Indexes
CREATE INDEX idx_task_type ON tasks (task_type);
CREATE INDEX idx_task_number ON tasks (task_number);
CREATE INDEX idx_task_vendor ON tasks (vendor_id);
CREATE INDEX idx_task_purchase_status ON tasks (purchase_status);
CREATE INDEX idx_task_scheduled_date ON tasks (scheduled_date);

-- Foreign keys
ALTER TABLE tasks ADD CONSTRAINT fk_task_vendor FOREIGN KEY (vendor_id) REFERENCES vendors(id) ON DELETE SET NULL;
ALTER TABLE tasks ADD CONSTRAINT fk_task_vendor_loc FOREIGN KEY (vendor_location_id) REFERENCES vendor_locations(id) ON DELETE SET NULL;
ALTER TABLE tasks ADD CONSTRAINT fk_task_expense FOREIGN KEY (expense_id) REFERENCES expenses(id) ON DELETE SET NULL;

-- Unique constraint on task_number (only for non-null values)
ALTER TABLE tasks ADD UNIQUE INDEX uq_task_number (task_number);


-- ═══════════════════════════════════════════════════════════════════════════════
-- 2. CREATE task_items — Purchase line items
-- ═══════════════════════════════════════════════════════════════════════════════

CREATE TABLE IF NOT EXISTS task_items (
    id INT AUTO_INCREMENT PRIMARY KEY,
    task_id INT NOT NULL,
    vendor_product_id INT NULL COMMENT 'FK to vendor_products for catalog items',
    product_id INT NULL COMMENT 'FK to products (internal catalog)',
    name VARCHAR(255) NOT NULL,
    description TEXT NULL,
    quantity DECIMAL(10,2) NOT NULL DEFAULT 1.00,
    unit VARCHAR(50) NULL COMMENT 'unit, yard, bag, each, etc.',
    estimated_unit_price DECIMAL(10,2) NULL,
    actual_unit_price DECIMAL(10,2) NULL,
    actual_total DECIMAL(10,2) NULL,
    quote_line_item_id INT NULL COMMENT 'Tracks which quoted item this fulfills',
    plan_line_item_id INT NULL COMMENT 'Tracks which plan item this fulfills',
    is_purchased TINYINT(1) NOT NULL DEFAULT 0,
    is_substituted TINYINT(1) NOT NULL DEFAULT 0,
    substitution_notes TEXT NULL,
    sort_order INT NOT NULL DEFAULT 0,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,

    INDEX idx_ti_task (task_id),
    INDEX idx_ti_vendor_product (vendor_product_id),
    INDEX idx_ti_product (product_id),

    FOREIGN KEY (task_id) REFERENCES tasks(id) ON DELETE CASCADE,
    FOREIGN KEY (vendor_product_id) REFERENCES vendor_products(id) ON DELETE SET NULL,
    FOREIGN KEY (product_id) REFERENCES products(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci
COMMENT='Line items for purchase tasks';


-- ═══════════════════════════════════════════════════════════════════════════════
-- 3. ALTER vendor_locations — Enhanced fields
-- ═══════════════════════════════════════════════════════════════════════════════

ALTER TABLE vendor_locations ADD COLUMN phone VARCHAR(20) NULL AFTER city;
ALTER TABLE vendor_locations ADD COLUMN hours_weekday VARCHAR(20) NULL AFTER phone;
ALTER TABLE vendor_locations ADD COLUMN hours_saturday VARCHAR(20) NULL AFTER hours_weekday;
ALTER TABLE vendor_locations ADD COLUMN hours_sunday VARCHAR(20) NULL AFTER hours_saturday;
ALTER TABLE vendor_locations ADD COLUMN notes TEXT NULL AFTER hours_sunday;
ALTER TABLE vendor_locations ADD COLUMN is_preferred TINYINT(1) NOT NULL DEFAULT 0 AFTER notes;


-- ═══════════════════════════════════════════════════════════════════════════════
-- 4. ALTER expenses — Purchase task linkage
-- ═══════════════════════════════════════════════════════════════════════════════

ALTER TABLE expenses ADD COLUMN purchase_task_id INT NULL AFTER job_id;
CREATE INDEX idx_exp_purchase_task ON expenses (purchase_task_id);
ALTER TABLE expenses ADD CONSTRAINT fk_exp_purchase_task FOREIGN KEY (purchase_task_id) REFERENCES tasks(id) ON DELETE SET NULL;


-- ═══════════════════════════════════════════════════════════════════════════════
-- 5. ALTER job_time_entries — Purchase time type
-- ═══════════════════════════════════════════════════════════════════════════════

ALTER TABLE job_time_entries ADD COLUMN purchase_task_id INT NULL AFTER visit_id;
ALTER TABLE job_time_entries ADD COLUMN time_type ENUM('job','purchase','drive') NOT NULL DEFAULT 'job' AFTER purchase_task_id;
CREATE INDEX idx_jte_purchase_task ON job_time_entries (purchase_task_id);

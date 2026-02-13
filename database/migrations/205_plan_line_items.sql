-- Migration 205: Plan Line Items
-- Adds line items to job plans (what services are included in each visit)
-- Also tracks which quote line items have been converted to plans

-- 1. Create plan_line_items table
CREATE TABLE IF NOT EXISTS plan_line_items (
    id INT AUTO_INCREMENT PRIMARY KEY,
    plan_id INT NOT NULL,
    quote_line_item_id INT NULL,
    service_type VARCHAR(100) NOT NULL,
    description TEXT,
    quantity DECIMAL(10,2) NOT NULL DEFAULT 1,
    unit_type ENUM('each','sqft','hour','visit','season','month') DEFAULT 'visit',
    unit_price DECIMAL(10,2) NOT NULL DEFAULT 0,
    line_total DECIMAL(10,2) NOT NULL DEFAULT 0,
    sort_order INT DEFAULT 0,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (plan_id) REFERENCES job_plans(id) ON DELETE CASCADE,
    INDEX idx_plan_id (plan_id),
    INDEX idx_quote_line_item (quote_line_item_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- 2. Add plan_id to quote_line_items to track conversions
-- (Check if column exists first to be safe)
-- ALTER TABLE quote_line_items ADD COLUMN plan_id INT NULL AFTER quote_id;
-- ALTER TABLE quote_line_items ADD INDEX idx_plan_id (plan_id);

-- Migration 601: Contact Purchase History
-- Materialized purchase history table + lifecycle columns on contacts
-- Depends on: quotes, job_plans, quote_line_items, plan_line_items, properties

-- Materialized purchase history (refreshed by cron)
CREATE TABLE IF NOT EXISTS contact_product_history (
  id INT AUTO_INCREMENT PRIMARY KEY,
  contact_id INT NOT NULL,
  product_id INT NOT NULL,
  first_purchased DATE NULL,
  last_purchased DATE NULL,
  total_quantity DECIMAL(10,2) DEFAULT 0,
  total_revenue DECIMAL(10,2) DEFAULT 0,
  source VARCHAR(50) NULL COMMENT 'quote, plan, both',
  refreshed_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY uq_contact_product (contact_id, product_id),
  KEY idx_product (product_id),
  KEY idx_contact (contact_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- Add lifecycle columns to contacts (safe: checks before adding)
-- These may already exist in the schema file but are missing on production
ALTER TABLE contacts
  ADD COLUMN lifecycle_stage ENUM('lead','prospect','customer','repeat','at_risk','lost') DEFAULT 'lead';

ALTER TABLE contacts
  ADD COLUMN last_service_date DATE NULL;

ALTER TABLE contacts
  ADD COLUMN total_lifetime_value DECIMAL(10,2) DEFAULT 0;

ALTER TABLE contacts
  ADD COLUMN first_quote_date DATE NULL;

ALTER TABLE contacts
  ADD COLUMN first_job_date DATE NULL;

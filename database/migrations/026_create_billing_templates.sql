-- Migration 026: Billing Templates Table
-- Purpose: Define how jobs translate into invoices and payment schedules
-- Date: February 8, 2026

CREATE TABLE IF NOT EXISTS billing_templates (
  id INT AUTO_INCREMENT PRIMARY KEY,

  -- Identity
  template_name VARCHAR(100) NOT NULL,
  slug VARCHAR(50) UNIQUE NOT NULL,
  description TEXT,

  -- Billing behavior
  invoicing_mode ENUM('per_visit', 'monthly_grouped', 'monthly_flat', 'prepay') DEFAULT 'per_visit',

  -- Invoice timing
  invoice_when VARCHAR(30) DEFAULT 'on_completion',
  days_until_due INT DEFAULT 30,

  -- Auto-grouping rules (for monthly_grouped)
  group_by_property BOOLEAN DEFAULT TRUE,
  group_by_crew BOOLEAN DEFAULT FALSE,
  include_notes BOOLEAN DEFAULT TRUE,

  -- Recurring behavior
  applies_to_recurring VARCHAR(30),

  -- Tax & discounts
  tax_rate DECIMAL(5,2) DEFAULT 5.00,
  apply_discount_after_tax BOOLEAN DEFAULT FALSE,

  -- Client communication
  send_invoice_immediately BOOLEAN DEFAULT TRUE,
  payment_terms TEXT,

  -- Service address handling
  show_service_address BOOLEAN DEFAULT TRUE,
  require_proof_before_invoice BOOLEAN DEFAULT FALSE,

  -- State
  is_active BOOLEAN DEFAULT TRUE,
  is_default BOOLEAN DEFAULT FALSE,
  sort_order INT DEFAULT 0,

  -- Timestamps
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,

  KEY idx_active (is_active),
  KEY idx_default (is_default),
  KEY idx_sort (sort_order)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Seed default billing templates
INSERT INTO billing_templates (template_name, slug, description, invoicing_mode, invoice_when, payment_terms, is_active, is_default, sort_order) VALUES
('Per Visit', 'per-visit', 'One invoice per completed job visit', 'per_visit', 'on_completion', 'Due upon receipt', TRUE, TRUE, 1),
('Monthly Grouped', 'monthly-grouped', 'Combine multiple jobs at same property into monthly invoices', 'monthly_grouped', 'end_of_month', 'Net 30', TRUE, FALSE, 2),
('Monthly Flat', 'monthly-flat', 'Flat monthly service fee regardless of visit count', 'monthly_flat', 'end_of_month', 'Net 30', TRUE, FALSE, 3),
('Seasonal Prepay', 'seasonal-prepay', 'Prepayment for seasonal services (snow removal, spring cleanup)', 'prepay', 'upfront', 'Due before service begins', TRUE, FALSE, 4);

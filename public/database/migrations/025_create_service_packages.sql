-- Migration 025: Service Packages Table
-- Purpose: Define reusable service packages that drive job creation with smart defaults
-- Date: February 8, 2026

CREATE TABLE IF NOT EXISTS service_packages (
  id INT AUTO_INCREMENT PRIMARY KEY,

  -- Identity
  package_name VARCHAR(100) NOT NULL,
  slug VARCHAR(100) UNIQUE NOT NULL,
  description TEXT,
  icon_name VARCHAR(50),
  category VARCHAR(50),

  -- Defaults for job creation
  default_duration_minutes INT DEFAULT 60,
  default_crew_size INT DEFAULT 1,
  default_visit_frequency VARCHAR(30),

  -- Billing & Pricing
  base_price DECIMAL(10,2) NOT NULL,
  unit_type VARCHAR(20) DEFAULT 'visit',
  billing_template_id INT,
  default_billing_interval VARCHAR(30),
  margin_target_percent INT DEFAULT 35,

  -- Proof of Work
  checklist_items JSON,
  photo_types_required JSON,
  gps_enforcement VARCHAR(20) DEFAULT 'optional',
  photos_block_completion BOOLEAN DEFAULT FALSE,
  checklist_blocks_completion BOOLEAN DEFAULT FALSE,

  -- Seasonal behavior
  seasonal_available VARCHAR(100),
  estimated_seasonal_recurrence VARCHAR(30),

  -- Modifiers (inline price adjustments)
  modifiers JSON,

  -- Service types (legacy — for filtering/reporting)
  service_type VARCHAR(50),

  -- State
  is_active BOOLEAN DEFAULT TRUE,
  is_premium BOOLEAN DEFAULT FALSE,
  sort_order INT DEFAULT 0,

  -- Timestamps
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  created_by INT,

  KEY idx_active (is_active),
  KEY idx_category (category),
  KEY idx_sort (sort_order),
  FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Seed default service packages
INSERT INTO service_packages (package_name, slug, description, icon_name, category, default_duration_minutes, default_crew_size, base_price, unit_type, service_type, checklist_items, photo_types_required, modifiers, is_active, sort_order) VALUES
('Lawn Mowing Standard', 'lawn-mowing-standard', 'Standard residential lawn mowing (45 min)', 'leaf', 'mowing', 45, 1, 65.00, 'visit', 'lawn_care', '["lines_present", "trim_edges", "debris_removed"]', '["before", "after"]', '[{"id": "green-waste", "name": "+$25 Green Waste Removal", "cost": 25}, {"id": "edging", "name": "+$15 Edging Touch-up", "cost": 15}]', TRUE, 1),
('Lawn Mowing Large', 'lawn-mowing-large', 'Large property lawn mowing (90 min, 2 crew)', 'leaf', 'mowing', 90, 2, 120.00, 'visit', 'lawn_care', '["lines_present", "trim_edges", "debris_removed"]', '["before", "after"]', '[{"id": "green-waste", "name": "+$35 Green Waste Removal", "cost": 35}]', TRUE, 2),
('Hedge Trim Light', 'hedge-trim-light', 'Light hedge trimming (up to 1 hour)', 'scissors', 'trimming', 60, 1, 75.00, 'visit', 'hedge_trimming', '["branches_cleared", "debris_removed"]', '["before", "after"]', '[]', TRUE, 3),
('Hedge Trim Heavy', 'hedge-trim-heavy', 'Heavy hedge trimming with hauling (2 hours, 2 crew)', 'scissors', 'trimming', 120, 2, 150.00, 'visit', 'hedge_trimming', '["branches_cleared", "debris_removed", "hauled"]', '["before", "after", "issue"]', '[]', TRUE, 4),
('Spring Cleanup', 'spring-cleanup', 'Seasonal spring property cleanup (2 hours, 2 crew)', 'wand-2', 'cleanup', 120, 2, 200.00, 'visit', 'seasonal_cleanup', '["all_debris_removed", "edges_clean", "beds_prepared"]', '["before", "after"]', '[]', TRUE, 5),
('Garden Maintenance', 'garden-maintenance', 'Weekly garden weeding and mulch refresh', 'clover', 'maintenance', 60, 1, 65.00, 'visit', 'garden_maintenance', '["weeds_removed", "mulch_refreshed", "edges_clean"]', '["before", "after"]', '[]', TRUE, 6),
('Snow Removal Per Visit', 'snow-removal-per-visit', 'Single driveway/entrance snow removal', 'cloud-snow', 'seasonal', 30, 1, 75.00, 'visit', 'snow_removal', '["driveway_clear", "entrance_clear"]', '["before", "after"]', '[]', TRUE, 7),
('Snow Removal Seasonal', 'snow-removal-seasonal', 'Monthly snow removal plan (Nov-Feb, flat rate)', 'cloud-snow', 'seasonal', 0, 1, 450.00, 'month', 'snow_removal', '[]', '[]', '[]', TRUE, 8);

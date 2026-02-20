-- Migration 602: Field Observations & Recommendation Rules
-- Crew logs on-site observations (pH tests, pest damage, etc.)
-- which trigger personalized product recommendation emails to customers.
-- Depends on: products, contacts, properties, media_assets

-- Field observations logged by crew during visits
CREATE TABLE IF NOT EXISTS field_observations (
  id INT AUTO_INCREMENT PRIMARY KEY,
  visit_id INT NULL COMMENT 'FK to job_visits if logged during a visit',
  property_id INT NOT NULL,
  contact_id INT NOT NULL,
  observation_type VARCHAR(50) NOT NULL COMMENT 'ph_test, soil_condition, pest_damage, weed_growth, drainage_issue, lawn_disease, tree_hazard, hedge_overgrowth, hardscape_damage, other',
  observation_value VARCHAR(255) NULL COMMENT 'e.g., pH 5.2, severity: high',
  notes TEXT NULL,
  photo_media_id INT NULL COMMENT 'FK to media_assets',
  recommended_product_id INT NULL COMMENT 'FK to products',
  status VARCHAR(20) DEFAULT 'pending' COMMENT 'pending, approved, email_sent, quote_created, converted, dismissed',
  auto_send TINYINT(1) DEFAULT 0 COMMENT '1=auto-send on creation, 0=queue for review',
  email_sent_at DATETIME NULL,
  dismissed_reason VARCHAR(255) NULL,
  created_by INT NOT NULL COMMENT 'FK to users (crew member)',
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  KEY idx_status (status),
  KEY idx_contact (contact_id),
  KEY idx_property (property_id),
  KEY idx_product (recommended_product_id),
  KEY idx_visit (visit_id),
  KEY idx_created (created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- Maps observation types to default product recommendations + email templates
CREATE TABLE IF NOT EXISTS observation_product_rules (
  id INT AUTO_INCREMENT PRIMARY KEY,
  observation_type VARCHAR(50) NOT NULL,
  condition_match VARCHAR(255) NULL COMMENT 'optional condition e.g., ph < 6.0 for acidic soil',
  recommended_product_id INT NOT NULL COMMENT 'FK to products',
  email_subject VARCHAR(255) NOT NULL,
  email_body_template TEXT NOT NULL COMMENT 'HTML with {{merge_fields}}',
  auto_send TINYINT(1) DEFAULT 0 COMMENT '1=auto-send when observation logged, 0=queue for review',
  is_active TINYINT(1) DEFAULT 1,
  priority INT DEFAULT 0 COMMENT 'higher = checked first when multiple rules match',
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  KEY idx_type (observation_type),
  KEY idx_active (is_active)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

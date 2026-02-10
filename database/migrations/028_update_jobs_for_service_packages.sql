-- Migration 028: Update Jobs Table for Service Packages
-- Purpose: Add foreign keys and fields to support service package-driven job creation
-- Date: February 8, 2026

ALTER TABLE jobs ADD COLUMN service_package_id INT NULL AFTER service_type;
ALTER TABLE jobs ADD COLUMN billing_template_id INT NULL AFTER estimated_amount;
ALTER TABLE jobs ADD COLUMN crew_size_required INT DEFAULT 1 AFTER billing_template_id;
ALTER TABLE jobs ADD COLUMN actual_crew_count INT NULL AFTER crew_size_required;
ALTER TABLE jobs ADD COLUMN route_sequence INT NULL AFTER actual_crew_count;

-- Add foreign key constraints
ALTER TABLE jobs ADD CONSTRAINT fk_jobs_service_package
  FOREIGN KEY (service_package_id) REFERENCES service_packages(id) ON DELETE SET NULL;

ALTER TABLE jobs ADD CONSTRAINT fk_jobs_billing_template
  FOREIGN KEY (billing_template_id) REFERENCES billing_templates(id) ON DELETE SET NULL;

-- Add indexes for performance
ALTER TABLE jobs ADD KEY idx_service_package (service_package_id);
ALTER TABLE jobs ADD KEY idx_billing_template (billing_template_id);
ALTER TABLE jobs ADD KEY idx_route_sequence (route_sequence);

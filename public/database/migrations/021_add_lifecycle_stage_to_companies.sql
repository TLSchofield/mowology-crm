-- Add lifecycle_stage column to companies table
-- Defines client progression through sales/service pipeline

ALTER TABLE `companies` ADD COLUMN `lifecycle_stage` VARCHAR(50) DEFAULT 'prospect' AFTER `account_status`;

-- Create lifecycle_stages lookup table for future extensibility
CREATE TABLE IF NOT EXISTS `lifecycle_stages` (
  `id` INT NOT NULL AUTO_INCREMENT PRIMARY KEY,
  `stage_key` VARCHAR(50) NOT NULL UNIQUE COMMENT 'Unique identifier (prospect, qualified, client, inactive)',
  `stage_label` VARCHAR(100) NOT NULL COMMENT 'Display name',
  `stage_order` INT DEFAULT 0 COMMENT 'Sort order in kanban',
  `stage_color` VARCHAR(10) DEFAULT '#6B7280' COMMENT 'Hex color for UI',
  `description` TEXT COMMENT 'Stage description',
  `is_active` TINYINT(1) DEFAULT 1,
  `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  `updated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- Insert default lifecycle stages
INSERT INTO `lifecycle_stages` (`stage_key`, `stage_label`, `stage_order`, `stage_color`, `description`)
VALUES
  ('prospect', 'Prospect', 1, '#3B82F6', 'New inquiry or quote request'),
  ('qualified', 'Qualified', 2, '#F59E0B', 'Prospect has been contacted and qualified'),
  ('client', 'Client', 3, '#2D8659', 'Active paying customer'),
  ('inactive', 'Inactive', 4, '#6B7280', 'No longer active or pursuing')
ON DUPLICATE KEY UPDATE stage_label=VALUES(stage_label);

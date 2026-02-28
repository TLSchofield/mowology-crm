-- Migration 013: Create portfolio_projects table
-- Adds support for portfolio project management with full CRUD from CRM dashboard

CREATE TABLE IF NOT EXISTS `portfolio_projects` (
  `id` int NOT NULL AUTO_INCREMENT PRIMARY KEY,
  `project_number` varchar(20) NOT NULL UNIQUE COMMENT 'Format: PORT-YYYY-NNNN',
  `project_name` varchar(255) NOT NULL COMMENT 'Project title/name',
  `description` text COMMENT 'Full project description',
  `location` varchar(255) COMMENT 'Project location/address',
  `status` enum('draft', 'published') NOT NULL DEFAULT 'draft' COMMENT 'Publication status',
  `featured` boolean NOT NULL DEFAULT false COMMENT 'Show on top of portfolio',
  `display_order` int NOT NULL DEFAULT 999 COMMENT 'Sort order on frontend',
  `categories` json COMMENT 'Array of category IDs or strings',
  `tags` json COMMENT 'Array of project tags (e.g., ["Weekly Maintenance", "Strata"])',
  `before_image_path` varchar(512) COMMENT 'Path to before image',
  `after_image_path` varchar(512) COMMENT 'Path to after image',
  `gallery_images` json COMMENT 'Array of gallery image paths',
  `created_by` int COMMENT 'User ID of project creator',
  `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,

  CONSTRAINT `fk_portfolio_projects_user_id`
    FOREIGN KEY (`created_by`) REFERENCES `users` (`id`) ON DELETE SET NULL,

  INDEX `idx_portfolio_projects_status` (`status`),
  INDEX `idx_portfolio_projects_featured` (`featured`),
  INDEX `idx_portfolio_projects_display_order` (`display_order` ASC),
  INDEX `idx_portfolio_projects_created_at` (`created_at` DESC),
  INDEX `idx_portfolio_projects_project_number` (`project_number`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci
COMMENT='Portfolio projects for CRM management and public display';

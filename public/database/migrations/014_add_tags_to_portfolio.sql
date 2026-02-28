-- Migration 014: Add tags column to portfolio_projects table
-- Adds support for project tags (flexible labels)

ALTER TABLE portfolio_projects ADD COLUMN `tags` json COMMENT 'Array of project tags (e.g., ["Weekly Maintenance", "Strata"])' AFTER `categories`;

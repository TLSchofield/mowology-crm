-- ============================================================
-- Migration 1033: Template service_type + schedule source flexibility
-- ============================================================

-- Add service_type to templates so the scheduler picks the right optimal slots
ALTER TABLE social_templates
    ADD COLUMN service_type VARCHAR(100) NULL DEFAULT NULL AFTER category;

-- Allow campaigns to be sourced from a template (not just a saved post)
ALTER TABLE social_schedules
    MODIFY COLUMN source_post_id INT NULL DEFAULT NULL,
    ADD COLUMN source_template_id INT NULL DEFAULT NULL AFTER source_post_id;

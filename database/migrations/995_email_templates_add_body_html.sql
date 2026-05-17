-- Migration 995: Patch email_templates to add body_html and missing columns
-- The table was created by an older script (run-migration-email-templates.php)
-- with template_key/body_text schema. Migration 604 was silently skipped by
-- CREATE TABLE IF NOT EXISTS, leaving production without columns the Marketing
-- module expects. This migration adds them without touching existing data.

ALTER TABLE email_templates
  ADD COLUMN body_html    TEXT         NULL                    AFTER subject,
  ADD COLUMN category     VARCHAR(50)  NOT NULL DEFAULT 'custom' AFTER name,
  ADD COLUMN merge_fields TEXT         NULL,
  ADD COLUMN created_by   INT          NULL,
  ADD COLUMN created_at   TIMESTAMP    DEFAULT CURRENT_TIMESTAMP;

-- Populate body_html from body_text for all existing rows
UPDATE email_templates SET body_html = body_text WHERE body_html IS NULL;

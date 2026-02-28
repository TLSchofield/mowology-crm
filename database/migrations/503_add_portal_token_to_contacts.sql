-- Migration 503: Add portal_token to contacts
-- Allows each contact to have a persistent portal access link

ALTER TABLE contacts
    ADD COLUMN portal_token VARCHAR(64) NULL DEFAULT NULL AFTER notes,
    ADD UNIQUE KEY unique_portal_token (portal_token);

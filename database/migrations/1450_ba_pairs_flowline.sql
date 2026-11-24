-- Migration 1450: Before & After flow line (Mia's strip)
-- Date: 2026-11-10
-- Purpose: the owner's privacy check (faces, house numbers, licence plates) is recorded before Mia's text is
-- offered, and whether the offered text was published unedited is kept so Mia's confidence is earned by record.
-- Used only while ops_settings ba_flowline = '1'. Safe to run twice (the updater skips duplicate columns).
ALTER TABLE ba_pairs ADD COLUMN privacy_checked_by INT NULL COMMENT 'users.id who checked the photos' AFTER rejected_reason;
ALTER TABLE ba_pairs ADD COLUMN privacy_checked_at DATETIME NULL AFTER privacy_checked_by;
ALTER TABLE ba_pairs ADD COLUMN offer_state VARCHAR(10) NULL COMMENT 'unedited | edited: Mia offered text, how it was published' AFTER privacy_checked_at;

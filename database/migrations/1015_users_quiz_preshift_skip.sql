-- Migration 1015: per-user quiz pre-shift skip flag
-- Allows individual users to bypass the pre-shift quiz gate
-- regardless of the global quiz_preshift_enabled setting.
ALTER TABLE users
    ADD COLUMN quiz_preshift_skip TINYINT(1) NOT NULL DEFAULT 0
        COMMENT 'When 1, this user skips the pre-shift quiz even when globally enabled';

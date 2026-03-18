-- Migration 971: Add driver's licence fields to users table
-- Licence number stored encrypted (government ID). Class, province, expiry are plaintext.

ALTER TABLE users ADD COLUMN dl_number_encrypted TEXT NULL AFTER notes;
ALTER TABLE users ADD COLUMN dl_class VARCHAR(10) NULL AFTER dl_number_encrypted;
ALTER TABLE users ADD COLUMN dl_province VARCHAR(2) NULL AFTER dl_class;
ALTER TABLE users ADD COLUMN dl_expiry DATE NULL AFTER dl_province;

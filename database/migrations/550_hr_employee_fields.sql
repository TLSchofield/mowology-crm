-- ============================================================================
-- Migration 550: HR Employee Fields
-- ============================================================================
-- Purpose: Extend the users table with full human-resources fields:
--   personal address, SIN (encrypted), date of birth, banking info (encrypted),
--   TD1 tax credit amounts, and install-link tracking.
--
-- Security notes:
--   - SIN and bank account numbers are stored AES-256 encrypted via PHP
--     (openssl_encrypt / openssl_decrypt using APP_ENCRYPTION_KEY from secrets.php)
--   - Raw values are NEVER stored in plaintext columns
--   - Fields prefixed _encrypted contain base64(IV + ciphertext)
--
-- MySQL 5.7 compatible — no JSON functions, no window functions
-- Run outside of a transaction (DDL auto-commits)
-- ============================================================================

-- ── Personal Address ──────────────────────────────────────────────────────
ALTER TABLE `users`
    ADD COLUMN IF NOT EXISTS `address`      VARCHAR(255) NULL COMMENT 'Street address' AFTER `notes`,
    ADD COLUMN IF NOT EXISTS `city`         VARCHAR(100) NULL AFTER `address`,
    ADD COLUMN IF NOT EXISTS `province`     VARCHAR(50)  NULL AFTER `city`,
    ADD COLUMN IF NOT EXISTS `postal_code`  VARCHAR(10)  NULL AFTER `province`;

-- ── Personal / Legal ──────────────────────────────────────────────────────
ALTER TABLE `users`
    ADD COLUMN IF NOT EXISTS `date_of_birth`    DATE         NULL COMMENT 'Employee DOB (stored in plaintext — not SIN-level sensitive)' AFTER `postal_code`,
    ADD COLUMN IF NOT EXISTS `sin_encrypted`    TEXT         NULL COMMENT 'AES-256 encrypted SIN — base64(iv+ciphertext)' AFTER `date_of_birth`;

-- ── Banking / Direct Deposit ──────────────────────────────────────────────
ALTER TABLE `users`
    ADD COLUMN IF NOT EXISTS `bank_transit`           VARCHAR(5)   NULL COMMENT '5-digit branch transit number (plaintext)' AFTER `sin_encrypted`,
    ADD COLUMN IF NOT EXISTS `bank_institution`        VARCHAR(3)   NULL COMMENT '3-digit institution number (plaintext)' AFTER `bank_transit`,
    ADD COLUMN IF NOT EXISTS `bank_account_encrypted`  TEXT         NULL COMMENT 'AES-256 encrypted account number' AFTER `bank_institution`;

-- ── Tax Forms ─────────────────────────────────────────────────────────────
ALTER TABLE `users`
    ADD COLUMN IF NOT EXISTS `td1_federal`     DECIMAL(10,2) NULL COMMENT 'Federal TD1 personal tax credits total' AFTER `bank_account_encrypted`,
    ADD COLUMN IF NOT EXISTS `td1_provincial`  DECIMAL(10,2) NULL COMMENT 'Provincial TD1 personal tax credits total' AFTER `td1_federal`,
    ADD COLUMN IF NOT EXISTS `td1_notes`       TEXT          NULL COMMENT 'Admin notes re: TD1 / T4' AFTER `td1_provincial`;

-- ── App Install Link Tracking ─────────────────────────────────────────────
ALTER TABLE `users`
    ADD COLUMN IF NOT EXISTS `install_link_sent_at`   DATETIME     NULL COMMENT 'When install link was last sent' AFTER `td1_notes`,
    ADD COLUMN IF NOT EXISTS `install_link_sent_by`   INT          NULL COMMENT 'FK to users — who sent the install link' AFTER `install_link_sent_at`;

-- ── RBAC permissions ──────────────────────────────────────────────────────
-- hr.view  — can see the HR profile tab (admin + manager)
-- hr.edit  — can edit sensitive fields like SIN/banking (admin only)

INSERT IGNORE INTO `permissions` (`key`, `description`) VALUES
    ('hr.view', 'View employee HR profile (address, DOB, banking summary)'),
    ('hr.edit', 'Edit sensitive HR fields (SIN, banking, TD1)');

-- Admin gets both
INSERT IGNORE INTO `role_permissions` (`role_id`, `permission_id`)
    SELECT r.id, p.id FROM roles r CROSS JOIN permissions p
    WHERE r.name = 'admin' AND p.`key` IN ('hr.view', 'hr.edit');

-- Manager gets view-only
INSERT IGNORE INTO `role_permissions` (`role_id`, `permission_id`)
    SELECT r.id, p.id FROM roles r JOIN permissions p ON p.`key` = 'hr.view'
    WHERE r.name = 'manager';

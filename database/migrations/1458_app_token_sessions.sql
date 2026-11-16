-- Migration 1458: one row per app (JWT) sign-in, so a token can be revoked (Fable audit H2, Tim 2026-11-16).
-- Every token now carries a jti that points at its row here. JwtAuth refuses a token whose row is missing
-- or revoked, so a token issued before this change (no jti) stops working on deploy: each iPhone signs in once more.
-- Idempotent (CREATE TABLE IF NOT EXISTS). MySQL 5.7 compatible: no window functions, no generated columns.

CREATE TABLE IF NOT EXISTS `app_token_sessions` (
  `jti` CHAR(32) NOT NULL,
  `user_id` INT NOT NULL,
  `platform` VARCHAR(16) NOT NULL DEFAULT 'ios',
  `created_at` DATETIME NOT NULL,
  `expires_at` DATETIME NOT NULL,
  `revoked_at` DATETIME NULL DEFAULT NULL,
  PRIMARY KEY (`jti`),
  KEY `idx_ats_user` (`user_id`, `revoked_at`),
  KEY `idx_ats_expires` (`expires_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

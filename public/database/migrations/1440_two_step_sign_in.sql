-- Migration 1440 (2026-11-06): two-step sign-in, opt-in (sign-in security plan step 2).
-- user_two_step        : one row per person who has begun setup; enabled_at NULL = not confirmed yet (no code asked).
--                        totp_secret_enc is encrypted (QboTokenVault, key in secrets.php); last_step stops a code being reused.
-- user_backup_codes    : single-use codes, stored only as password_hash().
-- user_trusted_devices : "trust this device" cookies, stored only as sha256; revoked on password change or turning off.
-- No row in user_two_step (or tables missing) = nobody is asked for a code.
CREATE TABLE IF NOT EXISTS user_two_step (
  user_id INT NOT NULL PRIMARY KEY,
  totp_secret_enc TEXT NOT NULL,
  enabled_at DATETIME NULL,
  last_step BIGINT NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
CREATE TABLE IF NOT EXISTS user_backup_codes (
  id INT AUTO_INCREMENT PRIMARY KEY,
  user_id INT NOT NULL,
  code_hash VARCHAR(255) NOT NULL,
  used_at DATETIME NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  KEY idx_user_backup_codes_user (user_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
CREATE TABLE IF NOT EXISTS user_trusted_devices (
  id INT AUTO_INCREMENT PRIMARY KEY,
  user_id INT NOT NULL,
  token_hash CHAR(64) NOT NULL,
  user_agent VARCHAR(255) NULL,
  created_at DATETIME NOT NULL,
  last_seen_at DATETIME NOT NULL,
  expires_at DATETIME NOT NULL,
  KEY idx_user_trusted_devices_token (token_hash),
  KEY idx_user_trusted_devices_user (user_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- Migration 1341: client portal links expire, and portal email changes wait for confirmation
-- (security review #14, 2026-10-12). PortalTokenService / PortalEmailChangeService.
-- Every existing token is stamped NOW(), so no client is cut off the day this runs; links then last
-- 365 days and the office can regenerate one (contact page, clients.edit). MySQL 5.7.
ALTER TABLE contacts ADD COLUMN portal_token_issued_at DATETIME NULL DEFAULT NULL COMMENT 'portal_token works for 365 days from here';
UPDATE contacts SET portal_token_issued_at = NOW() WHERE portal_token IS NOT NULL AND portal_token_issued_at IS NULL;
CREATE TABLE IF NOT EXISTS contact_email_changes (
  id INT AUTO_INCREMENT PRIMARY KEY,
  contact_id INT NOT NULL,
  old_email VARCHAR(255) NULL,
  new_email VARCHAR(255) NOT NULL,
  token_hash CHAR(64) NOT NULL COMMENT 'sha256 of the emailed link token',
  expires_at DATETIME NOT NULL,
  confirmed_at DATETIME NULL,
  ip_address VARCHAR(45) NULL,
  created_at DATETIME NOT NULL,
  UNIQUE KEY uq_cec_token (token_hash),
  KEY idx_cec_contact (contact_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

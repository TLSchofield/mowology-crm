-- Migration 1283: keep the attachments of billing mail routed to Penny
-- Date: 2026-10-08
-- Purpose: a property manager's direct-deposit / vendor form arrives as a PDF. Penny shows it
--   as a task for Tim ("fill it in and email ...") with the attachment one tap away. Only PDF /
--   image attachments of inbound customer mail routed to PENNY are kept (other customer mail
--   attachments are never stored). Files live under app/Storage/inbound-attachments (the app/
--   directory denies all web access); they are served only to an admin session
--   (/crm/api/inbound-route.php?mode=attachment) or by a short-lived signed link for the app
--   (/api/team/inbound-attachment). Penny never fills in or sends banking details herself.
-- MySQL 5.7 compatible; safe to re-run.

CREATE TABLE IF NOT EXISTS inbound_message_attachments (
  id INT AUTO_INCREMENT PRIMARY KEY,
  message_key VARCHAR(191) NOT NULL      COMMENT 'sales_messages.message_key',
  filename VARCHAR(255) NOT NULL,
  mime VARCHAR(100) NOT NULL,
  size_bytes INT NOT NULL DEFAULT 0,
  sha256 CHAR(64) NOT NULL,
  stored_path VARCHAR(255) NOT NULL      COMMENT 'Relative to app/Storage/inbound-attachments',
  created_at DATETIME NOT NULL,
  UNIQUE KEY uq_ima_key_sha (message_key, sha256),
  INDEX idx_ima_key (message_key)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci
  COMMENT='Attachments of inbound billing mail routed to Penny';

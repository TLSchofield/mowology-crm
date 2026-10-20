-- 1383 — QuickBooks Online, step 2: what Ittai has sent and what it became in QuickBooks
-- (plan 2026-10-20). One row per Ittai thing sent: a client → Customer, an income account → its
-- Service item, an invoice → Invoice, a payment → Payment. The request id is fixed per thing, so a
-- retry of the same send can never make a second copy (Intuit replays the first answer).
-- QboSyncService. Tenant-neutral (site_id).

CREATE TABLE IF NOT EXISTS qbo_links (
  id INT AUTO_INCREMENT PRIMARY KEY,
  site_id INT NOT NULL DEFAULT 1,
  realm_id VARCHAR(32) NOT NULL COMMENT 'the QuickBooks company it was sent to',
  entity_type VARCHAR(16) NOT NULL COMMENT 'client | income_item | invoice | payment',
  entity_id VARCHAR(64) NOT NULL COMMENT 'Ittai id (client id, account code, invoice id, allocation id)',
  qbo_id VARCHAR(32) NULL,
  sync_token VARCHAR(16) NULL,
  request_id VARCHAR(64) NOT NULL,
  status VARCHAR(12) NOT NULL DEFAULT 'sent' COMMENT 'sent | mismatch | refused (QuickBooks said no: next try gets a new request id) | error (timeout/outage: same id)',
  qbo_total DECIMAL(12,2) NULL COMMENT 'QuickBooks total, compared with Ittai''s',
  message VARCHAR(500) NULL,
  sent_at DATETIME NULL,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  UNIQUE KEY uq_qbo_link (site_id, realm_id, entity_type, entity_id),
  KEY idx_qbo_link_qbo (site_id, realm_id, entity_type, qbo_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

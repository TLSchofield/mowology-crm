-- Migration 1047 — clients (account spine) + client_contacts (people junction)
--
-- Client/Account model, Phase 1. Creates the unified bill-to entity and the
-- many-to-many link between accounts and the people on them.
--
--   clients         — the bill-to (person OR organization). A strata, a PM
--                     firm, and an individual homeowner are all clients.
--   client_contacts — which contacts belong to which client, with a role and
--                     per-person routing flags (decision D2).
--
-- Provenance columns legacy_company_id / legacy_contact_id make the backfill
-- (1049) idempotent and reversible, and let later phases trace each client
-- back to its source row. They are UNIQUE → exactly one client per source.
--
-- See docs/architecture/client-account-model.md §2, §5 (D1/D2/D3).
-- MySQL rules: nullable FKs (companies/contacts may be sparse), ON DELETE
-- SET NULL / CASCADE as appropriate, utf8mb4_general_ci for FK collation match.

CREATE TABLE IF NOT EXISTS clients (
  id INT AUTO_INCREMENT PRIMARY KEY,
  site_id INT NULL DEFAULT 1
    COMMENT 'FK sites.id — tenant. D3: only clients carries the tenant discriminator.',
  client_type ENUM('individual','business','strata','property_manager')
    NOT NULL DEFAULT 'individual',
  display_name VARCHAR(200) NOT NULL
    COMMENT 'Invoice/account header name: "Jane Doe" | "Strata Plan VR15/40" | "FirstService Residential"',
  billing_address VARCHAR(255) NULL DEFAULT NULL,
  billing_city VARCHAR(100) NULL DEFAULT NULL,
  billing_province VARCHAR(50) NULL DEFAULT NULL,
  billing_postal_code VARCHAR(10) NULL DEFAULT NULL,
  billing_email VARCHAR(255) NULL DEFAULT NULL
    COMMENT 'Ultimate invoice fallback when no client_contact has receives_invoices=1',
  billing_phone VARCHAR(50) NULL DEFAULT NULL,
  payment_terms VARCHAR(50) DEFAULT 'Net 30',
  payment_method ENUM('invoice','credit_card','bank_transfer','cheque') DEFAULT 'invoice',
  lifecycle_stage VARCHAR(50) DEFAULT 'prospect',
  status ENUM('active','inactive','suspended') DEFAULT 'active',
  managed_by_client_id INT NULL DEFAULT NULL
    COMMENT 'FK clients.id — a strata managed BY a PM firm; routing walks to the firm.',
  legacy_company_id INT NULL DEFAULT NULL
    COMMENT 'Provenance: companies.id this client was backfilled from (org clients).',
  legacy_contact_id INT NULL DEFAULT NULL
    COMMENT 'Provenance: contacts.id this client was backfilled from (individual clients).',
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  CONSTRAINT fk_clients_site
    FOREIGN KEY (site_id) REFERENCES sites(id) ON DELETE SET NULL,
  CONSTRAINT fk_clients_managed_by
    FOREIGN KEY (managed_by_client_id) REFERENCES clients(id) ON DELETE SET NULL,
  INDEX idx_clients_site (site_id),
  INDEX idx_clients_type (client_type),
  INDEX idx_clients_display (display_name),
  INDEX idx_clients_status (status),
  -- D3: account identity is unique per TENANT, not globally.
  UNIQUE KEY uq_client_site_legacy_company (site_id, legacy_company_id),
  UNIQUE KEY uq_client_site_legacy_contact (site_id, legacy_contact_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

CREATE TABLE IF NOT EXISTS client_contacts (
  id INT AUTO_INCREMENT PRIMARY KEY,
  client_id INT NOT NULL,
  contact_id INT NOT NULL,
  role ENUM('owner','council_member','property_manager','billing','site')
    NOT NULL DEFAULT 'owner',
  is_primary TINYINT(1) DEFAULT 0,
  receives_invoices TINYINT(1) DEFAULT 0
    COMMENT 'D2: authoritative routing flag (replaces companies.invoice_routing_method).',
  receives_notifications TINYINT(1) DEFAULT 0,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  CONSTRAINT fk_cc_client
    FOREIGN KEY (client_id) REFERENCES clients(id) ON DELETE CASCADE,
  CONSTRAINT fk_cc_contact
    FOREIGN KEY (contact_id) REFERENCES contacts(id) ON DELETE CASCADE,
  -- Same person can hold the SAME role on many clients, and several roles on
  -- one client — but not the same role twice on the same client.
  UNIQUE KEY uq_client_contact_role (client_id, contact_id, role),
  INDEX idx_cc_client (client_id),
  INDEX idx_cc_contact (contact_id),
  INDEX idx_cc_receives_invoices (receives_invoices)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

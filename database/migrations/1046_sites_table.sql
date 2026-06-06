-- Migration 1046 — sites (tenant) stub table
--
-- Client/Account model, Phase 1 (decision D3): tenancy resolves THROUGH the
-- client spine, so only `clients` needs a site_id. This creates the one-row
-- tenant stub that clients.site_id points at. Dormant until the SaaS tenancy
-- phase fills it with real tenants.
--
-- See docs/architecture/client-account-model.md §3 (Phase 1), §5 (D3).
-- MySQL rules: no IF NOT EXISTS on ALTER; CREATE TABLE IF NOT EXISTS is fine.

CREATE TABLE IF NOT EXISTS sites (
  id INT AUTO_INCREMENT PRIMARY KEY,
  site_key VARCHAR(50) NOT NULL,
  name VARCHAR(150) NOT NULL,
  domain VARCHAR(255) NULL DEFAULT NULL,
  status ENUM('active','inactive') DEFAULT 'active',
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  UNIQUE KEY uq_site_key (site_key)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- Seed the first tenant (Mowology) as id=1. Idempotent: only inserts if absent.
INSERT INTO sites (id, site_key, name, domain)
SELECT 1, 'mowology', 'Mowology', 'mowology.ca'
FROM DUAL
WHERE NOT EXISTS (SELECT 1 FROM sites WHERE id = 1);

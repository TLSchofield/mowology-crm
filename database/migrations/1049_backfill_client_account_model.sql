-- Migration 1049 — backfill the Client/Account model
--
-- Client/Account model, Phase 1. Populates clients + client_contacts from the
-- existing contacts/companies, then stamps client_id onto every billable
-- entity. Old columns are LEFT IN PLACE (contract happens in Phase 4).
--
-- IDEMPOTENT BY DESIGN — safe to re-run:
--   * INSERT IGNORE + UNIQUE(legacy_*) → no duplicate clients
--   * INSERT IGNORE + UNIQUE(client_id,contact_id,role) → no duplicate links
--   * UPDATE ... WHERE client_id IS NULL → only fills unset rows
--
-- ⚠️ REVIEW BEFORE RUNNING ON PRODUCTION. Assumptions (verify against the live
-- schema snapshot — production differs from the schema file):
--   * invoices.contact_id exists (migration 209) and invoices.company_id is
--     nullable (209 MODIFY).
--   * properties.company_id exists (migration 1012). billing_company_id is
--     NOT referenced here (it appears only in page code, never in a migration)
--     — if it exists in prod and you want it preferred over company_id, add it
--     to the COALESCE in Step E/properties.
--   * companies.invoice_routing_method / primary_contact_id / billing_contact_id
--     exist (clean schema).
--
-- See docs/architecture/client-account-model.md §3, §5 (D1/D2).

-- ─────────────────────────────────────────────────────────────────────────
-- STEP A — organization clients from companies (provenance: legacy_company_id)
-- company_type values align 1:1 with clients.client_type.
-- ─────────────────────────────────────────────────────────────────────────
INSERT IGNORE INTO clients
  (site_id, client_type, display_name, billing_address, billing_city,
   billing_province, billing_postal_code, billing_email, billing_phone,
   payment_terms, payment_method, lifecycle_stage, status, legacy_company_id)
SELECT
  1,
  COALESCE(co.company_type, 'business'),
  co.company_name,
  co.billing_address, co.billing_city, co.billing_province, co.billing_postal_code,
  co.billing_email, co.billing_phone,
  COALESCE(co.payment_terms, 'Net 30'),
  COALESCE(co.payment_method, 'invoice'),
  COALESCE(co.lifecycle_stage, 'prospect'),
  CASE co.account_status WHEN 'inactive' THEN 'inactive'
                         WHEN 'suspended' THEN 'suspended'
                         ELSE 'active' END,
  co.id
FROM companies co;

-- ─────────────────────────────────────────────────────────────────────────
-- STEP B — individual clients from contacts (D1: EVERY contact gets one)
-- provenance: legacy_contact_id
-- ─────────────────────────────────────────────────────────────────────────
INSERT IGNORE INTO clients
  (site_id, client_type, display_name, billing_email, billing_phone,
   lifecycle_stage, status, legacy_contact_id)
SELECT
  1,
  'individual',
  NULLIF(TRIM(CONCAT(COALESCE(c.first_name,''), ' ', COALESCE(c.last_name,''))), ''),
  c.email,
  COALESCE(NULLIF(c.mobile,''), c.phone),
  COALESCE(c.lifecycle_stage, 'lead'),
  CASE WHEN c.is_active = 1 THEN 'active' ELSE 'inactive' END,
  c.id
FROM contacts c
-- display_name is NOT NULL; skip any nameless contact rather than fail.
WHERE TRIM(CONCAT(COALESCE(c.first_name,''), ' ', COALESCE(c.last_name,''))) <> '';

-- ─────────────────────────────────────────────────────────────────────────
-- STEP C — link each individual client to its own contact (owner / primary)
-- ─────────────────────────────────────────────────────────────────────────
INSERT IGNORE INTO client_contacts
  (client_id, contact_id, role, is_primary, receives_invoices, receives_notifications)
SELECT cl.id, cl.legacy_contact_id, 'owner', 1, 1, 1
FROM clients cl
WHERE cl.legacy_contact_id IS NOT NULL;

-- ─────────────────────────────────────────────────────────────────────────
-- STEP D — link organization clients to their people, deriving routing flags
-- from the old invoice_routing_method enum (D2). NULL method → default to
-- primary receives invoices.
-- ─────────────────────────────────────────────────────────────────────────
-- D.1 primary contact
INSERT IGNORE INTO client_contacts
  (client_id, contact_id, role, is_primary, receives_invoices, receives_notifications)
SELECT
  cl.id, co.primary_contact_id, 'owner', 1,
  CASE WHEN co.invoice_routing_method IN ('primary_contact','both_contacts')
            OR co.invoice_routing_method IS NULL THEN 1 ELSE 0 END,
  1
FROM clients cl
JOIN companies co ON co.id = cl.legacy_company_id
WHERE cl.legacy_company_id IS NOT NULL
  AND co.primary_contact_id IS NOT NULL;

-- D.2 billing contact (only when distinct from the primary)
INSERT IGNORE INTO client_contacts
  (client_id, contact_id, role, is_primary, receives_invoices, receives_notifications)
SELECT
  cl.id, co.billing_contact_id, 'billing', 0,
  CASE WHEN co.invoice_routing_method IN ('billing_contact','both_contacts') THEN 1 ELSE 0 END,
  0
FROM clients cl
JOIN companies co ON co.id = cl.legacy_company_id
WHERE cl.legacy_company_id IS NOT NULL
  AND co.billing_contact_id IS NOT NULL
  AND co.billing_contact_id <> COALESCE(co.primary_contact_id, 0);

-- ─────────────────────────────────────────────────────────────────────────
-- STEP E — stamp client_id onto billable entities (org link wins, else the
-- individual via contact/site-contact). Idempotent: only unset rows.
-- ─────────────────────────────────────────────────────────────────────────
-- properties: org via company_id, else individual via site_contact_id
UPDATE properties p
LEFT JOIN clients oc ON oc.legacy_company_id = p.company_id
LEFT JOIN clients ic ON ic.legacy_contact_id = p.site_contact_id
SET p.client_id = COALESCE(oc.id, ic.id)
WHERE p.client_id IS NULL
  AND COALESCE(oc.id, ic.id) IS NOT NULL;

-- invoices: company_id → contact_id → property's client
UPDATE invoices i
LEFT JOIN clients oc ON oc.legacy_company_id = i.company_id
LEFT JOIN clients ic ON ic.legacy_contact_id = i.contact_id
LEFT JOIN properties p ON p.id = i.property_id
SET i.client_id = COALESCE(oc.id, ic.id, p.client_id)
WHERE i.client_id IS NULL
  AND COALESCE(oc.id, ic.id, p.client_id) IS NOT NULL;

-- quotes: company_id → property's client
UPDATE quotes q
LEFT JOIN clients oc ON oc.legacy_company_id = q.company_id
LEFT JOIN properties p ON p.id = q.property_id
SET q.client_id = COALESCE(oc.id, p.client_id)
WHERE q.client_id IS NULL
  AND COALESCE(oc.id, p.client_id) IS NOT NULL;

-- job_plans: company_id → property's client
UPDATE job_plans jp
LEFT JOIN clients oc ON oc.legacy_company_id = jp.company_id
LEFT JOIN properties p ON p.id = jp.property_id
SET jp.client_id = COALESCE(oc.id, p.client_id)
WHERE jp.client_id IS NULL
  AND COALESCE(oc.id, p.client_id) IS NOT NULL;

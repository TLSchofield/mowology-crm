-- 1190: Laburnum Heights (strata VR738, 1551 West 11th Avenue) — one property, the right roles (Tim, 2026-10-06).
--   #77   the real property: contract CTR-2026-0005, plan, arrival border. Managed by Quay Pacific (company 15),
--         whose accounts address gets the invoices (unchanged).
--   #581  an empty duplicate made for Roland Bowman (contact 70) — archived here.
--   Marianna Pandy (contact 1518) is Quay Pacific's property manager → role + employer, and Quay Pacific's primary
--   contact (it had none). Roland is the strata rep → quotes for #77 go to him.
-- Every statement is guarded by id AND address / email domain, so it does nothing if the records have moved on.

-- Marianna: property manager at Quay Pacific
UPDATE contacts
   SET contact_role = 'property_manager', employer_company_id = 15
 WHERE id = 1518 AND email LIKE '%@quaypacific.com';

UPDATE companies
   SET primary_contact_id = 1518
 WHERE id = 15 AND (primary_contact_id IS NULL OR primary_contact_id = 0)
   AND EXISTS (SELECT 1 FROM contacts WHERE id = 1518 AND email LIKE '%@quaypacific.com');

-- Roland: strata rep; quotes for the building go to him
UPDATE contacts
   SET contact_role = 'strata_rep'
 WHERE id = 70 AND (contact_role IS NULL OR contact_role = '');

UPDATE properties
   SET quote_contact_id = 70
 WHERE id = 77 AND address = '1551 West 11th Avenue';

-- Move any per-building contact rows from the duplicate, then archive it (only if nothing else hangs off it)
UPDATE property_contacts pc
  JOIN properties dup  ON dup.id = 581 AND dup.address = '1551 West 11th Avenue'
  JOIN properties keep ON keep.id = 77 AND keep.address = '1551 West 11th Avenue'
   SET pc.property_id = 77
 WHERE pc.property_id = 581
   AND NOT EXISTS (SELECT 1 FROM (SELECT property_id, contact_id FROM property_contacts) x
                    WHERE x.property_id = 77 AND x.contact_id = pc.contact_id);

UPDATE properties
   SET status = 'archived',
       notes = CONCAT(COALESCE(notes, ''), CASE WHEN COALESCE(notes, '') = '' THEN '' ELSE '\n' END,
                      'Duplicate of property #77 (Laburnum Heights, VR738) — archived 2026-10-06; use #77.')
 WHERE id = 581 AND address = '1551 West 11th Avenue' AND status <> 'archived'
   AND NOT EXISTS (SELECT 1 FROM quotes    WHERE property_id = 581)
   AND NOT EXISTS (SELECT 1 FROM job_plans WHERE property_id = 581)
   AND NOT EXISTS (SELECT 1 FROM invoices  WHERE property_id = 581);

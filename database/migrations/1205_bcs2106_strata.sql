-- 1205: 2505 West 8th Avenue is strata BCS 2106, self-governed (no management company). Alexandra Bee
-- (contact 855) is the strata representative AND pays the bills (Tim, 2026-10-07). Contract invoices
-- then read "BCS 2106 · Attn Alexandra Bee"; quotes for the building go to her.
-- Guarded by id + address.

UPDATE properties
   SET property_type = 'strata',
       billing_entity_name = 'BCS 2106',
       property_name = CASE WHEN COALESCE(property_name, '') = '' THEN 'BCS 2106' ELSE property_name END,
       quote_contact_id = 855
 WHERE id = 843 AND address = '2505 West 8th Avenue';

UPDATE contacts
   SET contact_role = 'strata_rep'
 WHERE id = 855 AND (contact_role IS NULL OR contact_role = '');

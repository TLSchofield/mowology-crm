-- 1193: contacts.lifecycle_stage could only hold lead/prospect/customer/repeat/at_risk/lost (an old ENUM),
-- but the CRM writes client/opportunity/inactive (lifecycle_stages keys, the contact page, the pipeline rules).
-- Every "make them a client" was silently refused, so contract clients stayed "Lead" (e.g. Monica Nicule).
-- Widen to VARCHAR like companies.lifecycle_stage and move the old values onto the CRM's keys. (Tim, 2026-10-06)

ALTER TABLE contacts MODIFY lifecycle_stage VARCHAR(50) NULL DEFAULT 'lead';

UPDATE contacts SET lifecycle_stage = 'client'      WHERE lifecycle_stage IN ('customer', 'repeat');
UPDATE contacts SET lifecycle_stage = 'opportunity' WHERE lifecycle_stage = 'prospect';
UPDATE contacts SET lifecycle_stage = 'inactive'    WHERE lifecycle_stage = 'at_risk';

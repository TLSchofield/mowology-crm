-- 1187: GST is never included in a price; it is always added at the end (Tim, 2026-10-06).
-- The default terms said "All prices include GST." while every quote adds 5% on top.
-- Fixes the contract terms templates (version bumped, so new contracts name the new revision)
-- and open quotes (draft, sent, viewed — Tim approved). Accepted quotes and signed contract snapshots are left as they are.

UPDATE contract_terms_templates
   SET body = REPLACE(body, 'All prices include GST.', 'GST (5%) is added to all prices.'),
       version = version + 1
 WHERE body LIKE '%All prices include GST.%';

UPDATE quotes
   SET terms = REPLACE(terms, 'All prices include GST.', 'GST (5%) is added to all prices.')
 WHERE status IN ('draft','sent','viewed')
   AND terms LIKE '%All prices include GST.%';

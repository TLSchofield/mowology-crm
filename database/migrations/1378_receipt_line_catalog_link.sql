-- 1378 — receipt lines linked to the SHARED product catalogue, matched by themselves (owner,
-- 2026-10-19: "shouldnt we be able to match products with our catalogue and have an option to add to
-- it?" … "as much as possible this must be automatched"). ReceiptCatalogService.
--   catalog_product_id   the catalogue product this line is (shared; the price stays on this tenant's line)
--   catalog_match        alias | exact      linked by itself (a learned spelling/SKU, or an exact key)
--                        confirmed | added  a person's tap (Yes / chose one / Add to catalogue) — taught as an alias
--                        suggested          a likely product, waiting for Yes (catalog_suggested_id, catalog_score)
--                        none               a person said "not this" — no suggestion until the line changes
-- Learned links live in catalog_aliases (shared): 'rcpt:<vendor name key>:sku:<SKU>' and ':n:<words>'.
ALTER TABLE expense_line_items
  ADD COLUMN catalog_product_id INT NULL,
  ADD COLUMN catalog_match VARCHAR(12) NULL,
  ADD COLUMN catalog_suggested_id INT NULL,
  ADD COLUMN catalog_score DECIMAL(4,3) NULL,
  ADD KEY idx_eli_catalog (catalog_product_id);

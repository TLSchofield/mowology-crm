-- Migration 1322: approving a receipt confirms its item names (owner, 2026-10-11).
-- vendor_product_skus already remembers SKU → name from every save; times_confirmed counts the
-- approvals where that name was left as it was. A confirmed name is used on the next receipt
-- from the same vendor (ReceiptLearning: getVendorLineItemProfile 'sku_names'). MySQL 5.7.
ALTER TABLE vendor_product_skus
  ADD COLUMN times_confirmed INT NOT NULL DEFAULT 0 COMMENT 'Approvals that kept this SKU''s name unchanged';

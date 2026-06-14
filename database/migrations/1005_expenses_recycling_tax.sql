-- Migration 1005: Add recycling_tax column to expenses
--
-- BC receipts often include a "Drink Tax" / Environmental Handling Charge (EHC)
-- on beverage containers. This is tracked separately from GST/PST.

ALTER TABLE `expenses`
  ADD COLUMN `recycling_tax` DECIMAL(10,2) NOT NULL DEFAULT 0.00
    COMMENT 'BC recycling / drink tax (EHC) — beverage container deposit fee'
  AFTER `pst_amount`;

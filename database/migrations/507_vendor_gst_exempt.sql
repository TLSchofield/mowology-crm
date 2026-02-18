-- Migration 507: Add GST exempt flag to vendors
-- Some vendors (e.g., Vancouver Landfill dump fees) do not charge GST.
-- When a GST-exempt vendor is matched, the system sets gst_amount = 0
-- and does NOT estimate GST even if the parser returns null.

ALTER TABLE `vendors` ADD COLUMN `gst_exempt` tinyint(1) NOT NULL DEFAULT 0
    COMMENT 'If 1, this vendor does not charge GST (e.g., municipal dump fees)'
    AFTER `notes`;

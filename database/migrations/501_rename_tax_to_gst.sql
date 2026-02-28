-- ============================================================================
-- Migration 501: Rename tax_amount → gst_amount in expenses table
-- ============================================================================
-- Purpose: Properly label the tax column as GST for BC accounting.
--          BC businesses charge/pay 5% GST — this makes the column name
--          explicit for clean accounting exports and QuickBooks integration.
--
-- MySQL 5.7 compatible — run outside of a transaction (DDL auto-commits)
-- ============================================================================

ALTER TABLE `expenses` CHANGE `tax_amount` `gst_amount` DECIMAL(10,2) DEFAULT 0.00;

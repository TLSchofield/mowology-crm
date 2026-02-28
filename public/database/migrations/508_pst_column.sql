-- ============================================================================
-- Migration 508: Add PST (Provincial Sales Tax) column to expenses
-- ============================================================================
-- Purpose: BC charges 7% PST on many items. The parser already detects PST
--          internally (via extractColumnSeparatedAmounts) but has nowhere to
--          store it. This column completes the tax tracking picture:
--          total = amount + gst_amount + pst_amount
--
-- MySQL 5.7 compatible — run outside of a transaction (DDL auto-commits)
-- ============================================================================

ALTER TABLE `expenses` ADD COLUMN `pst_amount` decimal(10,2) DEFAULT 0.00
    COMMENT 'BC Provincial Sales Tax (7%) — null or 0 for PST-exempt items'
    AFTER `gst_amount`;

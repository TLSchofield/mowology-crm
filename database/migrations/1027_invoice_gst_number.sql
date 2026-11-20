-- ============================================================================
-- Migration 1027: Add gst_number to invoices
-- ============================================================================
-- Purpose: Stores the business's CRA GST registration number on each invoice
--          at creation time so the number is locked to the invoice even if the
--          business registration changes later. Required for CRA-compliant
--          invoices (mandatory on invoices over $30 for GST registrants).
--
-- MySQL 5.7 compatible — run OUTSIDE of a transaction (DDL auto-commits)
-- ============================================================================

ALTER TABLE `invoices`
  ADD COLUMN `gst_number` VARCHAR(50) NULL DEFAULT NULL
    COMMENT 'Business GST/HST registration number at time of invoicing'
    AFTER `tax_amount`;

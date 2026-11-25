-- Migration 1405: vendor_parse_profiles.header_layouts + read_mode (Ittai, 2026-10-28)
-- Purpose: the receipt reader learns WHERE each vendor prints total / subtotal / GST / PST (label text, same line or
-- next line, currency prefix) from the owner's confirmed corrections, and remembers per vendor whether the cheap read
-- is trustworthy or the strong read (AI header rung) should be used straight away.
--   header_layouts : JSON string, e.g. {"total":[{"label":"TOTAL","pos":"next_line","cur":"CAD$","seen":3}]}
--   read_mode      : 'cheap' | 'strong' | NULL (NULL = decide from accuracy; 'strong' set by the owner forces it)
-- Not safe to re-run (MySQL 5.7 has no ADD COLUMN IF NOT EXISTS); run once.

ALTER TABLE vendor_parse_profiles ADD COLUMN header_layouts TEXT NULL;
ALTER TABLE vendor_parse_profiles ADD COLUMN read_mode VARCHAR(10) NULL;

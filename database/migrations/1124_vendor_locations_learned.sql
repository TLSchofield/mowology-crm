-- Migration 1124: Learned vendor store locations
-- Date: 2026-10-05
-- Purpose: GPS could only nudge a vendor the OCR text had already found, and only for
--   stores whose coordinates someone typed in (5 of 17 locations on 2026-10-05). Now
--   each approved phone receipt's location is attached to that vendor's nearest store
--   spot within 150 m (or starts a new one); a learned spot counts once two receipts
--   confirm it, and then names the store when the receipt text can't.
--   Spots shared by 3+ different vendors (home, office, truck) are never learned.
-- Code is guarded: ReceiptLearning/ReceiptSmartMatch probe for receipts_seen and skip
--   learned-store logic until this runs; hand-entered locations work as before.
-- MySQL 5.7 compatible.

ALTER TABLE vendor_locations
  ADD COLUMN source VARCHAR(20) NULL
    COMMENT 'NULL = entered by hand; learned = from approved receipt GPS',
  ADD COLUMN receipts_seen INT NOT NULL DEFAULT 0
    COMMENT 'Approved receipts whose capture location fell within 150 m of this spot',
  ADD COLUMN last_seen_at DATETIME NULL;

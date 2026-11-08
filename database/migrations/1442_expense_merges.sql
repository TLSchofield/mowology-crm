-- Migration 1442: expense_merges — a duplicate receipt merged into another, and the way back
-- Date: 2026-11-06
-- Purpose: "Merge" on Penny's duplicate card (phone and web) sets the copy aside — rejected
--   "Duplicate of receipt #X", kept for the six-year record, never deleted — as before. New:
--   one row here per merged copy, holding everything needed to split it off again exactly
--   (its prior status and rejection fields, and what the kept receipt took from it), who did
--   it and on what evidence. The kept receipt shows the copy's photo by reading this table,
--   so an approved receipt itself is never edited.
--   Split off / Undo: DuplicateReceiptService::unmerge() (gate transition 'unmerge').
--   Penny's per-vendor duplicate record counts rows here (merged = agreed, unmerged = wrong).
-- Plan: docs/plans/2026-11-05-duplicate-receipts-on-the-phone.md
-- Code is guarded: without this table merges still work the old way (no undo).
-- MySQL 5.7 compatible.

CREATE TABLE IF NOT EXISTS expense_merges (
  id INT AUTO_INCREMENT PRIMARY KEY,
  copy_id INT NOT NULL COMMENT 'the receipt set aside (rejected) as a copy',
  kept_id INT NOT NULL COMMENT 'the receipt that stays',
  evidence VARCHAR(20) NULL COMMENT 'number | photo | total_date — what Penny matched on',
  evidence_text VARCHAR(255) NULL COMMENT 'her verdict in words, as shown',
  copy_before_json TEXT NULL COMMENT 'copy status/rejection fields before, and what the kept one took (fill, photo, lines)',
  merged_by INT NULL COMMENT 'user id; NULL = Penny (not used yet: she only proposes)',
  merged_at DATETIME NOT NULL,
  unmerged_by INT NULL,
  unmerged_at DATETIME NULL,
  unmerge_kind VARCHAR(10) NULL COMMENT 'undo (a slip, asked again) | split (not the same purchase)',
  INDEX idx_em_copy (copy_id),
  INDEX idx_em_kept (kept_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci
  COMMENT='Duplicate receipts merged into another, with what is needed to split them off';

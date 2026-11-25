-- Migration 1066: Receipt archival pointers on media_assets
-- Run on production via /crm/api/run-migration-1066-receipt-archive.php (admin only).
--
-- Supports the Receipt Archival & Export feature: confirmed expense receipts are
-- bundled into a ZIP, emailed to the owner/accountant/bookkeeping inbox, then the
-- original image is moved OFF the live web disk into app/Storage/receipt-archive/
-- (private, denied by app/.htaccess) to reclaim disk. The DB record and a pointer to
-- the archived original are kept so the CRA 6-year record is never lost.
--
-- A row is "archived" once archived_at IS NOT NULL — this is the idempotency guard
-- (the archive selector excludes already-archived rows so re-runs are safe).
--
-- MySQL 5.7-compatible: plain ADD COLUMN (no IF NOT EXISTS), no DDL inside a txn.

ALTER TABLE media_assets
  ADD COLUMN archived_at      DATETIME     NULL DEFAULT NULL COMMENT 'When the original was moved to cold archive',
  ADD COLUMN archive_path     VARCHAR(512) NULL DEFAULT NULL COMMENT 'APP_ROOT-relative path, e.g. Storage/receipt-archive/2026/01/<file>',
  ADD COLUMN archive_batch_id INT          NULL DEFAULT NULL COMMENT 'receipt_archive_batches.id this row was archived in',
  ADD COLUMN original_removed TINYINT(1)   NOT NULL DEFAULT 0 COMMENT '1 = web-disk original deleted (thumb may remain)',
  ADD COLUMN thumb_path       VARCHAR(512) NULL DEFAULT NULL COMMENT 'Web path of the small thumbnail left in /uploads/receipts/, or NULL';

CREATE INDEX idx_media_archived ON media_assets (archived_at);

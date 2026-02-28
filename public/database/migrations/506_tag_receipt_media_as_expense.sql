-- Migration 506: Tag receipt media as context_type='expense'
-- Receipts uploaded via expense module were missing context_type,
-- causing them to appear in the Portfolio Dashboard.
-- Identifies receipts by: file_path containing '/receipts/' OR alt_text = 'Receipt photo'
-- Also catches any linked via expenses.receipt_media_id

-- Tag by file path (most reliable)
UPDATE media_assets
SET context_type = 'expense'
WHERE file_path LIKE '%/receipts/%'
  AND (context_type IS NULL OR context_type = 'cms');

-- Tag by alt_text
UPDATE media_assets
SET context_type = 'expense'
WHERE alt_text = 'Receipt photo'
  AND (context_type IS NULL OR context_type = 'cms');

-- Tag any media linked from expenses table
UPDATE media_assets ma
INNER JOIN expenses e ON e.receipt_media_id = ma.id
SET ma.context_type = 'expense'
WHERE ma.context_type IS NULL OR ma.context_type = 'cms';

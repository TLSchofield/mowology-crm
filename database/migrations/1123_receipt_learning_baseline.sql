-- Migration 1123: Receipt learning baseline
-- Date: 2026-10-05
-- Purpose: header learning (total/GST/subtotal/date/vendor/category) used to diff the
--   saved values against a FRESH re-parse of the stored OCR on every save. That re-parse
--   skips learned patterns, smart-match, Vision and the LLM tier, so it is not what the
--   user was shown; category was never in it (every save logged a null→X lesson); and
--   each re-save counted the same lesson again.
--   Now the extraction shown at capture is stored once (ocr_parsed_json) and header
--   lessons are recorded once, when the receipt is confirmed (approved or sent to
--   accounting), as original-vs-approved. learning_recorded_at makes that idempotent.
--   These are also the "current" values the AI bookkeeper's side-by-side review shows.
-- Code is guarded: ReceiptLearning probes for both columns. Before this runs, header
--   lessons pause (no wrong lessons); line-item lessons are unaffected.
-- MySQL 5.7 compatible: plain TEXT, no JSON type, no generated columns.

ALTER TABLE expenses
  ADD COLUMN ocr_parsed_json MEDIUMTEXT NULL
    COMMENT 'Extraction shown at capture (parser + learned patterns + suggested category), JSON text; written once',
  ADD COLUMN learning_recorded_at DATETIME NULL
    COMMENT 'When header lessons were recorded from this receipt (once, at approval/send)';

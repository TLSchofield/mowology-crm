-- Migration 1433 (2026-11-04): keep the phone's own OCR text with each comparison.
-- 1407 stored only its length. The receipt-parser replay corpus (scripts/receipt-replay.php) needs the text itself,
-- so every phone read can be re-parsed after each parser change and scored against the approved values. The
-- server's text is already stored on expenses.raw_ocr_json; this is the phone's half. Nullable: rows before this stay null.
ALTER TABLE receipt_ocr_compare ADD COLUMN device_text MEDIUMTEXT NULL AFTER device_text_chars;

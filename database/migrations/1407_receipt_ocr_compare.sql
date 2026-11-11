-- Migration 1407: how the phone's own text read compares with the server's Google Vision read, per receipt.
-- The apps send the text (Apple Vision / ML Kit); in shadow mode the server keeps using Vision and logs
-- field-by-field agreement here. scripts/ocr-compare-report.php turns it into per-engine and per-vendor numbers
-- so the switch to the device read is decided on Tim's real receipts. No receipt text is stored here.
CREATE TABLE receipt_ocr_compare (
  id INT UNSIGNED NOT NULL AUTO_INCREMENT,
  media_id INT UNSIGNED NOT NULL,
  vendor_id INT UNSIGNED NULL,
  engine VARCHAR(20) NOT NULL,
  engine_version VARCHAR(20) NOT NULL DEFAULT '',
  server_source VARCHAR(20) NOT NULL DEFAULT '',
  fields_compared TINYINT UNSIGNED NOT NULL DEFAULT 0,
  fields_agree TINYINT UNSIGNED NOT NULL DEFAULT 0,
  detail_json TEXT NULL,
  device_text_chars INT UNSIGNED NOT NULL DEFAULT 0,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY idx_ocr_compare_vendor (vendor_id),
  KEY idx_ocr_compare_engine (engine, created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- Migration 1115: Receipt line-item learning
-- Date: 2026-09-02
-- Purpose: make the receipt parser's self-learning loop cover LINE ITEMS, not just
-- header fields. Adds:
--   * vendor_product_skus — per-vendor SKU/barcode → product memory (auto-links the
--     next receipt's items with zero clicks; sku_raw was captured but never read)
--   * expense_line_items.ocr_name — the parser's original name, so later renames can
--     be recorded as lessons against what OCR actually produced
--   * expenses.line_items_source — 'ocr' | 'vision' | 'llm' | 'manual' provenance
--   * vendor_parse_profiles line-item accuracy stats, folded into the
--     Tesseract→Vision escalation threshold
-- MySQL 5.7 compatible: no JSON columns, no IF NOT EXISTS on ALTER/INDEX.

CREATE TABLE IF NOT EXISTS vendor_product_skus (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    vendor_id INT NOT NULL,
    sku_raw VARCHAR(64) NOT NULL COMMENT 'Barcode/SKU exactly as printed on the receipt',
    product_id INT NULL COMMENT 'CRM products.id this SKU maps to (NULL = seen but never linked)',
    vendor_product_id INT UNSIGNED NULL COMMENT 'vendor_products.id when a catalog row exists',
    item_name VARCHAR(255) NULL COMMENT 'Most recent line-item name seen with this SKU',
    times_seen INT UNSIGNED NOT NULL DEFAULT 1,
    last_seen_at TIMESTAMP NULL DEFAULT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE INDEX idx_vendor_sku (vendor_id, sku_raw),
    INDEX idx_vps_product (product_id),
    CONSTRAINT fk_vps_vendor FOREIGN KEY (vendor_id) REFERENCES vendors(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

ALTER TABLE expense_line_items
    ADD COLUMN ocr_name VARCHAR(255) NULL
        COMMENT 'Line-item name as the parser produced it, before any user rename'
        AFTER name;

ALTER TABLE expenses
    ADD COLUMN line_items_source VARCHAR(20) NULL
        COMMENT 'Provenance of stored line items: ocr | vision | llm | manual'
        AFTER raw_ocr_json;

ALTER TABLE vendor_parse_profiles
    ADD COLUMN line_item_receipts INT UNSIGNED NOT NULL DEFAULT 0
        COMMENT 'Receipts whose line items were reviewed (saved with items)',
    ADD COLUMN line_item_corrections INT UNSIGNED NOT NULL DEFAULT 0
        COMMENT 'Line-item renames / removals / manual additions across those receipts',
    ADD COLUMN line_item_accuracy DECIMAL(5,2) NULL
        COMMENT 'Percent of parsed line items accepted without correction';

-- Migration 503: Receipt Parse Lessons
-- Self-improving receipt parsing — stores corrections users make to OCR results
-- so the system learns vendor-specific patterns over time.

CREATE TABLE IF NOT EXISTS receipt_parse_lessons (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    vendor_id INT UNSIGNED NULL,
    vendor_name VARCHAR(255) NULL COMMENT 'Vendor name at time of correction (for unmatched vendors)',

    -- What field was corrected
    field_name VARCHAR(50) NOT NULL COMMENT 'total, gst, subtotal, date, vendor, line_item',

    -- What the parser produced vs what the user saved
    ocr_value TEXT NULL COMMENT 'What the parser extracted (null = missed entirely)',
    corrected_value TEXT NULL COMMENT 'What the user saved',

    -- Context from the OCR text that led to the correction
    ocr_context TEXT NULL COMMENT 'Surrounding OCR text near the field (for pattern learning)',

    -- A learned regex or rule derived from corrections (populated after N corrections)
    learned_pattern VARCHAR(500) NULL COMMENT 'Regex or rule derived from repeated corrections',
    pattern_confidence TINYINT UNSIGNED DEFAULT 0 COMMENT '0-100 confidence in the learned pattern',
    times_seen INT UNSIGNED DEFAULT 1 COMMENT 'How many times this correction pattern was observed',

    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,

    INDEX idx_vendor (vendor_id),
    INDEX idx_field (field_name),
    INDEX idx_vendor_field (vendor_id, field_name)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- Vendor parse profiles — aggregated learning per vendor
-- Built from lessons after enough corrections accumulate
CREATE TABLE IF NOT EXISTS vendor_parse_profiles (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    vendor_id INT UNSIGNED NOT NULL,

    -- Learned patterns per field (JSON-like text for MySQL 5.7 compat)
    gst_label VARCHAR(100) NULL COMMENT 'e.g., "GST/HST" or "TAX"',
    gst_position ENUM('same_line', 'next_line', 'unknown') DEFAULT 'unknown',
    has_pst TINYINT(1) DEFAULT 0,
    pst_label VARCHAR(100) NULL,
    date_format VARCHAR(50) NULL COMMENT 'e.g., "DD/MM/YY" or "MM/DD/YYYY"',
    barcode_length TINYINT UNSIGNED NULL COMMENT 'Typical barcode digit count',
    item_suffix_pattern VARCHAR(100) NULL COMMENT 'e.g., "<[A-Z,]+>"',
    discount_prefix VARCHAR(100) NULL COMMENT 'e.g., "RSN:" or "DISCOUNT"',
    noise_patterns TEXT NULL COMMENT 'Comma-separated patterns to skip',
    card_mask_style VARCHAR(50) NULL COMMENT 'e.g., "XXXX" or "****"',

    -- Stats
    total_receipts INT UNSIGNED DEFAULT 0,
    total_corrections INT UNSIGNED DEFAULT 0,
    accuracy_rate DECIMAL(5,2) NULL COMMENT 'Percentage of fields correct without user edit',

    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,

    UNIQUE INDEX idx_vendor_unique (vendor_id),
    CONSTRAINT fk_vpp_vendor FOREIGN KEY (vendor_id) REFERENCES vendors(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

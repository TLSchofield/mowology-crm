/**
 * Migration 1022: Receipt Learning Improvements
 * Date: 2026-05-02
 * Purpose: Add columns to vendor_parse_profiles to support:
 *   - Vendor-specific Tesseract OCR threshold (auto-adjusts based on accuracy)
 *   - Learned accounting category from user corrections
 *   - Category correction count
 */

ALTER TABLE `vendor_parse_profiles`
  ADD COLUMN `tesseract_threshold` TINYINT UNSIGNED NULL DEFAULT NULL
    COMMENT 'Vendor-specific Tesseract gate threshold (NULL = use default 70)',
  ADD COLUMN `learned_accounting_category` VARCHAR(100) NULL DEFAULT NULL
    COMMENT 'Accounting category learned from user corrections (overrides vendor default)',
  ADD COLUMN `category_correction_count` TINYINT UNSIGNED NOT NULL DEFAULT 0
    COMMENT 'Number of times category was corrected for this vendor';

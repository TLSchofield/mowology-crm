-- ============================================================================
-- Migration 922: CMS Block Variants (P1-C)
-- Adds variant support to block types and individual block instances.
-- Variants let one block type have multiple presentation styles
-- (e.g. hero--default, hero--minimal, hero--full-bleed).
-- ============================================================================

-- Add variant columns to block type registry
ALTER TABLE cms_block_types
    ADD COLUMN supports_variants      TINYINT(1) NOT NULL DEFAULT 0
        COMMENT '1 = this block type has variant renderers'
        AFTER is_active,
    ADD COLUMN available_variants_json TEXT NULL
        COMMENT 'JSON array: [{"key":"minimal","label":"Minimal"},...]'
        AFTER supports_variants;

-- Add chosen variant to each block instance
ALTER TABLE cms_blocks
    ADD COLUMN variant VARCHAR(50) NULL DEFAULT NULL
        COMMENT 'Active variant key (NULL = default renderer)'
        AFTER block_type;

-- Seed variant definitions for existing block types
UPDATE cms_block_types SET
    supports_variants       = 1,
    available_variants_json = '[{"key":"default","label":"Full (default)"},{"key":"minimal","label":"Minimal"},{"key":"centered","label":"Centered"}]'
WHERE block_type = 'hero';

UPDATE cms_block_types SET
    supports_variants       = 1,
    available_variants_json = '[{"key":"default","label":"2-column"},{"key":"3col","label":"3-column"},{"key":"list","label":"List"}]'
WHERE block_type = 'feature_grid';

UPDATE cms_block_types SET
    supports_variants       = 1,
    available_variants_json = '[{"key":"default","label":"Cards"},{"key":"compact","label":"Compact list"}]'
WHERE block_type = 'service_cards';

UPDATE cms_block_types SET
    supports_variants       = 1,
    available_variants_json = '[{"key":"default","label":"Carousel"},{"key":"grid","label":"Grid"},{"key":"single","label":"Single quote"}]'
WHERE block_type = 'testimonials';

UPDATE cms_block_types SET
    supports_variants       = 1,
    available_variants_json = '[{"key":"default","label":"Standard"},{"key":"banner","label":"Full-width banner"},{"key":"inline","label":"Inline"}]'
WHERE block_type = 'cta';

UPDATE cms_block_types SET
    supports_variants       = 1,
    available_variants_json = '[{"key":"default","label":"Accordion"},{"key":"open","label":"All open"}]'
WHERE block_type = 'faq';

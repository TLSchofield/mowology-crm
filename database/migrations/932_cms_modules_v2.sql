-- ============================================================
-- CMS Modules v2 — 7 new block types
-- Migration: 932_cms_modules_v2.sql
-- ============================================================

INSERT INTO cms_block_types (block_type, label, description, is_active, supports_variants, available_variants_json, created_at)
VALUES
    ('quote_wizard',      'Quote Wizard',           'Multi-step quote request form: service selection, property details, contact info', 1, 0, NULL, NOW()),
    ('seasonal_calendar', 'Seasonal Calendar',      'Tab-based seasonal service guide with month-highlight strips (Spring/Summer/Fall/Winter)', 1, 0, NULL, NOW()),
    ('pricing_packages',  'Pricing Packages',       'Monthly/annual toggle pricing grid with up to 3 plan tiers and feature lists', 1, 0, NULL, NOW()),
    ('trust_badges',      'Trust Badges',           'Social proof grid (stats + icons) with optional satisfaction guarantee banner', 1, 0, NULL, NOW()),
    ('crew_showcase',     'Crew Showcase',          'Team member cards with emoji avatar, role, bio, and two stat callouts', 1, 0, NULL, NOW()),
    ('lawn_tip_widget',   'Lawn Tip Widget',        'Seasonal lawn tip with weather display panel — location, temp, lawn condition bar', 1, 0, NULL, NOW())
ON DUPLICATE KEY UPDATE
    label = VALUES(label),
    description = VALUES(description),
    is_active = 1;

-- faq--enhanced is a variant of the existing faq block type, no new row needed
-- (renderer file faq--enhanced.php is loaded automatically when variant='enhanced')

-- ============================================================
-- END OF MIGRATION 932
-- ============================================================

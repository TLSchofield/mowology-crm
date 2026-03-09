<?php
/**
 * Run Migration 934 — CMS Modules v2
 * Registers 6 new block types: quote_wizard, seasonal_calendar, pricing_packages,
 * trust_badges, crew_showcase, lawn_tip_widget
 * (faq--enhanced is a variant of the existing faq block — no DB row needed)
 * Admin-only, idempotent.
 */
$__dir = __DIR__;
for ($__i = 0; $__i < 5; $__i++) {
    $__dir = dirname($__dir);
    if (is_file($__dir . '/app/Core/paths.php')) {
        require_once $__dir . '/app/Core/paths.php';
        break;
    }
}
unset($__dir, $__i);

require_once PUBLIC_ROOT . '/loginAuth/auth.php';
requireLogin();
requirePermission('admin');

header('Content-Type: text/plain; charset=utf-8');

$db = getDB();
$results = [];

$blockTypes = [
    ['block_type' => 'quote_wizard',      'label' => 'Quote Wizard',       'description' => 'Multi-step quote request form: service selection, property details, contact info',                'supports_variants' => 0],
    ['block_type' => 'seasonal_calendar', 'label' => 'Seasonal Calendar',  'description' => 'Tab-based seasonal service guide with month-highlight strips (Spring/Summer/Fall/Winter)',      'supports_variants' => 0],
    ['block_type' => 'pricing_packages',  'label' => 'Pricing Packages',   'description' => 'Monthly/annual toggle pricing grid with up to 3 plan tiers and feature lists',                  'supports_variants' => 0],
    ['block_type' => 'trust_badges',      'label' => 'Trust Badges',       'description' => 'Social proof grid (stats + icons) with optional satisfaction guarantee banner',                  'supports_variants' => 0],
    ['block_type' => 'crew_showcase',     'label' => 'Crew Showcase',      'description' => 'Team member cards with emoji avatar, role, bio, and two stat callouts',                         'supports_variants' => 0],
    ['block_type' => 'lawn_tip_widget',   'label' => 'Lawn Tip Widget',    'description' => 'Seasonal lawn tip with weather display panel — location, temp, lawn condition bar',             'supports_variants' => 0],
];

$stmt = $db->prepare("
    INSERT INTO cms_block_types (block_type, label, description, is_active, supports_variants, available_variants_json, created_at)
    VALUES (?, ?, ?, 1, ?, NULL, NOW())
    ON DUPLICATE KEY UPDATE
        label = VALUES(label),
        description = VALUES(description),
        is_active = 1
");

foreach ($blockTypes as $bt) {
    try {
        $stmt->execute([$bt['block_type'], $bt['label'], $bt['description'], $bt['supports_variants']]);
        $results[] = "OK   '{$bt['block_type']}' registered/updated.";
    } catch (PDOException $e) {
        $results[] = "ERR  '{$bt['block_type']}': " . $e->getMessage();
    }
}

$results[] = '';
$results[] = "NOTE faq--enhanced is a variant of the existing 'faq' block type.";
$results[] = "     Renderer: /crm/includes/blocks/faq--enhanced.php (no DB row needed)";

echo "Migration 934 — CMS Modules v2 (Quote Wizard, Seasonal Calendar, Pricing, Trust Badges, Crew, Lawn Tip)\n";
echo str_repeat('-', 70) . "\n\n";
echo implode("\n", $results) . "\n\nDone.";

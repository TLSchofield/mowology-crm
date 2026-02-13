<?php
/**
 * Migration 301: Pricing Rules & Bundles
 *
 * Creates measurement_groups, product_pricing_rules, product_upsells,
 * product_bundles, product_bundle_items tables.
 * Extends property_measurements, quote_line_items, properties with new columns.
 * Safe to run multiple times (uses IF NOT EXISTS and column existence checks).
 *
 * Usage: Visit this URL as admin in your browser.
 */

require_once __DIR__ . '/../loginAuth/auth.php';
require_once __DIR__ . '/includes/functions.php';

requireLogin();
$user = getCurrentUser();

if ($user['role'] !== 'admin') {
    http_response_code(403);
    die('Admin access required.');
}

$db = getDB();
$results = [];

// Helper: check if column exists
function colExists(PDO $db, string $table, string $col): bool {
    $safeTable = preg_replace('/[^a-zA-Z0-9_]/', '', $table);
    $safeCol   = preg_replace('/[^a-zA-Z0-9_]/', '', $col);
    $stmt = $db->query("SHOW COLUMNS FROM `{$safeTable}` LIKE '{$safeCol}'");
    return $stmt && $stmt->rowCount() > 0;
}

// Helper: check if table exists
function tableExists(PDO $db, string $table): bool {
    $safeTable = preg_replace('/[^a-zA-Z0-9_]/', '', $table);
    $stmt = $db->query("SHOW TABLES LIKE '{$safeTable}'");
    return $stmt && $stmt->rowCount() > 0;
}

try {

    // 1. measurement_groups
    if (!tableExists($db, 'measurement_groups')) {
        $db->exec("CREATE TABLE `measurement_groups` (
            `id` int NOT NULL AUTO_INCREMENT,
            `group_key` varchar(50) NOT NULL,
            `group_label` varchar(100) NOT NULL,
            `measurement_types` varchar(500) NOT NULL COMMENT 'Comma-separated property_measurements.measurement_type values',
            `unit` varchar(20) NOT NULL DEFAULT 'sqft' COMMENT 'sqft or linear_ft',
            `sort_order` int DEFAULT 0,
            `is_active` tinyint(1) DEFAULT 1,
            `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
            `updated_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (`id`),
            UNIQUE KEY `uq_group_key` (`group_key`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci");
        $results[] = '✅ Created measurement_groups table';
    } else {
        $results[] = '⏭️ measurement_groups already exists';
    }

    // Seed measurement_groups
    $stmt = $db->query("SELECT COUNT(*) FROM measurement_groups");
    if ($stmt->fetchColumn() == 0) {
        $db->exec("INSERT INTO `measurement_groups` (`group_key`, `group_label`, `measurement_types`, `unit`, `sort_order`) VALUES
            ('lawn_area',     'Lawn & Garden Area',  'lawn,garden',                        'sqft',      1),
            ('hard_surface',  'Hard Surface Area',   'driveway,walkway,patio,parking',     'sqft',      2),
            ('hedge_linear',  'Hedge / Edge Linear', 'hedge',                              'linear_ft', 3),
            ('other_area',    'Other Area',          'other',                              'sqft',      4)");
        $results[] = '✅ Seeded 4 measurement groups';
    } else {
        $results[] = '⏭️ measurement_groups already has data';
    }

    // 2. product_pricing_rules
    if (!tableExists($db, 'product_pricing_rules')) {
        $db->exec("CREATE TABLE `product_pricing_rules` (
            `id` int NOT NULL AUTO_INCREMENT,
            `product_id` int NOT NULL,
            `measurement_group_id` int NOT NULL,
            `pricing_model` enum('flat','per_sqft','per_linear_ft','min_plus_sqft','min_plus_linear_ft') NOT NULL DEFAULT 'per_sqft',
            `price_per_unit` decimal(10,4) DEFAULT 0.0000 COMMENT 'Price per sqft or linear ft',
            `minimum_price` decimal(10,2) DEFAULT 0.00 COMMENT 'Minimum charge regardless of area',
            `included_units` decimal(10,2) DEFAULT 0.00 COMMENT 'Units included in minimum (for min_plus models)',
            `default_frequency` enum('one_off','7_day','14_day','21_day','monthly','seasonal') DEFAULT 'one_off',
            `is_default_for_group` tinyint(1) DEFAULT 0 COMMENT 'Auto-select this product when populating quotes for this group',
            `priority` int DEFAULT 0 COMMENT 'Higher priority wins if multiple rules match',
            `notes` text,
            `is_active` tinyint(1) DEFAULT 1,
            `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
            `updated_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (`id`),
            KEY `idx_product` (`product_id`),
            KEY `idx_group` (`measurement_group_id`),
            KEY `idx_default` (`measurement_group_id`, `is_default_for_group`, `is_active`),
            CONSTRAINT `fk_pricing_rules_product` FOREIGN KEY (`product_id`) REFERENCES `products` (`id`) ON DELETE CASCADE,
            CONSTRAINT `fk_pricing_rules_group` FOREIGN KEY (`measurement_group_id`) REFERENCES `measurement_groups` (`id`) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci");
        $results[] = '✅ Created product_pricing_rules table';
    } else {
        $results[] = '⏭️ product_pricing_rules already exists';
    }

    // 3. product_upsells
    if (!tableExists($db, 'product_upsells')) {
        $db->exec("CREATE TABLE `product_upsells` (
            `id` int NOT NULL AUTO_INCREMENT,
            `base_product_id` int NOT NULL,
            `upsell_product_id` int NOT NULL,
            `upsell_type` enum('recommended','addon','upgrade') DEFAULT 'recommended',
            `display_text` varchar(255) DEFAULT NULL COMMENT 'Override text shown to customer',
            `default_checked` tinyint(1) DEFAULT 0 COMMENT 'Pre-selected on customer view',
            `sort_order` int DEFAULT 0,
            `is_active` tinyint(1) DEFAULT 1,
            `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (`id`),
            UNIQUE KEY `uq_upsell` (`base_product_id`, `upsell_product_id`),
            KEY `idx_base` (`base_product_id`),
            KEY `idx_upsell` (`upsell_product_id`),
            CONSTRAINT `fk_upsell_base` FOREIGN KEY (`base_product_id`) REFERENCES `products` (`id`) ON DELETE CASCADE,
            CONSTRAINT `fk_upsell_product` FOREIGN KEY (`upsell_product_id`) REFERENCES `products` (`id`) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci");
        $results[] = '✅ Created product_upsells table';
    } else {
        $results[] = '⏭️ product_upsells already exists';
    }

    // 4. product_bundles
    if (!tableExists($db, 'product_bundles')) {
        $db->exec("CREATE TABLE `product_bundles` (
            `id` int NOT NULL AUTO_INCREMENT,
            `bundle_name` varchar(200) NOT NULL,
            `tier` enum('good','better','best','custom') DEFAULT 'custom',
            `description` text,
            `discount_type` enum('percentage','fixed') DEFAULT 'percentage',
            `discount_value` decimal(10,2) DEFAULT 0.00 COMMENT 'Percentage off or fixed dollar off',
            `is_active` tinyint(1) DEFAULT 1,
            `sort_order` int DEFAULT 0,
            `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
            `updated_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (`id`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci");
        $results[] = '✅ Created product_bundles table';
    } else {
        $results[] = '⏭️ product_bundles already exists';
    }

    // 5. product_bundle_items
    if (!tableExists($db, 'product_bundle_items')) {
        $db->exec("CREATE TABLE `product_bundle_items` (
            `id` int NOT NULL AUTO_INCREMENT,
            `bundle_id` int NOT NULL,
            `product_id` int NOT NULL,
            `quantity_multiplier` decimal(10,2) DEFAULT 1.00 COMMENT 'Multiplier against measurement-derived quantity',
            `override_price` decimal(10,2) DEFAULT NULL COMMENT 'NULL = use product pricing rule',
            `sort_order` int DEFAULT 0,
            PRIMARY KEY (`id`),
            UNIQUE KEY `uq_bundle_item` (`bundle_id`, `product_id`),
            KEY `idx_bundle` (`bundle_id`),
            KEY `idx_product` (`product_id`),
            CONSTRAINT `fk_bundle_item_bundle` FOREIGN KEY (`bundle_id`) REFERENCES `product_bundles` (`id`) ON DELETE CASCADE,
            CONSTRAINT `fk_bundle_item_product` FOREIGN KEY (`product_id`) REFERENCES `products` (`id`) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci");
        $results[] = '✅ Created product_bundle_items table';
    } else {
        $results[] = '⏭️ product_bundle_items already exists';
    }

    // 6. Extend property_measurements
    if (!colExists($db, 'property_measurements', 'measurement_shape')) {
        $db->exec("ALTER TABLE `property_measurements` ADD COLUMN `measurement_shape` enum('polygon','rectangle','polyline') DEFAULT 'polygon' AFTER `measurement_type`");
        $results[] = '✅ Added property_measurements.measurement_shape';
    } else {
        $results[] = '⏭️ property_measurements.measurement_shape already exists';
    }

    if (!colExists($db, 'property_measurements', 'linear_ft')) {
        $db->exec("ALTER TABLE `property_measurements` ADD COLUMN `linear_ft` decimal(10,2) DEFAULT NULL AFTER `perimeter_ft`");
        $results[] = '✅ Added property_measurements.linear_ft';
    } else {
        $results[] = '⏭️ property_measurements.linear_ft already exists';
    }

    if (!colExists($db, 'property_measurements', 'measurement_group_key')) {
        $db->exec("ALTER TABLE `property_measurements` ADD COLUMN `measurement_group_key` varchar(50) DEFAULT NULL AFTER `measurement_shape`");
        $results[] = '✅ Added property_measurements.measurement_group_key';
    } else {
        $results[] = '⏭️ property_measurements.measurement_group_key already exists';
    }

    // 7. Extend quote_line_items
    $qliColumns = [
        ['product_id',           'int DEFAULT NULL',            'quote_id'],
        ['pricing_rule_id',      'int DEFAULT NULL',            'product_id'],
        ['measurement_group_key','varchar(50) DEFAULT NULL',    'pricing_rule_id'],
        ['units_used',           'decimal(12,2) DEFAULT NULL',  'measurement_group_key'],
        ['price_per_unit',       'decimal(10,4) DEFAULT NULL',  'units_used'],
        ['minimum_applied',      'tinyint(1) DEFAULT 0',        'price_per_unit'],
        ['included_units',       'decimal(10,2) DEFAULT NULL',  'minimum_applied'],
        ['pricing_snapshot',     'text',                         'included_units'],
        ['bundle_id',            'int DEFAULT NULL',             'pricing_snapshot'],
        ['is_upsell',            'tinyint(1) DEFAULT 0',        'bundle_id'],
    ];
    foreach ($qliColumns as [$col, $def, $after]) {
        if (!colExists($db, 'quote_line_items', $col)) {
            $db->exec("ALTER TABLE `quote_line_items` ADD COLUMN `{$col}` {$def} AFTER `{$after}`");
            $results[] = "✅ Added quote_line_items.{$col}";
        } else {
            $results[] = "⏭️ quote_line_items.{$col} already exists";
        }
    }

    // 8. Extend properties
    if (!colExists($db, 'properties', 'total_hard_surface_sqft')) {
        $db->exec("ALTER TABLE `properties` ADD COLUMN `total_hard_surface_sqft` decimal(12,2) DEFAULT 0 AFTER `total_driveway_sqft`");
        $results[] = '✅ Added properties.total_hard_surface_sqft';
    } else {
        $results[] = '⏭️ properties.total_hard_surface_sqft already exists';
    }

    if (!colExists($db, 'properties', 'total_hedge_linear_ft')) {
        $db->exec("ALTER TABLE `properties` ADD COLUMN `total_hedge_linear_ft` decimal(10,2) DEFAULT 0 AFTER `total_hard_surface_sqft`");
        $results[] = '✅ Added properties.total_hedge_linear_ft';
    } else {
        $results[] = '⏭️ properties.total_hedge_linear_ft already exists';
    }

    if (!colExists($db, 'properties', 'total_other_sqft')) {
        $db->exec("ALTER TABLE `properties` ADD COLUMN `total_other_sqft` decimal(12,2) DEFAULT 0 AFTER `total_hedge_linear_ft`");
        $results[] = '✅ Added properties.total_other_sqft';
    } else {
        $results[] = '⏭️ properties.total_other_sqft already exists';
    }

    // 9. Backfill measurement_group_key on existing data
    $updated = $db->exec("UPDATE `property_measurements` SET `measurement_group_key` = 'lawn_area'
        WHERE `measurement_type` IN ('lawn', 'garden') AND `measurement_group_key` IS NULL");
    $updated += $db->exec("UPDATE `property_measurements` SET `measurement_group_key` = 'hard_surface'
        WHERE `measurement_type` IN ('driveway', 'walkway', 'patio', 'parking') AND `measurement_group_key` IS NULL");
    $updated += $db->exec("UPDATE `property_measurements` SET `measurement_group_key` = 'hedge_linear'
        WHERE `measurement_type` = 'hedge' AND `measurement_group_key` IS NULL");
    $updated += $db->exec("UPDATE `property_measurements` SET `measurement_group_key` = 'other_area'
        WHERE `measurement_type` = 'other' AND `measurement_group_key` IS NULL");
    $results[] = "✅ Backfilled {$updated} measurement rows with group_key";

} catch (PDOException $e) {
    $results[] = '❌ Error: ' . $e->getMessage();
}

// Output results
header('Content-Type: text/html; charset=utf-8');
echo "<!DOCTYPE html><html><head><title>Migration 301</title>";
echo "<style>body{font-family:monospace;padding:40px;background:#f5f5f5}h1{color:#2D8659}li{margin:8px 0;font-size:14px}.ok{color:#2D8659}.skip{color:#666}.err{color:red}</style>";
echo "</head><body><h1>Migration 301: Pricing Rules & Bundles</h1><ul>";
foreach ($results as $r) {
    $cls = strpos($r, '✅') !== false ? 'ok' : (strpos($r, '❌') !== false ? 'err' : 'skip');
    echo "<li class='{$cls}'>{$r}</li>";
}
echo "</ul><p><a href='/crm/products/products-manager.php'>← Back to Products</a></p></body></html>";

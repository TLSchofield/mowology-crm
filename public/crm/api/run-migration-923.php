<?php
/**
 * Run Migration 923 — CMS Phase 2 Block Types + Full-Text Index
 * Registers recent_projects, quote_cta, service_area_map block types.
 * Adds FULLTEXT index on cms_pages (title, meta_description, meta_keywords).
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
session_write_close();
requirePermission('admin');

header('Content-Type: text/plain; charset=utf-8');

$db = getDB();
$results = [];

// 1. Register new block types
$blockTypes = [
    ['block_type' => 'recent_projects',  'label' => 'Recent Projects',    'description' => 'Dynamically pulls latest portfolio items from the database', 'supports_variants' => 0, 'available_variants_json' => null],
    ['block_type' => 'quote_cta',        'label' => 'Quote CTA',          'description' => 'Dynamic call-to-action with live phone number and seasonal offers', 'supports_variants' => 1, 'available_variants_json' => '["green","dark","light"]'],
    ['block_type' => 'service_area_map', 'label' => 'Service Area Map',   'description' => 'Interactive Leaflet.js map of service areas (no API key required)', 'supports_variants' => 0, 'available_variants_json' => null],
];

$stmt = $db->prepare("
    INSERT INTO cms_block_types (block_type, label, description, is_active, supports_variants, available_variants_json, created_at)
    VALUES (?, ?, ?, 1, ?, ?, NOW())
    ON DUPLICATE KEY UPDATE
        label = VALUES(label),
        description = VALUES(description),
        is_active = 1
");

foreach ($blockTypes as $bt) {
    try {
        $stmt->execute([
            $bt['block_type'],
            $bt['label'],
            $bt['description'],
            $bt['supports_variants'],
            $bt['available_variants_json'],
        ]);
        $results[] = "OK   Block type '{$bt['block_type']}' registered/updated.";
    } catch (\PDOException $e) {
        $results[] = "ERR  Block type '{$bt['block_type']}': " . $e->getMessage();
    }
}

// 2. Add FULLTEXT index (idempotent — skip if already exists)
try {
    $db->exec("ALTER TABLE cms_pages ADD FULLTEXT INDEX ft_cms_pages_search (title, meta_description, meta_keywords)");
    $results[] = "OK   FULLTEXT index ft_cms_pages_search created on cms_pages.";
} catch (\PDOException $e) {
    $msg = $e->getMessage();
    if (strpos($msg, 'Duplicate key name') !== false || strpos($msg, '1061') !== false) {
        $results[] = "SKIP FULLTEXT index already exists — skipping.";
    } else {
        $results[] = "ERR  FULLTEXT index: " . $msg;
    }
}

echo "Migration 923 — CMS Phase 2 Block Types + Full-Text Index\n";
echo str_repeat('─', 60) . "\n\n";
echo implode("\n", $results) . "\n\nDone.";

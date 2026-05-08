<?php
/**
 * Migration 1021 — Upsells: bundles as upsell targets
 *
 * Adds `upsell_bundle_id` to product_upsells. Either upsell_product_id OR
 * upsell_bundle_id is set (not both). On the customer quote, a bundle upsell
 * expands into multiple line items at the bundle price.
 */
declare(strict_types=1);

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

$statements = [
    "ALTER TABLE product_upsells ADD COLUMN upsell_bundle_id INT NULL
        COMMENT 'Alternative to upsell_product_id — references product_bundles.id'",
    "ALTER TABLE product_upsells MODIFY COLUMN upsell_product_id INT NULL
        COMMENT 'Either this OR upsell_bundle_id must be set'",
];

foreach ($statements as $i => $sql) {
    try {
        $db->exec($sql);
        $results[] = "OK  [$i] " . substr(trim($sql), 0, 80) . '...';
    } catch (PDOException $e) {
        if (strpos($e->getMessage(), 'Duplicate column') !== false ||
            strpos($e->getMessage(), '1060') !== false) {
            $results[] = "SKIP[$i] Already applied";
        } else {
            $results[] = "ERR [$i] " . $e->getMessage();
        }
    }
}

echo "Migration 1021 — Bundle Upsells:\n\n" . implode("\n", $results) . "\n\nDone.";

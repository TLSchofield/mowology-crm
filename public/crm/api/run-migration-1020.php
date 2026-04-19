<?php
/**
 * Migration 1020 — Upsell bundle pricing
 * Adds bundled_price (pre-accept discount) and is_popular (social proof badge)
 * to product_upsells.
 *
 * Admin-only, one-time use.
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
    // Bundled price — what the customer pays if added BEFORE signing
    "ALTER TABLE product_upsells ADD COLUMN bundled_price DECIMAL(10,2) NULL
        COMMENT 'Discounted price when added to quote before acceptance. NULL = use product base_price'",

    // Popular badge — set by cron based on historical adoption rate
    "ALTER TABLE product_upsells ADD COLUMN is_popular TINYINT(1) NOT NULL DEFAULT 0
        COMMENT 'Shows a \"Most popular\" badge in the customer UI'",

    // Track when an upsell was added (pre-accept vs post-accept)
    "ALTER TABLE quote_line_items ADD COLUMN added_post_accept TINYINT(1) NOT NULL DEFAULT 0
        COMMENT 'TRUE if this upsell was added after the quote was accepted (at regular price)'",

    // Backfill bundled_price = 85% of base_price for existing upsells (sensible default, tune later)
    "UPDATE product_upsells pu
        JOIN products p ON pu.upsell_product_id = p.id
        SET pu.bundled_price = ROUND(p.base_price * 0.85, 2)
        WHERE pu.bundled_price IS NULL",
];

foreach ($statements as $i => $sql) {
    try {
        $affected = $db->exec($sql);
        $results[] = "OK  [$i] " . substr(trim($sql), 0, 80) . '...' . ($affected !== false ? " ({$affected} rows)" : '');
    } catch (PDOException $e) {
        if (strpos($e->getMessage(), 'Duplicate column') !== false ||
            strpos($e->getMessage(), '1060') !== false) {
            $results[] = "SKIP[$i] Column already exists — " . substr(trim($sql), 0, 60);
        } else {
            $results[] = "ERR [$i] " . $e->getMessage() . ' | SQL: ' . substr(trim($sql), 0, 80);
        }
    }
}

echo "Migration 1020 — Upsell Bundle Pricing:\n\n" . implode("\n", $results) . "\n\nDone.";

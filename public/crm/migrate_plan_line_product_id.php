<?php
/**
 * Migration: Add product_id to plan_line_items
 *
 * Plans created directly (not from a quote) have no quote_line_item_id,
 * which breaks the product tracking inheritance chain. This adds a direct
 * product_id FK and backfills it from both quote_line_items and name matching.
 *
 * Safe to run multiple times.
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

try {
    // 1. Add product_id column to plan_line_items
    if (!colExists($db, 'plan_line_items', 'product_id')) {
        $db->exec("ALTER TABLE `plan_line_items` ADD COLUMN `product_id` INT NULL AFTER `quote_line_item_id`");
        $db->exec("ALTER TABLE `plan_line_items` ADD INDEX `idx_product_id` (`product_id`)");
        $results[] = '✅ Added plan_line_items.product_id column + index';
    } else {
        $results[] = '⏭️ plan_line_items.product_id already exists';
    }

    // 2. Backfill product_id from quote_line_items (for quote-based plans)
    $stmt = $db->exec("
        UPDATE plan_line_items pli
        JOIN quote_line_items qli ON pli.quote_line_item_id = qli.id
        SET pli.product_id = qli.product_id
        WHERE pli.product_id IS NULL
          AND pli.quote_line_item_id IS NOT NULL
          AND qli.product_id IS NOT NULL
    ");
    $results[] = "✅ Backfilled product_id from quote_line_items: {$stmt} rows updated";

    // 3. Backfill product_id by matching service_type to products.name (for direct-created plans)
    $stmt = $db->exec("
        UPDATE plan_line_items pli
        JOIN products p ON LOWER(TRIM(pli.service_type)) = LOWER(TRIM(p.name))
        SET pli.product_id = p.id
        WHERE pli.product_id IS NULL
    ");
    $results[] = "✅ Backfilled product_id by name matching: {$stmt} rows updated";

    // 4. Show current state
    $check = $db->query("
        SELECT pli.id, pli.plan_id, pli.service_type, pli.product_id, pli.quote_line_item_id,
               p.name AS product_name
        FROM plan_line_items pli
        LEFT JOIN products p ON pli.product_id = p.id
        ORDER BY pli.plan_id, pli.sort_order
    ");
    $rows = $check->fetchAll(PDO::FETCH_ASSOC);
    $results[] = '--- Current plan_line_items ---';
    foreach ($rows as $r) {
        $linked = $r['product_id'] ? "✅ product #{$r['product_id']} ({$r['product_name']})" : "❌ no product linked";
        $results[] = "  PLI #{$r['id']} plan={$r['plan_id']} service=\"{$r['service_type']}\" qli={$r['quote_line_item_id']} → {$linked}";
    }

} catch (Exception $e) {
    $results[] = '❌ Error: ' . htmlspecialchars($e->getMessage());
}
?>
<!DOCTYPE html>
<html><head><title>Migration: Plan Line Items product_id</title></head>
<body style="font-family: monospace; padding: 2rem;">
<h2>Migration: Add product_id to plan_line_items</h2>
<ul>
<?php foreach ($results as $r): ?>
    <li><?= $r ?></li>
<?php endforeach; ?>
</ul>
<p><a href="/crm/jobs/index.php">← Back to Plans</a></p>
</body></html>

<?php
/**
 * Seeds the "Landscape Maintenance Program — March to December (14 visits)" quote template.
 *
 * It is a catalog product, so it appears under "+ From Template" on the quote workflow
 * (public/crm/quote-workflow.php) and adds one line per property at $2,160.00 + GST,
 * payable as 12 monthly payments of $180.00 + GST.
 *
 * CLI only, run once per business database:
 *   php scripts/seed_landscape_maintenance_template.php --dry-run   (show what would be written)
 *   php scripts/seed_landscape_maintenance_template.php             (write)
 *
 * Idempotent: if a product with the same name already exists, nothing is inserted.
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit("CLI only\n");
}

$root = dirname(__DIR__);
require_once $root . '/app/Core/paths.php';
require_once PUBLIC_ROOT . '/app_config/config.php';

$dryRun = in_array('--dry-run', $argv ?? [], true);

$productName  = 'Landscape Maintenance Program — March to December (14 visits)';
$categoryName = 'Maintenance';
$annualPrice  = 2160.00;   // 12 payments x $180.00, before GST
$description  = implode("\n", [
    'Landscape maintenance, March to December: 14 visits per property.',
    '• Fall clean-up',
    '• Shrub maintenance',
    '• Bed maintenance',
    '• Trees: pruning and trimming to 12 ft only. Work above 12 ft is not included.',
    'Price: $2,160.00 + GST per property, payable as 12 monthly payments of $180.00 + GST.',
]);

$db = Database::pdo();

$existing = $db->prepare("SELECT id FROM products WHERE name = ? LIMIT 1");
$existing->execute([$productName]);
if ($existingId = $existing->fetchColumn()) {
    echo "Already present (product id {$existingId}): {$productName}\nNothing to do.\n";
    exit(0);
}

$catStmt = $db->prepare("SELECT id FROM product_categories WHERE name = ? LIMIT 1");
$catStmt->execute([$categoryName]);
$categoryId = $catStmt->fetchColumn();

echo ($dryRun ? "[dry run] " : '') . "Product: {$productName}\n";
echo "Price: \${$annualPrice} before GST (12 x \$180.00)\n";
echo "Category: {$categoryName}" . ($categoryId ? " (id {$categoryId})" : " (will be created)") . "\n";

if ($dryRun) {
    echo "Dry run: no rows written.\n";
    exit(0);
}

$db->beginTransaction();
try {
    if (!$categoryId) {
        $db->prepare("INSERT INTO product_categories (name) VALUES (?)")->execute([$categoryName]);
        $categoryId = $db->lastInsertId();
    }

    $db->prepare("
        INSERT INTO products (name, description, base_price, category_id, active, is_archived, display_order)
        VALUES (?, ?, ?, ?, 1, 0, 0)
    ")->execute([$productName, $description, $annualPrice, $categoryId]);

    $productId = $db->lastInsertId();
    $db->commit();
    echo "Created product id {$productId}.\n";
} catch (Throwable $e) {
    $db->rollBack();
    fwrite(STDERR, "Failed, nothing written: " . $e->getMessage() . "\n");
    exit(1);
}

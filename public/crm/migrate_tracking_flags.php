<?php
/**
 * Migration: Tracking Flags for Products & Job Plans
 *
 * Adds service-level tracking defaults to products (require_clock_in, require_gps,
 * require_photos, tracking_level) and nullable override columns to job_plans.
 * Safe to run multiple times (uses column existence checks).
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

try {

    // ── Products table: service-level tracking defaults ──────────────

    if (!colExists($db, 'products', 'tracking_level')) {
        $db->exec("ALTER TABLE `products` ADD COLUMN `tracking_level` VARCHAR(20) NOT NULL DEFAULT 'standard'");
        $results[] = '✅ Added products.tracking_level';
    } else {
        $results[] = '⏭️ products.tracking_level already exists';
    }

    if (!colExists($db, 'products', 'require_clock_in')) {
        $db->exec("ALTER TABLE `products` ADD COLUMN `require_clock_in` TINYINT(1) NOT NULL DEFAULT 0");
        $results[] = '✅ Added products.require_clock_in';
    } else {
        $results[] = '⏭️ products.require_clock_in already exists';
    }

    if (!colExists($db, 'products', 'require_gps')) {
        $db->exec("ALTER TABLE `products` ADD COLUMN `require_gps` TINYINT(1) NOT NULL DEFAULT 0");
        $results[] = '✅ Added products.require_gps';
    } else {
        $results[] = '⏭️ products.require_gps already exists';
    }

    if (!colExists($db, 'products', 'require_photos')) {
        $db->exec("ALTER TABLE `products` ADD COLUMN `require_photos` TINYINT(1) NOT NULL DEFAULT 0");
        $results[] = '✅ Added products.require_photos';
    } else {
        $results[] = '⏭️ products.require_photos already exists';
    }

    // ── Job Plans table: nullable override columns ───────────────────

    if (!colExists($db, 'job_plans', 'tracking_level_override')) {
        $db->exec("ALTER TABLE `job_plans` ADD COLUMN `tracking_level_override` VARCHAR(20) DEFAULT NULL");
        $results[] = '✅ Added job_plans.tracking_level_override';
    } else {
        $results[] = '⏭️ job_plans.tracking_level_override already exists';
    }

    if (!colExists($db, 'job_plans', 'require_clock_in_override')) {
        $db->exec("ALTER TABLE `job_plans` ADD COLUMN `require_clock_in_override` TINYINT(1) DEFAULT NULL");
        $results[] = '✅ Added job_plans.require_clock_in_override';
    } else {
        $results[] = '⏭️ job_plans.require_clock_in_override already exists';
    }

    if (!colExists($db, 'job_plans', 'require_gps_override')) {
        $db->exec("ALTER TABLE `job_plans` ADD COLUMN `require_gps_override` TINYINT(1) DEFAULT NULL");
        $results[] = '✅ Added job_plans.require_gps_override';
    } else {
        $results[] = '⏭️ job_plans.require_gps_override already exists';
    }

    if (!colExists($db, 'job_plans', 'require_photos_override')) {
        $db->exec("ALTER TABLE `job_plans` ADD COLUMN `require_photos_override` TINYINT(1) DEFAULT NULL");
        $results[] = '✅ Added job_plans.require_photos_override';
    } else {
        $results[] = '⏭️ job_plans.require_photos_override already exists';
    }

} catch (Exception $e) {
    $results[] = '❌ Error: ' . htmlspecialchars($e->getMessage());
}

?>
<!DOCTYPE html>
<html><head><title>Migration: Tracking Flags</title></head>
<body style="font-family: monospace; padding: 2rem;">
<h2>Migration: Tracking Flags for Products &amp; Job Plans</h2>
<ul>
<?php foreach ($results as $r): ?>
    <li><?= $r ?></li>
<?php endforeach; ?>
</ul>
<p><a href="/crm/products/products-manager.php">← Back to Products</a></p>
</body></html>

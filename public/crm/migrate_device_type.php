<?php
/**
 * Migration: Add device_type column to users table
 *
 * Adds a device_type column to differentiate between 'personal' (phone)
 * and 'truck' (permanently mounted tablet) devices.
 * Truck devices track GPS continuously regardless of clock-in status.
 *
 * Safe to run multiple times (checks column existence first).
 * Usage: Visit this URL as admin in your browser.
 */
declare(strict_types=1);

require_once dirname(__DIR__) . '/loginAuth/auth.php';
require_once __DIR__ . '/includes/functions.php';

requireLogin();
$user = getCurrentUser();

if ($user['role'] !== 'admin') {
    die('Admin access required.');
}

$db = getDB();
$results = [];

function colExists(PDO $db, string $table, string $col): bool {
    $stmt = $db->query("SHOW COLUMNS FROM `{$table}` LIKE '{$col}'");
    return $stmt->rowCount() > 0;
}

try {
    if (!colExists($db, 'users', 'device_type')) {
        $db->exec("ALTER TABLE `users` ADD COLUMN `device_type` VARCHAR(20) NOT NULL DEFAULT 'personal'");
        $results[] = '✅ Added users.device_type (default: personal)';
    } else {
        $results[] = '⏭️ users.device_type already exists';
    }
} catch (PDOException $e) {
    $results[] = '❌ Error: ' . $e->getMessage();
}

echo '<h2>Migration: Device Type</h2>';
echo '<ul>';
foreach ($results as $r) {
    echo '<li>' . $r . '</li>';
}
echo '</ul>';
echo '<p><a href="/crm/team/">← Back to Team</a></p>';

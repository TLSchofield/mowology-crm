<?php
/**
 * Runner for migration 1013 — add company_tagline to business_settings.
 * Admin-only. Safe to re-run.
 */
declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/loginAuth/auth.php';
requireLogin();
$user = getCurrentUser();
if (($user['role'] ?? '') !== 'admin') {
    http_response_code(403);
    die('Admin only.');
}

header('Content-Type: text/plain; charset=utf-8');

$db = getDB();

echo "=== Migration 1013 — business_settings.company_tagline ===\n\n";

// Check if the column already exists
$exists = false;
try {
    $col = $db->query("SHOW COLUMNS FROM business_settings LIKE 'company_tagline'")->fetch();
    $exists = !empty($col);
} catch (Throwable $e) {
    echo "FAILED to check column: " . $e->getMessage() . "\n";
    exit;
}

if ($exists) {
    echo "SKIP — column company_tagline already exists.\n";
} else {
    try {
        $db->exec("ALTER TABLE business_settings ADD COLUMN company_tagline VARCHAR(255) NULL AFTER company_address");
        echo "OK — added company_tagline VARCHAR(255) NULL.\n";
    } catch (Throwable $e) {
        echo "FAILED: " . $e->getMessage() . "\n";
        exit;
    }
}

echo "\nDone.\n";

<?php
/**
 * Migration: Add location_ping_rate column to users table
 *
 * Adds per-user GPS ping rate: low (10min), medium (2min), high (30s).
 * Default is 'high' to match existing 30-second behavior.
 *
 * Safe to run multiple times — checks if column exists first.
 */
require_once dirname(__DIR__) . '/loginAuth/auth.php';
requireLogin();
$user = getCurrentUser();
if ($user['role'] !== 'admin') {
    die('Admin access required');
}

$db = getDB();

// Check if column already exists
$cols = $db->query("SHOW COLUMNS FROM users LIKE 'location_ping_rate'")->fetchAll();
if (count($cols) > 0) {
    echo "<p style='color:green;'>&#10004; Column <code>location_ping_rate</code> already exists. Nothing to do.</p>";
} else {
    $db->exec("ALTER TABLE users ADD COLUMN location_ping_rate ENUM('low','medium','high') NOT NULL DEFAULT 'high'");
    echo "<p style='color:green;'>&#10004; Added <code>location_ping_rate</code> column to <code>users</code> table.</p>";
}

echo "<p><a href='/crm/team/'>Back to Team</a></p>";

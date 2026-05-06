<?php
/**
 * Migration: Add email_opened_at column to quotes table
 * For tracking when a customer opens the quote email (via 1x1 pixel).
 *
 * Run once: /crm/api/run-migration-quote-tracking.php
 */
require_once dirname(__DIR__) . '/../loginAuth/auth.php';
requireLogin();
session_write_close();

$db = getDB();
$results = [];

// Add email_opened_at column
try {
    $cols = $db->query("SHOW COLUMNS FROM quotes LIKE 'email_opened_at'")->fetchAll();
    if (empty($cols)) {
        $db->exec("ALTER TABLE quotes ADD COLUMN email_opened_at DATETIME NULL AFTER viewed_at");
        $results[] = "Added quotes.email_opened_at column";
    } else {
        $results[] = "quotes.email_opened_at already exists — skipped";
    }
} catch (PDOException $e) {
    $results[] = "Error: " . $e->getMessage();
}

header('Content-Type: text/plain');
echo implode("\n", $results) . "\n";
echo "\nDone.\n";

<?php
/**
 * Migration 920: Add is_driver flag to users table.
 * Allows admin to designate which users see the Driver Portal on login.
 */
require_once dirname(__DIR__) . '/../../loginAuth/auth.php';
requireLogin();
$user = getCurrentUser();
if ($user['role'] !== 'admin') { http_response_code(403); die('Admin only'); }

$db = getDB();
$results = [];

try {
    $db->exec("ALTER TABLE users ADD COLUMN is_driver TINYINT(1) NOT NULL DEFAULT 0");
    $results[] = 'OK: Added is_driver column to users';
} catch (PDOException $e) {
    if (strpos($e->getMessage(), 'Duplicate column') !== false || strpos($e->getMessage(), 'already exists') !== false) {
        $results[] = 'SKIP: is_driver column already exists';
    } else {
        $results[] = 'ERROR: ' . $e->getMessage();
    }
}

echo '<pre>' . implode("\n", $results) . '</pre>';
echo '<p>Done. <a href="/crm/">Back to CRM</a></p>';

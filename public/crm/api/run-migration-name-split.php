<?php
/**
 * Migration: Add first_name + last_name columns to users table
 * Splits existing full_name into first/last, keeps full_name synced.
 */
declare(strict_types=1);
header('Content-Type: application/json');

require_once dirname(__DIR__) . '/../loginAuth/auth.php';
requireLogin();
$user = getCurrentUser();
session_write_close();
if ($user['role'] !== 'admin') {
    echo json_encode(['error' => 'Admin only']);
    exit;
}

$db = getDB();

// Check if columns already exist
$cols = $db->query("SHOW COLUMNS FROM users")->fetchAll(PDO::FETCH_COLUMN);
$results = [];

if (!in_array('first_name', $cols)) {
    $db->exec("ALTER TABLE users ADD COLUMN first_name VARCHAR(100) DEFAULT NULL AFTER full_name");
    $results[] = 'Added first_name column';
}

if (!in_array('last_name', $cols)) {
    $db->exec("ALTER TABLE users ADD COLUMN last_name VARCHAR(100) DEFAULT NULL AFTER first_name");
    $results[] = 'Added last_name column';
}

// Populate from existing full_name where first/last are empty
$stmt = $db->query("SELECT id, full_name FROM users WHERE first_name IS NULL AND full_name IS NOT NULL AND full_name != ''");
$rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

$updateStmt = $db->prepare("UPDATE users SET first_name = ?, last_name = ? WHERE id = ?");
foreach ($rows as $row) {
    $parts = explode(' ', trim($row['full_name']), 2);
    $first = $parts[0];
    $last  = $parts[1] ?? '';
    $updateStmt->execute([$first, $last, $row['id']]);
    $results[] = "Split '{$row['full_name']}' → first='{$first}', last='{$last}' (user #{$row['id']})";
}

echo json_encode(['success' => true, 'results' => $results]);

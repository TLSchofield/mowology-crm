<?php
/**
 * One-shot migration runner — 971: driver's licence fields on users table.
 * DELETE after running.
 */
if (!defined('APP_ROOT')) {
    $__dir = __DIR__;
    for ($__i = 0; $__i < 5; $__i++) {
        $__dir = dirname($__dir);
        if (is_file($__dir . '/app/Core/paths.php')) {
            require_once $__dir . '/app/Core/paths.php';
            break;
        }
    }
    unset($__dir, $__i);
}
require_once PUBLIC_ROOT . '/loginAuth/auth.php';
requireLogin();
if (getCurrentUser()['role'] !== 'admin') { http_response_code(403); exit('Admins only'); }

$db = getDB();
$results = [];

$steps = [
    "ALTER TABLE users ADD COLUMN dl_number_encrypted TEXT NULL AFTER notes",
    "ALTER TABLE users ADD COLUMN dl_class VARCHAR(10) NULL AFTER dl_number_encrypted",
    "ALTER TABLE users ADD COLUMN dl_province VARCHAR(2) NULL AFTER dl_class",
    "ALTER TABLE users ADD COLUMN dl_expiry DATE NULL AFTER dl_province",
];

foreach ($steps as $sql) {
    try {
        $db->exec($sql);
        $results[] = ['sql' => $sql, 'status' => 'OK'];
    } catch (PDOException $e) {
        $results[] = ['sql' => $sql, 'status' => strpos($e->getMessage(), 'Duplicate column') !== false ? 'SKIPPED (already exists)' : 'ERROR: ' . $e->getMessage()];
    }
}

echo '<pre>';
foreach ($results as $r) {
    echo ($r['status'] === 'OK' ? '✅' : (strpos($r['status'], 'SKIPPED') === 0 ? '⏭️' : '❌')) . ' ' . $r['status'] . "\n   " . $r['sql'] . "\n\n";
}
echo '</pre>';
echo '<p>Done. Delete this file.</p>';

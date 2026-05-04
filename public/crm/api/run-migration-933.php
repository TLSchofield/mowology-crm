<?php
/**
 * Run Migration 933 — Idempotency Keys
 * Creates idempotency_keys table for timer/clock duplicate-write protection.
 * Admin-only, one-time use. Safe to run multiple times (CREATE TABLE IF NOT EXISTS).
 */
$__dir = __DIR__;
for ($__i = 0; $__i < 5; $__i++) {
    $__dir = dirname($__dir);
    if (is_file($__dir . '/app/Core/paths.php')) {
        require_once $__dir . '/app/Core/paths.php';
        break;
    }
}
unset($__dir, $__i);

require_once PUBLIC_ROOT . '/loginAuth/auth.php';
requireLogin();
requirePermission('admin');

header('Content-Type: text/plain; charset=utf-8');

$db = getDB();
$results = [];

$statements = [
    "CREATE TABLE IF NOT EXISTS `idempotency_keys` (
        `idem_key`   VARCHAR(64)  NOT NULL,
        `endpoint`   VARCHAR(80)  NOT NULL,
        `created_at` TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
        PRIMARY KEY (`idem_key`, `endpoint`),
        INDEX `idx_created_at` (`created_at`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci",
];

foreach ($statements as $sql) {
    try {
        $db->exec($sql);
        $results[] = 'OK: ' . substr($sql, 0, 80) . '...';
    } catch (PDOException $e) {
        $results[] = 'ERROR: ' . $e->getMessage() . ' | SQL: ' . substr($sql, 0, 80);
    }
}

echo "Migration 933 — Idempotency Keys\n";
echo str_repeat('=', 50) . "\n";
foreach ($results as $r) {
    echo $r . "\n";
}
echo "\nDone.\n";

<?php
/**
 * Run Migration 930 — Quote Line Items: Section Grouping
 * Adds section_name column to quote_line_items table.
 * Admin-only, one-time use.
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
    "ALTER TABLE `quote_line_items` ADD COLUMN `section_name` VARCHAR(100) NULL DEFAULT NULL AFTER `sort_order`",
];

foreach ($statements as $i => $sql) {
    try {
        $db->exec($sql);
        $results[] = "OK  [$i] " . substr(trim($sql), 0, 80) . '...';
    } catch (PDOException $e) {
        if (strpos($e->getMessage(), 'Duplicate column') !== false ||
            strpos($e->getMessage(), '1060') !== false ||
            strpos($e->getMessage(), 'already exists') !== false) {
            $results[] = "SKIP[$i] Already exists — " . substr(trim($sql), 0, 60);
        } else {
            $results[] = "ERR [$i] " . $e->getMessage() . ' | SQL: ' . substr(trim($sql), 0, 80);
        }
    }
}

echo "Migration 930 — Results:\n\n" . implode("\n", $results) . "\n\nDone.";

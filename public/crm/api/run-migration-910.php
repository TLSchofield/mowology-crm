<?php
/**
 * Run Migration 910 — Icon Sets
 * Creates icon_sets table and adds icon_set_id to products.
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
session_write_close();
requirePermission('admin');

header('Content-Type: text/plain; charset=utf-8');

$db = getDB();
$results = [];

$statements = [
    "CREATE TABLE IF NOT EXISTS `icon_sets` (
        `id`             INT          NOT NULL AUTO_INCREMENT,
        `name`           VARCHAR(100) NOT NULL,
        `icon_base_path` VARCHAR(255) NOT NULL,
        `created_by`     INT          DEFAULT NULL,
        `created_at`     TIMESTAMP    DEFAULT CURRENT_TIMESTAMP,
        `updated_at`     TIMESTAMP    DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        PRIMARY KEY (`id`),
        KEY `idx_icon_sets_created_by` (`created_by`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci",

    "ALTER TABLE `products` ADD COLUMN `icon_set_id` INT DEFAULT NULL",
    "ALTER TABLE `products` ADD KEY `idx_products_icon_set_id` (`icon_set_id`)",
];

foreach ($statements as $i => $sql) {
    try {
        $db->exec($sql);
        $results[] = "OK  [$i] " . substr(trim($sql), 0, 80) . '...';
    } catch (PDOException $e) {
        if (strpos($e->getMessage(), 'Duplicate column') !== false ||
            strpos($e->getMessage(), '1060') !== false ||
            strpos($e->getMessage(), 'already exists') !== false ||
            strpos($e->getMessage(), '1061') !== false) {
            $results[] = "SKIP[$i] Already exists — " . substr(trim($sql), 0, 60);
        } else {
            $results[] = "ERR [$i] " . $e->getMessage() . ' | SQL: ' . substr(trim($sql), 0, 80);
        }
    }
}

echo "Migration 910 — Results:\n\n" . implode("\n", $results) . "\n\nDone.";

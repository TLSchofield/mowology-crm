<?php
/**
 * Migration 1046 — sites (tenant) stub table.
 * Client/Account model Phase 1 (D3). Mirrors database/migrations/1046_sites_table.sql.
 * Run by visiting this URL as an admin. Idempotent.
 */
declare(strict_types=1);
header('Content-Type: application/json');

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
if (!isAdmin()) { http_response_code(403); echo json_encode(['error' => 'Admin only']); exit; }

$db      = getDB();
$results = [];

function tryExec1046(PDO $db, string $sql, string $label, array &$results): void {
    try {
        $n = $db->exec($sql);
        $results[] = ['step' => $label, 'status' => 'ok', 'rows' => $n];
    } catch (PDOException $e) {
        $msg     = $e->getMessage();
        $already = strpos($msg, 'Duplicate') !== false || strpos($msg, 'already exists') !== false;
        $results[] = ['step' => $label, 'status' => $already ? 'already_exists' : 'error', 'msg' => $msg];
    }
}

tryExec1046($db,
    "CREATE TABLE IF NOT EXISTS sites (
       id INT AUTO_INCREMENT PRIMARY KEY,
       site_key VARCHAR(50) NOT NULL,
       name VARCHAR(150) NOT NULL,
       domain VARCHAR(255) NULL DEFAULT NULL,
       status ENUM('active','inactive') DEFAULT 'active',
       created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
       updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
       UNIQUE KEY uq_site_key (site_key)
     ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci",
    'create sites', $results);

tryExec1046($db,
    "INSERT INTO sites (id, site_key, name, domain)
     SELECT 1, 'mowology', 'Mowology', 'mowology.ca'
     FROM DUAL WHERE NOT EXISTS (SELECT 1 FROM sites WHERE id = 1)",
    'seed Mowology tenant', $results);

$ok = !in_array('error', array_column($results, 'status'));
echo json_encode(['ok' => $ok, 'migration' => '1046', 'results' => $results], JSON_PRETTY_PRINT);

<?php
/**
 * Migration 1050 — add nullable client_id to contracts.
 * Mirrors database/migrations/1050_contracts_client_id.sql. Admin-only, idempotent.
 */
declare(strict_types=1);
header('Content-Type: application/json');

$__dir = __DIR__;
for ($__i = 0; $__i < 5; $__i++) {
    $__dir = dirname($__dir);
    if (is_file($__dir . '/app/Core/paths.php')) { require_once $__dir . '/app/Core/paths.php'; break; }
}
unset($__dir, $__i);

require_once PUBLIC_ROOT . '/loginAuth/auth.php';
requireLogin();
if (!isAdmin()) { http_response_code(403); echo json_encode(['error' => 'Admin only']); exit; }

$db = getDB();
$results = [];

function tryExec1050(PDO $db, string $sql, string $label, array &$results): void {
    try {
        $db->exec($sql);
        $results[] = ['step' => $label, 'status' => 'ok'];
    } catch (PDOException $e) {
        $msg = $e->getMessage();
        $already = strpos($msg, 'Duplicate') !== false
                || strpos($msg, 'already exists') !== false
                || strpos($msg, 'check that column/key exists') !== false;
        $results[] = ['step' => $label, 'status' => $already ? 'already_exists' : 'error', 'msg' => $msg];
    }
}

tryExec1050($db, "ALTER TABLE contracts ADD COLUMN client_id INT NULL DEFAULT NULL AFTER contact_id", 'contracts: add client_id', $results);
tryExec1050($db, "ALTER TABLE contracts ADD CONSTRAINT fk_contracts_client FOREIGN KEY (client_id) REFERENCES clients(id) ON DELETE SET NULL", 'contracts: add client FK', $results);
tryExec1050($db, "ALTER TABLE contracts ADD INDEX idx_contracts_client (client_id)", 'contracts: add client_id index', $results);

$ok = !in_array('error', array_column($results, 'status'));
echo json_encode(['ok' => $ok, 'migration' => '1050', 'results' => $results], JSON_PRETTY_PRINT);

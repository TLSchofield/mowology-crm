<?php
/**
 * Migration 1048 — nullable client_id on properties/job_plans/quotes/invoices.
 * Client/Account model Phase 1. Mirrors database/migrations/1048_entities_client_id.sql.
 * Run by visiting this URL as an admin. Idempotent (already_exists tolerated).
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

function tryExec1048(PDO $db, string $sql, string $label, array &$results): void {
    try {
        $db->exec($sql);
        $results[] = ['step' => $label, 'status' => 'ok'];
    } catch (PDOException $e) {
        $msg     = $e->getMessage();
        $already = strpos($msg, 'Duplicate') !== false
                || strpos($msg, 'already exists') !== false
                || strpos($msg, 'Duplicate column') !== false
                || strpos($msg, 'check that column/key exists') !== false;
        $results[] = ['step' => $label, 'status' => $already ? 'already_exists' : 'error', 'msg' => $msg];
    }
}

$entities = [
    ['properties', 'site_contact_id', 'fk_properties_client', 'idx_properties_client'],
    ['job_plans',  'company_id',      'fk_job_plans_client',  'idx_job_plans_client'],
    ['quotes',     'company_id',      'fk_quotes_client',     'idx_quotes_client'],
    ['invoices',   'company_id',      'fk_invoices_client',   'idx_invoices_client'],
];

foreach ($entities as [$table, $after, $fk, $idx]) {
    tryExec1048($db,
        "ALTER TABLE {$table} ADD COLUMN client_id INT NULL DEFAULT NULL AFTER {$after}",
        "{$table}: add client_id", $results);
    tryExec1048($db,
        "ALTER TABLE {$table} ADD CONSTRAINT {$fk} FOREIGN KEY (client_id) REFERENCES clients(id) ON DELETE SET NULL",
        "{$table}: add client FK", $results);
    tryExec1048($db,
        "ALTER TABLE {$table} ADD INDEX {$idx} (client_id)",
        "{$table}: add client_id index", $results);
}

$ok = !in_array('error', array_column($results, 'status'));
echo json_encode(['ok' => $ok, 'migration' => '1048', 'results' => $results], JSON_PRETTY_PRINT);

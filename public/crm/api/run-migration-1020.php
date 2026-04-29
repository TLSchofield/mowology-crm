<?php
/**
 * Migration 1020 — transaction_rules self-learning columns.
 * Adds: source (manual|learned), learned_count, last_learned_at.
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
if (!isAdmin()) {
    http_response_code(403);
    echo json_encode(['error' => 'Admin only']);
    exit;
}

$db      = getDB();
$results = [];

function tryExec1020(PDO $db, string $sql, string $label, array &$results): void {
    try {
        $db->exec($sql);
        $results[] = ['step' => $label, 'status' => 'ok'];
    } catch (PDOException $e) {
        $msg     = $e->getMessage();
        $already = strpos($msg, 'Duplicate') !== false
                || strpos($msg, 'already exists') !== false;
        $results[] = [
            'step'   => $label,
            'status' => $already ? 'already_exists' : 'error',
            'msg'    => $msg,
        ];
    }
}

tryExec1020($db,
    "ALTER TABLE transaction_rules ADD COLUMN source ENUM('manual','learned') NOT NULL DEFAULT 'manual' AFTER updated_at",
    'add source column', $results);

tryExec1020($db,
    "ALTER TABLE transaction_rules ADD COLUMN learned_count INT NOT NULL DEFAULT 0 AFTER source",
    'add learned_count column', $results);

tryExec1020($db,
    "ALTER TABLE transaction_rules ADD COLUMN last_learned_at DATETIME NULL AFTER learned_count",
    'add last_learned_at column', $results);

tryExec1020($db,
    "CREATE INDEX idx_rules_source ON transaction_rules (source)",
    'add source index', $results);

echo json_encode(['ok' => true, 'results' => $results], JSON_PRETTY_PRINT);

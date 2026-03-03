<?php
/**
 * Migration 933 — Contract pathway toggle on quotes
 *
 * 1. Clears the auto-migrated contracts (created incorrectly from all accepted quotes).
 *    All plans revert to standalone (contract_id = NULL). Contracts table emptied.
 * 2. Adds is_contract TINYINT(1) to quotes (default 0 = residential pathway).
 *
 * Safe to run multiple times.
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

// ── 1. Clear incorrectly auto-migrated contracts ───────────────────────────
$r = $db->query("UPDATE job_plans SET contract_id = NULL WHERE contract_id IS NOT NULL");
$results[] = "Cleared contract_id from {$r->rowCount()} job_plan(s).";

$r = $db->query("DELETE FROM contracts");
$results[] = "Deleted {$r->rowCount()} auto-created contract(s).";

// ── 2. Add is_contract column to quotes ────────────────────────────────────
$ddl = [
    "ALTER TABLE quotes ADD COLUMN is_contract TINYINT(1) NOT NULL DEFAULT 0 AFTER status",
    "ALTER TABLE quotes ADD KEY idx_is_contract (is_contract)",
];

foreach ($ddl as $sql) {
    try {
        $db->exec($sql);
        $results[] = "OK  " . substr(trim($sql), 0, 80);
    } catch (PDOException $e) {
        $msg = $e->getMessage();
        if (strpos($msg, 'Duplicate column') !== false || strpos($msg, '1060') !== false ||
            strpos($msg, 'Duplicate key')   !== false || strpos($msg, '1061') !== false) {
            $results[] = "SKIP Already exists — " . substr(trim($sql), 0, 60);
        } else {
            $results[] = "ERR  " . $msg;
        }
    }
}

echo "Migration 933 — Contract Pathway Toggle\n\n" . implode("\n", $results) . "\n\nDone.";

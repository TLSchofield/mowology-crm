<?php
/**
 * Run Migration 208 — Add invoice_timing to contracts
 *
 * Moves billing trigger (when to send invoices) to the contract level.
 * Plans under a contract inherit this setting; standalone plans keep their own.
 *
 * Admin-only, idempotent (re-runnable safely).
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
    "ALTER TABLE contracts
        ADD COLUMN invoice_timing ENUM('after_visit', 'end_of_month', 'upfront') NOT NULL DEFAULT 'after_visit'
        AFTER billing_amount",
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

echo "Migration 208 — Add invoice_timing to contracts\n\n";
echo implode("\n", $results) . "\n\nDone.";

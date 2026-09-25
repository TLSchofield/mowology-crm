<?php
/**
 * Run Migration — Contracts v2
 * Adds auto_renew, renewal_increase_pct, last_renewed_at columns to contracts table.
 * Idempotent — safe to run multiple times.
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

header('Content-Type: application/json; charset=utf-8');

$db  = getDB();
$log = [];

/** Check if a column exists in a table */
function colExistsV2(PDO $db, string $table, string $col): bool {
    return $db->query("SHOW COLUMNS FROM `{$table}` LIKE '{$col}'")->rowCount() > 0;
}

/** Check if an index exists on a table */
function indexExistsV2(PDO $db, string $table, string $index): bool {
    $stmt = $db->query("SHOW INDEX FROM `{$table}` WHERE Key_name = '{$index}'");
    return $stmt->rowCount() > 0;
}

// ── 1. auto_renew ────────────────────────────────────────────────────────────
if (!colExistsV2($db, 'contracts', 'auto_renew')) {
    $db->exec("ALTER TABLE contracts ADD COLUMN auto_renew TINYINT(1) NOT NULL DEFAULT 1 AFTER renewal_date");
    $log[] = '✓ Added contracts.auto_renew (default 1 = auto-renew on)';
} else {
    $log[] = '– contracts.auto_renew already exists';
}

// ── 2. renewal_increase_pct ──────────────────────────────────────────────────
if (!colExistsV2($db, 'contracts', 'renewal_increase_pct')) {
    $db->exec("ALTER TABLE contracts ADD COLUMN renewal_increase_pct DECIMAL(5,2) NOT NULL DEFAULT 0.00 AFTER auto_renew");
    $log[] = '✓ Added contracts.renewal_increase_pct (default 0.00)';
} else {
    $log[] = '– contracts.renewal_increase_pct already exists';
}

// ── 3. last_renewed_at ───────────────────────────────────────────────────────
if (!colExistsV2($db, 'contracts', 'last_renewed_at')) {
    $db->exec("ALTER TABLE contracts ADD COLUMN last_renewed_at DATETIME DEFAULT NULL AFTER renewal_increase_pct");
    $log[] = '✓ Added contracts.last_renewed_at';
} else {
    $log[] = '– contracts.last_renewed_at already exists';
}

// ── 4. Index for cron query ──────────────────────────────────────────────────
if (!indexExistsV2($db, 'contracts', 'idx_renewal_date')) {
    try {
        $db->exec("ALTER TABLE contracts ADD KEY idx_renewal_date (renewal_date, auto_renew, status)");
        $log[] = '✓ Added idx_renewal_date index';
    } catch (PDOException $e) {
        if (strpos($e->getMessage(), '1061') !== false || strpos($e->getMessage(), 'Duplicate key name') !== false) {
            $log[] = '– idx_renewal_date already exists (caught)';
        } else {
            throw $e;
        }
    }
} else {
    $log[] = '– idx_renewal_date already exists';
}

echo json_encode([
    'success' => true,
    'log'     => $log,
    'note'    => 'All ALTER TABLE ran outside transaction (MySQL DDL auto-commits).',
], JSON_PRETTY_PRINT);

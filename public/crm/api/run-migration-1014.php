<?php
/**
 * Migration 1014 — Multi-trip-per-day support on vehicle_trip_reports
 *
 * Three steps, all idempotent:
 *   1. DROP UNIQUE KEY uq_driver_date  (lets multiple rows per day exist)
 *   2. ADD COLUMN trip_sequence INT NOT NULL DEFAULT 1
 *   3. ADD INDEX idx_driver_date_seq (driver_id, report_date, trip_sequence)
 *
 * Run once via /crm/api/run-migration-1014.php while logged in as an admin.
 */
declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/loginAuth/auth.php';
requireLogin();
requirePermission('database.manage');

$db  = getDB();
$log = [];

function mig1014_index_exists(PDO $db, string $table, string $indexName): bool {
    $stmt = $db->prepare("
        SELECT COUNT(*) FROM information_schema.statistics
        WHERE table_schema = DATABASE()
          AND table_name   = ?
          AND index_name   = ?
    ");
    $stmt->execute([$table, $indexName]);
    return (int)$stmt->fetchColumn() > 0;
}

function mig1014_column_exists(PDO $db, string $table, string $column): bool {
    $stmt = $db->prepare("
        SELECT COUNT(*) FROM information_schema.columns
        WHERE table_schema = DATABASE()
          AND table_name   = ?
          AND column_name  = ?
    ");
    $stmt->execute([$table, $column]);
    return (int)$stmt->fetchColumn() > 0;
}

// ── 1. Add trip_sequence column (needed before index creation) ──────────────
try {
    if (mig1014_column_exists($db, 'vehicle_trip_reports', 'trip_sequence')) {
        $log[] = '⏭ vehicle_trip_reports.trip_sequence — already exists';
    } else {
        $db->exec("
            ALTER TABLE vehicle_trip_reports
            ADD COLUMN trip_sequence INT NOT NULL DEFAULT 1
            COMMENT 'Driver-authored trip number within a given report_date. 1 = first trip of the day.'
            AFTER report_date
        ");
        $log[] = '✅ vehicle_trip_reports.trip_sequence — added';
    }
} catch (\PDOException $e) {
    $log[] = '❌ trip_sequence — ERROR: ' . $e->getMessage();
}

// ── 2. Add composite index (must exist before we drop uq_driver_date so FKs
//       have a backing index on driver_id when we re-add them) ──────────────
try {
    if (mig1014_index_exists($db, 'vehicle_trip_reports', 'idx_driver_date_seq')) {
        $log[] = '⏭ idx_driver_date_seq — already exists';
    } else {
        $db->exec("
            CREATE INDEX idx_driver_date_seq
            ON vehicle_trip_reports (driver_id, report_date, trip_sequence)
        ");
        $log[] = '✅ idx_driver_date_seq — created';
    }
} catch (\PDOException $e) {
    $log[] = '❌ idx_driver_date_seq — ERROR: ' . $e->getMessage();
}

// ── 3. Drop the UNIQUE KEY so multiple rows per (driver, date) are allowed ──
// MySQL error 1553: can't drop an index used by a FK. We must drop the FK
// first, drop the unique key, then re-add the FK. idx_driver_date_seq
// (created above) starts with driver_id so MySQL will use it for the FK.
try {
    if (mig1014_index_exists($db, 'vehicle_trip_reports', 'uq_driver_date')) {
        // Find FK constraints on driver_id that MySQL is using uq_driver_date for
        $fkStmt = $db->prepare("
            SELECT CONSTRAINT_NAME
            FROM information_schema.KEY_COLUMN_USAGE
            WHERE table_schema         = DATABASE()
              AND TABLE_NAME           = 'vehicle_trip_reports'
              AND REFERENCED_TABLE_NAME IS NOT NULL
              AND COLUMN_NAME          = 'driver_id'
        ");
        $fkStmt->execute();
        $fkNames = $fkStmt->fetchAll(PDO::FETCH_COLUMN);

        // Drop FKs → drop unique key → re-add FKs
        foreach ($fkNames as $fkName) {
            $db->exec("ALTER TABLE vehicle_trip_reports DROP FOREIGN KEY `{$fkName}`");
            $log[] = "✅ FK {$fkName} — temporarily dropped";
        }

        $db->exec("ALTER TABLE vehicle_trip_reports DROP INDEX uq_driver_date");
        $log[] = '✅ uq_driver_date — dropped';

        foreach ($fkNames as $fkName) {
            $db->exec("ALTER TABLE vehicle_trip_reports ADD CONSTRAINT `{$fkName}` FOREIGN KEY (driver_id) REFERENCES users(id)");
            $log[] = "✅ FK {$fkName} — re-added";
        }
    } else {
        $log[] = '⏭ uq_driver_date — already absent';
    }
} catch (\PDOException $e) {
    $log[] = '❌ uq_driver_date — ERROR: ' . $e->getMessage();
}

$failed = array_filter($log, fn($l) => str_starts_with($l, '❌'));
?>
<!DOCTYPE html><html lang="en"><head><meta charset="UTF-8"><title>Migration 1014</title>
<style>body{font-family:monospace;background:#f5f5f5;padding:2rem;max-width:720px;margin:0 auto}h1{color:#0D3B2E}ul{list-style:none;padding:0}li{padding:.5rem .75rem;border-bottom:1px solid #e0e0e0;background:#fff;margin-bottom:4px;border-radius:6px}.ok{color:#2D8659;font-weight:bold}.err{color:#DC2626;font-weight:bold}.skip{color:#6B7280}</style>
</head><body>
<h1>Migration 1014 — Trip Reports Multi-Trip Support</h1>
<p>Drops <code>uq_driver_date</code>, adds <code>trip_sequence</code>, adds
   a composite index. Lets drivers run multiple pre/post trip cycles per day
   without overwriting previous data.</p>
<ul>
<?php foreach ($log as $line):
    $cls = 'ok';
    if (str_starts_with($line, '❌')) $cls = 'err';
    elseif (str_starts_with($line, '⏭')) $cls = 'skip';
?>
  <li class="<?= $cls ?>"><?= htmlspecialchars($line) ?></li>
<?php endforeach; ?>
</ul>
<?php if (empty($failed)): ?>
  <p class="ok" style="margin-top:1rem">✅ Migration complete.</p>
<?php else: ?>
  <p class="err" style="margin-top:1rem">❌ <?= count($failed) ?> step(s) failed — see above.</p>
<?php endif; ?>
<p style="margin-top:1.5rem"><a href="/crm/database_appstack.php">← Database Manager</a></p>
</body></html>

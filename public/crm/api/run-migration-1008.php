<?php
/**
 * Migration 1008 — Performance indexes (Sprint Ⓓ D2)
 *
 * Adds three missing composite indexes identified in the April 2026
 * Capacitor app performance audit:
 *   1. property_measurements(property_id, measurement_type)
 *   2. calendar_stop_crew(stop_id)
 *   3. crew_location_history(crew_id, timestamp)
 *
 * Idempotent: checks information_schema before issuing CREATE INDEX
 * so repeat runs are safe.
 *
 * Run once via: /crm/api/run-migration-1008.php
 * Protected by CRM login + database.manage permission.
 */
declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/loginAuth/auth.php';
requireLogin();
requirePermission('database.manage');

$db  = getDB();
$log = [];

/**
 * Add an index only if it doesn't already exist.
 */
function mig1008_add_index(PDO $db, string $table, string $index, string $ddl): string {
    try {
        $check = $db->prepare("
            SELECT COUNT(*) FROM information_schema.statistics
            WHERE table_schema = DATABASE()
              AND table_name   = ?
              AND index_name   = ?
        ");
        $check->execute([$table, $index]);
        if ((int)$check->fetchColumn() > 0) {
            return "⏭ {$table}.{$index} — already exists";
        }
        $db->exec($ddl);
        return "✅ {$table}.{$index} — created";
    } catch (\PDOException $e) {
        return "❌ {$table}.{$index} — ERROR: " . $e->getMessage();
    }
}

$log[] = mig1008_add_index(
    $db,
    'property_measurements',
    'idx_pm_property_type',
    'CREATE INDEX idx_pm_property_type ON property_measurements (property_id, measurement_type)'
);

$log[] = mig1008_add_index(
    $db,
    'calendar_stop_crew',
    'idx_csc_stop',
    'CREATE INDEX idx_csc_stop ON calendar_stop_crew (stop_id)'
);

$log[] = mig1008_add_index(
    $db,
    'crew_location_history',
    'idx_clh_crew_time',
    'CREATE INDEX idx_clh_crew_time ON crew_location_history (crew_id, timestamp)'
);

$failed = array_filter($log, fn($l) => str_starts_with($l, '❌'));
?>
<!DOCTYPE html><html lang="en"><head><meta charset="UTF-8"><title>Migration 1008</title>
<style>body{font-family:monospace;background:#f5f5f5;padding:2rem;max-width:720px;margin:0 auto}h1{color:#0D3B2E}ul{list-style:none;padding:0}li{padding:.5rem .75rem;border-bottom:1px solid #e0e0e0;background:#fff;margin-bottom:4px;border-radius:6px}.ok{color:#2D8659;font-weight:bold}.err{color:#DC2626;font-weight:bold}.skip{color:#6B7280}</style>
</head><body>
<h1>Migration 1008 — Performance Indexes</h1>
<p>Adds missing composite indexes on hot tables used by
   <code>getCalendarStops()</code> and the WorkManager GPS sync pipeline.</p>
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

<?php
/**
 * Migration 1035 — Vehicle Tablet GPS flag
 *
 * Adds is_vehicle_tablet TINYINT(1) to users and an index on it.
 * Run once via: /crm/api/run-migration-1035.php
 */
declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/loginAuth/auth.php';
requireLogin();
requirePermission('database.manage');

$db  = getDB();
$log = [];

function mig1035_column_exists(PDO $db, string $table, string $col): bool {
    $stmt = $db->prepare("
        SELECT COUNT(*) FROM information_schema.COLUMNS
        WHERE table_schema = DATABASE() AND table_name = ? AND column_name = ?
    ");
    $stmt->execute([$table, $col]);
    return (int)$stmt->fetchColumn() > 0;
}

function mig1035_index_exists(PDO $db, string $table, string $index): bool {
    $stmt = $db->prepare("
        SELECT COUNT(*) FROM information_schema.STATISTICS
        WHERE table_schema = DATABASE() AND table_name = ? AND index_name = ?
    ");
    $stmt->execute([$table, $index]);
    return (int)$stmt->fetchColumn() > 0;
}

function mig1035_run(PDO $db, string $label, string $sql): string {
    try {
        $db->exec($sql);
        return "OK {$label}";
    } catch (\PDOException $e) {
        return "ERR {$label} — " . $e->getMessage();
    }
}

// ── 1. Add is_vehicle_tablet column ───────────────────────────────────────────
if (mig1035_column_exists($db, 'users', 'is_vehicle_tablet')) {
    $log[] = "SKIP is_vehicle_tablet column — already exists";
} else {
    $log[] = mig1035_run($db, 'ADD COLUMN is_vehicle_tablet', "
        ALTER TABLE users
        ADD COLUMN is_vehicle_tablet TINYINT(1) NOT NULL DEFAULT 0
            COMMENT '1 = device is a vehicle-mounted tablet; pings go to crew_location_history continuously'
    ");
}

// ── 2. Add index ───────────────────────────────────────────────────────────────
if (mig1035_index_exists($db, 'users', 'idx_users_vehicle_tablet')) {
    $log[] = "SKIP idx_users_vehicle_tablet — index already exists";
} else {
    $log[] = mig1035_run($db, 'CREATE INDEX idx_users_vehicle_tablet', "
        CREATE INDEX idx_users_vehicle_tablet ON users (is_vehicle_tablet)
    ");
}

$failed = array_filter($log, function($l) { return strpos($l, 'ERR') === 0; });
?>
<!DOCTYPE html><html lang="en"><head><meta charset="UTF-8"><title>Migration 1035</title>
<style>body{font-family:monospace;background:#f5f5f5;padding:2rem;max-width:720px;margin:0 auto}h1{color:#0D3B2E}ul{list-style:none;padding:0}li{padding:.5rem .75rem;border-bottom:1px solid #e0e0e0;background:#fff;margin-bottom:4px;border-radius:6px}.ok{color:#2D8659;font-weight:bold}.err{color:#DC2626;font-weight:bold}.skip{color:#6B7280}</style>
</head><body>
<h1>Migration 1035 — Vehicle Tablet GPS</h1>
<p>Adds <code>is_vehicle_tablet</code> flag to the <code>users</code> table so the truck-mounted tablet can be identified for GPS corroboration in salt reports.</p>
<ul>
<?php foreach ($log as $line):
    $cls = 'ok';
    if (strpos($line, 'ERR') === 0) $cls = 'err';
    elseif (strpos($line, 'SKIP') === 0) $cls = 'skip';
?>
  <li class="<?= $cls ?>"><?= htmlspecialchars($line) ?></li>
<?php endforeach; ?>
</ul>
<?php if (empty($failed)): ?>
  <p class="ok" style="margin-top:1rem">Migration complete. Set <code>is_vehicle_tablet = 1</code> on the truck tablet user in the Team settings.</p>
<?php else: ?>
  <p class="err" style="margin-top:1rem"><?= count($failed) ?> step(s) failed — see above.</p>
<?php endif; ?>
<p style="margin-top:1.5rem"><a href="/crm/database_appstack.php">&larr; Database Manager</a></p>
</body></html>

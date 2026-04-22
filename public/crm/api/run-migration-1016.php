<?php
/**
 * Migration 1016 — Add location_ping_rate to users table
 *
 * Adds per-user GPS ping rate: low (10 min), medium (2 min), high (30 s, default).
 * This column was previously added via a one-off script that was later removed.
 * Running this migration is safe even if the column already exists.
 *
 * Run once via: /crm/api/run-migration-1016.php
 * Protected by CRM login + database.manage permission.
 */
declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/loginAuth/auth.php';
requireLogin();
requirePermission('database.manage');

$db  = getDB();
$log = [];

function mig1016_has_column(PDO $db, string $table, string $col): bool {
    $stmt = $db->prepare("
        SELECT COUNT(*) FROM information_schema.COLUMNS
        WHERE TABLE_SCHEMA = DATABASE()
          AND TABLE_NAME   = ?
          AND COLUMN_NAME  = ?
    ");
    $stmt->execute([$table, $col]);
    return (int)$stmt->fetchColumn() > 0;
}

// ── location_ping_rate ──
if (mig1016_has_column($db, 'users', 'location_ping_rate')) {
    $log[] = "⏭ users.location_ping_rate — already exists";
} else {
    try {
        $db->exec("ALTER TABLE `users`
            ADD COLUMN `location_ping_rate` ENUM('low','medium','high') NOT NULL DEFAULT 'high'
            COMMENT 'Per-user GPS ping frequency: low=10min, medium=2min, high=30s'
        ");
        $log[] = "✅ users.location_ping_rate — added";
    } catch (PDOException $e) {
        $log[] = "❌ users.location_ping_rate — ERROR: " . $e->getMessage();
    }
}

$failed = array_filter($log, fn($l) => str_starts_with($l, '❌'));
?>
<!DOCTYPE html><html lang="en"><head><meta charset="UTF-8"><title>Migration 1016</title>
<style>body{font-family:monospace;background:#f5f5f5;padding:2rem;max-width:720px;margin:0 auto}h1{color:#0D3B2E}ul{list-style:none;padding:0}li{padding:.5rem .75rem;border-bottom:1px solid #e0e0e0;background:#fff;margin-bottom:4px;border-radius:6px}.ok{color:#2D8659;font-weight:bold}.err{color:#DC2626;font-weight:bold}.skip{color:#6B7280}</style>
</head><body>
<h1>Migration 1016 — location_ping_rate</h1>
<p>Adds per-user GPS ping rate column to the <code>users</code> table
   so the Time Clock status API can return per-user interval overrides.</p>
<ul>
<?php foreach ($log as $line):
    $cls = str_starts_with($line, '❌') ? 'err' : (str_starts_with($line, '⏭') ? 'skip' : 'ok');
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

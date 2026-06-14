<?php
/**
 * Migration 1017 — Add property_manager_id to properties table
 *
 * Links a property to its managing company (strata manager, property manager, etc.)
 * Mirrors properties.owner_company_id pattern. Both are nullable FKs to companies(id).
 *
 * Run once via: /crm/api/run-migration-1017.php
 * Protected by CRM login + database.manage permission.
 */
declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/loginAuth/auth.php';
requireLogin();
requirePermission('database.manage');

$db  = getDB();
$log = [];

function mig1017_has_column(PDO $db, string $table, string $col): bool {
    $stmt = $db->prepare("
        SELECT COUNT(*) FROM information_schema.COLUMNS
        WHERE TABLE_SCHEMA = DATABASE()
          AND TABLE_NAME   = ?
          AND COLUMN_NAME  = ?
    ");
    $stmt->execute([$table, $col]);
    return (int)$stmt->fetchColumn() > 0;
}

// ── property_manager_id ──
if (mig1017_has_column($db, 'properties', 'property_manager_id')) {
    $log[] = "⏭ properties.property_manager_id — already exists";
} else {
    try {
        $db->exec("ALTER TABLE `properties`
            ADD COLUMN `property_manager_id` INT NULL DEFAULT NULL
            COMMENT 'FK to companies — strata or property management company'
        ");
        $log[] = "✅ properties.property_manager_id — added";
    } catch (PDOException $e) {
        $log[] = "❌ properties.property_manager_id — ERROR: " . $e->getMessage();
    }
}

// ── FK index (non-blocking, best-effort) ──
try {
    $db->exec("ALTER TABLE `properties`
        ADD INDEX `idx_property_manager` (`property_manager_id`)
    ");
    $log[] = "✅ idx_property_manager index — added";
} catch (PDOException $e) {
    $log[] = "⏭ idx_property_manager index — skipped (" . $e->getMessage() . ")";
}

$failed = array_filter($log, fn($l) => str_starts_with($l, '❌'));
?>
<!DOCTYPE html><html lang="en"><head><meta charset="UTF-8"><title>Migration 1017</title>
<style>body{font-family:monospace;background:#f5f5f5;padding:2rem;max-width:720px;margin:0 auto}h1{color:#0D3B2E}ul{list-style:none;padding:0}li{padding:.5rem .75rem;border-bottom:1px solid #e0e0e0;background:#fff;margin-bottom:4px;border-radius:6px}.ok{color:#2D8659;font-weight:bold}.err{color:#DC2626;font-weight:bold}.skip{color:#6B7280}</style>
</head><body>
<h1>Migration 1017 — property_manager_id</h1>
<p>Adds <code>property_manager_id</code> FK column to the <code>properties</code> table
   so each property can be directly linked to its managing company.</p>
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

<?php
/**
 * Migration 1005 — Recycling Tax on Expenses
 *
 * Adds expenses.recycling_tax for BC drink tax / Environmental Handling Charge.
 * Run once via: /crm/api/run-migration-1005.php
 */
declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/loginAuth/auth.php';
requireLogin();
requirePermission('database.manage');

$db  = getDB();
$log = [];

function mig1005_run(PDO $db, string $label, string $sql): string {
    try {
        $db->exec($sql);
        return "✅ $label";
    } catch (\PDOException $e) {
        $msg = $e->getMessage();
        if (stripos($msg, 'Duplicate column') !== false) {
            return "⏭ $label — already exists, skipped";
        }
        return "❌ $label — ERROR: $msg";
    }
}

$log[] = mig1005_run($db, 'Add recycling_tax to expenses',
    "ALTER TABLE `expenses`
     ADD COLUMN `recycling_tax` DECIMAL(10,2) NOT NULL DEFAULT 0.00
       COMMENT 'BC recycling / drink tax (EHC) — beverage container deposit fee'
     AFTER `pst_amount`"
);

$failed = array_filter($log, fn($l) => str_starts_with($l, '❌'));
?>
<!DOCTYPE html><html lang="en"><head><meta charset="UTF-8"><title>Migration 1005</title>
<style>body{font-family:monospace;background:#f5f5f5;padding:2rem}ul{list-style:none;padding:0}li{padding:.35rem 0;border-bottom:1px solid #e0e0e0}.ok{color:#2D8659;font-weight:bold}.err{color:#c00;font-weight:bold}</style>
</head><body>
<h1>Migration 1005 — Recycling Tax on Expenses</h1>
<ul>
<?php foreach ($log as $line): ?>
  <li class="<?= str_starts_with($line,'❌')?'err':'ok' ?>"><?= htmlspecialchars($line) ?></li>
<?php endforeach; ?>
</ul>
<?php if (empty($failed)): ?>
  <p class="ok" style="margin-top:1rem">✅ Migration complete.</p>
<?php else: ?>
  <p class="err" style="margin-top:1rem">❌ <?= count($failed) ?> step(s) failed.</p>
<?php endif; ?>
<p><a href="/crm/database_appstack.php">← Database Manager</a></p>
</body></html>

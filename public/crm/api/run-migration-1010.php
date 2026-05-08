<?php
/**
 * Migration 1010 — invoices.bill_to_name
 *
 * Adds a free-text "billed entity" name to invoices so the Bill To
 * section on the view + PDF can show the actual billing entity
 * (e.g. "VR14-50 C/O MACDONALD REALTY") instead of the contact
 * person (who is a representative, not the billed party).
 *
 * Idempotent — checks information_schema before each ADD COLUMN.
 * Run once via: /crm/api/run-migration-1010.php
 */
declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/loginAuth/auth.php';
requireLogin();
requirePermission('database.manage');

$db  = getDB();
$log = [];

function mig1010_add_column(PDO $db, string $table, string $column, string $ddl): string {
    try {
        $check = $db->prepare("
            SELECT COUNT(*) FROM information_schema.columns
            WHERE table_schema = DATABASE()
              AND table_name   = ?
              AND column_name  = ?
        ");
        $check->execute([$table, $column]);
        if ((int)$check->fetchColumn() > 0) {
            return "⏭ {$table}.{$column} — already exists";
        }
        $db->exec($ddl);
        return "✅ {$table}.{$column} — added";
    } catch (\PDOException $e) {
        return "❌ {$table}.{$column} — ERROR: " . $e->getMessage();
    }
}

$log[] = mig1010_add_column(
    $db,
    'invoices',
    'bill_to_name',
    "ALTER TABLE `invoices`
     ADD COLUMN `bill_to_name` VARCHAR(255) NULL DEFAULT NULL
     COMMENT 'Free-text billed-entity name rendered on Bill To section. Overrides company_name / contact. Example: \"VR14-50 C/O MACDONALD REALTY\".'
     AFTER `notes`"
);

$failed = array_filter($log, fn($l) => str_starts_with($l, '❌'));
?>
<!DOCTYPE html><html lang="en"><head><meta charset="UTF-8"><title>Migration 1010</title>
<style>body{font-family:monospace;background:#f5f5f5;padding:2rem;max-width:720px;margin:0 auto}h1{color:#0D3B2E}ul{list-style:none;padding:0}li{padding:.5rem .75rem;border-bottom:1px solid #e0e0e0;background:#fff;margin-bottom:4px;border-radius:6px}.ok{color:#2D8659;font-weight:bold}.err{color:#DC2626;font-weight:bold}.skip{color:#6B7280}</style>
</head><body>
<h1>Migration 1010 — Invoice Bill To Name</h1>
<p>Adds <code>bill_to_name</code> to <code>invoices</code> for property-management
   / strata-style billing entities (e.g. "VR14-50 C/O MACDONALD REALTY").</p>
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

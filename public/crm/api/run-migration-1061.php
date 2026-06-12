<?php
/**
 * Migration 1061 — invoice_line_items.title
 *
 * Adds a title column to invoice_line_items so the service/plan name is
 * stored at invoice creation time. Invoices are billing snapshots and must
 * not rely on JOINs to reconstruct what was sold.
 *
 * Idempotent: checks information_schema before ALTER TABLE so repeat runs
 * are safe.
 *
 * Run once via: /crm/api/run-migration-1061.php
 * Protected by CRM login + database.manage permission.
 */
declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/loginAuth/auth.php';
requireLogin();
requirePermission('database.manage');

$db  = getDB();
$log = [];

// 1. Add title column if absent
try {
    $check = $db->prepare("
        SELECT COUNT(*) FROM information_schema.columns
        WHERE table_schema = DATABASE()
          AND table_name   = 'invoice_line_items'
          AND column_name  = 'title'
    ");
    $check->execute();
    if ((int)$check->fetchColumn() > 0) {
        $log[] = '⏭ invoice_line_items.title — already exists';
    } else {
        $db->exec("ALTER TABLE invoice_line_items ADD COLUMN title VARCHAR(255) NULL AFTER invoice_id");
        $log[] = '✅ invoice_line_items.title — added';
    }
} catch (\Throwable $e) {
    $log[] = '❌ ALTER TABLE — ERROR: ' . $e->getMessage();
}

// 2. Backfill title from job_plans for rows that have a visit link
try {
    $stmt = $db->prepare("
        UPDATE invoice_line_items ili
        JOIN   job_visits jv ON jv.id = ili.visit_id
        JOIN   job_plans  jp ON jp.id = jv.plan_id
        SET    ili.title = jp.title
        WHERE  ili.visit_id IS NOT NULL
          AND  ili.title IS NULL
    ");
    $stmt->execute();
    $rows = $stmt->rowCount();
    $log[] = "✅ Backfill — {$rows} row(s) updated with plan title";
} catch (\Throwable $e) {
    $log[] = '❌ Backfill — ERROR: ' . $e->getMessage();
}

$failed = array_filter($log, fn($l) => str_starts_with($l, '❌'));
?>
<!DOCTYPE html><html lang="en"><head><meta charset="UTF-8"><title>Migration 1061</title>
<style>body{font-family:monospace;background:#f5f5f5;padding:2rem;max-width:720px;margin:0 auto}h1{color:#0D3B2E}ul{list-style:none;padding:0}li{padding:.5rem .75rem;border-bottom:1px solid #e0e0e0;background:#fff;margin-bottom:4px;border-radius:6px}.ok{color:#2D8659;font-weight:bold}.err{color:#DC2626;font-weight:bold}.skip{color:#6B7280}</style>
</head><body>
<h1>Migration 1061 — invoice_line_items.title</h1>
<p>Adds <code>title</code> column to <code>invoice_line_items</code> and backfills
   existing rows from their linked <code>job_plans</code> record.</p>
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

<?php
/**
 * Migration 1009 — Manual invoice resend tracking
 *
 * Adds two columns to `invoices`:
 *   - resend_count   INT NOT NULL DEFAULT 0
 *   - last_resent_at DATETIME NULL
 *
 * Owned by /crm/invoices/view.php's "Resend" action. Keeps the
 * existing reminder_count/last_reminder_sent_at columns exclusive to
 * the automated overdue-reminders cron so the two dunning sources
 * stay independently countable on the Engagement panel.
 *
 * Idempotent — checks information_schema before each ADD COLUMN.
 * Protected by requireLogin() + database.manage permission.
 *
 * Run once via: /crm/api/run-migration-1009.php
 */
declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/loginAuth/auth.php';
requireLogin();
requirePermission('database.manage');

$db  = getDB();
$log = [];

function mig1009_add_column(PDO $db, string $table, string $column, string $ddl): string {
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

$log[] = mig1009_add_column(
    $db,
    'invoices',
    'resend_count',
    "ALTER TABLE `invoices`
     ADD COLUMN `resend_count` INT NOT NULL DEFAULT 0
     COMMENT 'Count of manual crew-initiated resends (separate from reminder_count which tracks the overdue-reminders cron)'
     AFTER `reminder_count`"
);

$log[] = mig1009_add_column(
    $db,
    'invoices',
    'last_resent_at',
    "ALTER TABLE `invoices`
     ADD COLUMN `last_resent_at` DATETIME NULL DEFAULT NULL
     COMMENT 'Timestamp of the most recent manual resend from view.php'
     AFTER `resend_count`"
);

$failed = array_filter($log, fn($l) => str_starts_with($l, '❌'));
?>
<!DOCTYPE html><html lang="en"><head><meta charset="UTF-8"><title>Migration 1009</title>
<style>body{font-family:monospace;background:#f5f5f5;padding:2rem;max-width:720px;margin:0 auto}h1{color:#0D3B2E}ul{list-style:none;padding:0}li{padding:.5rem .75rem;border-bottom:1px solid #e0e0e0;background:#fff;margin-bottom:4px;border-radius:6px}.ok{color:#2D8659;font-weight:bold}.err{color:#DC2626;font-weight:bold}.skip{color:#6B7280}</style>
</head><body>
<h1>Migration 1009 — Invoice Resend Tracking</h1>
<p>Adds <code>resend_count</code> and <code>last_resent_at</code> to the
   <code>invoices</code> table so crew-initiated resends can be counted
   separately from the overdue-reminders cron.</p>
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

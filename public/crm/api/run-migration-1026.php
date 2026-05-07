<?php
/**
 * Migration 1026 — quotes.accepted_by_title
 *
 * Captures the signer's title at quote acceptance for strata /
 * corporate signings (signing on behalf of a company).
 *
 * Idempotent — checks information_schema before ADD COLUMN.
 * Run once via: /crm/api/run-migration-1026.php
 */
declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/loginAuth/auth.php';
requireLogin();
requirePermission('database.manage');

$db  = getDB();
$log = [];

function mig1026_add_column(PDO $db, string $table, string $column, string $ddl): string {
    try {
        $check = $db->prepare("
            SELECT COUNT(*) FROM information_schema.columns
            WHERE table_schema = DATABASE()
              AND table_name   = ?
              AND column_name  = ?
        ");
        $check->execute([$table, $column]);
        if ((int)$check->fetchColumn() > 0) {
            return "SKIP {$table}.{$column} - already exists";
        }
        $db->exec($ddl);
        return "OK {$table}.{$column} - added";
    } catch (\PDOException $e) {
        return "ERR {$table}.{$column} - " . $e->getMessage();
    }
}

$log[] = mig1026_add_column(
    $db,
    'quotes',
    'accepted_by_title',
    "ALTER TABLE `quotes`
     ADD COLUMN `accepted_by_title` VARCHAR(255) NULL DEFAULT NULL
     COMMENT 'Title/role of the signer when the quote is accepted on behalf of a company (e.g. \"Strata Council President\"). NULL for personal/residential.'
     AFTER `accepted_by_email`"
);

$failed = array_filter($log, function($l) { return strpos($l, 'ERR') === 0; });
?>
<!DOCTYPE html><html lang="en"><head><meta charset="UTF-8"><title>Migration 1026</title>
<style>body{font-family:monospace;background:#f5f5f5;padding:2rem;max-width:720px;margin:0 auto}h1{color:#0D3B2E}ul{list-style:none;padding:0}li{padding:.5rem .75rem;border-bottom:1px solid #e0e0e0;background:#fff;margin-bottom:4px;border-radius:6px}.ok{color:#2D8659;font-weight:bold}.err{color:#DC2626;font-weight:bold}.skip{color:#6B7280}</style>
</head><body>
<h1>Migration 1026 - Quote Accepted By Title</h1>
<p>Adds <code>accepted_by_title</code> to <code>quotes</code> for strata / corporate signing capacity.</p>
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
  <p class="ok" style="margin-top:1rem">Migration complete.</p>
<?php else: ?>
  <p class="err" style="margin-top:1rem"><?= count($failed) ?> step(s) failed - see above.</p>
<?php endif; ?>
<p style="margin-top:1.5rem"><a href="/crm/database_appstack.php">&larr; Database Manager</a></p>
</body></html>

<?php
/**
 * Migration 1028 — users.quiz_exempt
 *
 * Adds a per-user flag so specific users (e.g. the truck tablet account)
 * can be exempted from the pre-shift quiz gate without changing the global
 * quiz_preshift_enabled setting.
 *
 * Idempotent — checks information_schema before ADD COLUMN.
 * Run once via: /crm/api/run-migration-1028.php
 */
declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/loginAuth/auth.php';
requireLogin();
requirePermission('database.manage');

$db  = getDB();
$log = [];

function mig1028_add_column(PDO $db, string $table, string $column, string $ddl): string {
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

$log[] = mig1028_add_column(
    $db,
    'users',
    'quiz_exempt',
    "ALTER TABLE `users` ADD COLUMN `quiz_exempt` TINYINT(1) NOT NULL DEFAULT 0"
);

$failed = array_filter($log, function($l) { return strpos($l, 'ERR') === 0; });
?>
<!DOCTYPE html><html lang="en"><head><meta charset="UTF-8"><title>Migration 1028</title>
<style>body{font-family:monospace;background:#f5f5f5;padding:2rem;max-width:720px;margin:0 auto}h1{color:#0D3B2E}ul{list-style:none;padding:0}li{padding:.5rem .75rem;border-bottom:1px solid #e0e0e0;background:#fff;margin-bottom:4px;border-radius:6px}.ok{color:#2D8659;font-weight:bold}.err{color:#DC2626;font-weight:bold}.skip{color:#6B7280}</style>
</head><body>
<h1>Migration 1028 - Per-User Quiz Exempt</h1>
<p>Adds <code>quiz_exempt</code> to <code>users</code>. When set to 1, the user bypasses the pre-shift quiz gate.</p>
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

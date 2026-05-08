<?php
/**
 * Migration 1003 — Section Module Library
 *
 * Creates cms_page_sections table to store per-page content blocks.
 * Run once via: /crm/api/run-migration-1003.php
 * Protected by CRM login + database.manage permission.
 */
declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/loginAuth/auth.php';
requireLogin();
requirePermission('database.manage');

$db  = getDB();
$log = [];

function mig1003_run(PDO $db, string $label, string $sql): string {
    try {
        $db->exec($sql);
        return "✅ $label";
    } catch (\PDOException $e) {
        $msg = $e->getMessage();
        if (stripos($msg, 'already exists') !== false || stripos($msg, 'Duplicate column') !== false) {
            return "⏭ $label — already exists, skipped";
        }
        return "❌ $label — ERROR: $msg";
    }
}

$log[] = mig1003_run($db, 'Create cms_page_sections table', "
    CREATE TABLE IF NOT EXISTS cms_page_sections (
        id         INT AUTO_INCREMENT PRIMARY KEY,
        page_id    INT NOT NULL,
        site_id    INT NOT NULL DEFAULT 1,
        block_type VARCHAR(50) NOT NULL,
        sort_order SMALLINT NOT NULL DEFAULT 0,
        data       TEXT NOT NULL,
        is_active  TINYINT(1) NOT NULL DEFAULT 1,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        INDEX idx_page_sort (page_id, sort_order),
        INDEX idx_site_id (site_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci
");

$failed = array_filter($log, fn($l) => str_starts_with($l, '❌'));
?>
<!DOCTYPE html><html lang="en"><head><meta charset="UTF-8"><title>Migration 1003</title>
<style>body{font-family:monospace;background:#f5f5f5;padding:2rem}ul{list-style:none;padding:0}li{padding:.35rem 0;border-bottom:1px solid #e0e0e0}.ok{color:#2D8659;font-weight:bold}.err{color:#c00;font-weight:bold}</style>
</head><body>
<h1>Migration 1003 — Section Module Library</h1>
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

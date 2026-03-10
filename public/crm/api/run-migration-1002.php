<?php
/**
 * Migration 1002 — Theme Engine
 *
 * Creates cms_themes table. Each site can have one active theme
 * storing CSS custom property overrides (colors, fonts, custom CSS).
 *
 * Run once via: /crm/api/run-migration-1002.php
 * Protected by CRM login + database.manage permission.
 */
declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/loginAuth/auth.php';
requireLogin();
requirePermission('database.manage');

$db  = getDB();
$log = [];
$ok  = true;

function mig1002_run(PDO $db, string $label, string $sql): string {
    try {
        $db->exec($sql);
        return "✅ $label";
    } catch (\PDOException $e) {
        $msg = $e->getMessage();
        // Ignore "already exists" and "duplicate column" errors — idempotent
        if (stripos($msg, 'already exists') !== false || stripos($msg, 'Duplicate column') !== false) {
            return "⏭ $label — already exists, skipped";
        }
        return "❌ $label — ERROR: $msg";
    }
}

// ─── 1. Create cms_themes table ──────────────────────────────────────────────
$log[] = mig1002_run($db, 'Create cms_themes table', "
    CREATE TABLE IF NOT EXISTS cms_themes (
        id            INT AUTO_INCREMENT PRIMARY KEY,
        site_id       INT NOT NULL DEFAULT 1,
        name          VARCHAR(100) NOT NULL DEFAULT 'Default',
        color_primary VARCHAR(20) NOT NULL DEFAULT '#2D8659',
        color_dark    VARCHAR(20) NOT NULL DEFAULT '#1A5F4A',
        color_accent  VARCHAR(20) NOT NULL DEFAULT '#e85d04',
        color_bg_dark VARCHAR(20) NOT NULL DEFAULT '#0d1f12',
        color_text    VARCHAR(20) NOT NULL DEFAULT '#1a1a1a',
        font_heading  VARCHAR(100) NOT NULL DEFAULT 'Playfair Display',
        font_body     VARCHAR(100) NOT NULL DEFAULT 'DM Sans',
        custom_css    TEXT DEFAULT NULL,
        is_active     TINYINT(1) NOT NULL DEFAULT 1,
        created_at    TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        updated_at    TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        INDEX idx_site_id (site_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci
");

// ─── 2. Seed Mowology default theme (site_id = 1) ────────────────────────────
$log[] = mig1002_run($db, 'Seed Mowology default theme', "
    INSERT IGNORE INTO cms_themes
        (id, site_id, name, color_primary, color_dark, color_accent, color_bg_dark, color_text, font_heading, font_body)
    VALUES
        (1, 1, 'Mowology Default', '#2D8659', '#1A5F4A', '#e85d04', '#0d1f12', '#1a1a1a', 'Playfair Display', 'DM Sans')
");

// ─── 3. Seed Talking Hands placeholder theme (site_id = 2) ───────────────────
$log[] = mig1002_run($db, 'Seed Talking Hands default theme', "
    INSERT IGNORE INTO cms_themes
        (id, site_id, name, color_primary, color_dark, color_accent, color_bg_dark, color_text, font_heading, font_body)
    VALUES
        (2, 2, 'Talking Hands Default', '#2D8659', '#1A5F4A', '#e85d04', '#0d1f12', '#1a1a1a', 'Playfair Display', 'DM Sans')
");

// ─── Summary ─────────────────────────────────────────────────────────────────
$failed = array_filter($log, fn($l) => str_starts_with($l, '❌'));
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<title>Migration 1002</title>
<style>
  body { font-family: monospace; background: #f5f5f5; padding: 2rem; }
  h1 { font-size: 1.25rem; }
  ul { list-style: none; padding: 0; }
  li { padding: .35rem 0; border-bottom: 1px solid #e0e0e0; }
  .ok  { color: #2D8659; font-weight: bold; }
  .err { color: #c00; font-weight: bold; }
</style>
</head>
<body>
<h1>Migration 1002 — Theme Engine</h1>
<ul>
<?php foreach ($log as $line): ?>
  <li class="<?= str_starts_with($line, '❌') ? 'err' : 'ok' ?>"><?= htmlspecialchars($line) ?></li>
<?php endforeach; ?>
</ul>
<?php if (empty($failed)): ?>
  <p class="ok" style="margin-top:1rem">✅ Migration complete.</p>
<?php else: ?>
  <p class="err" style="margin-top:1rem">❌ <?= count($failed) ?> step(s) failed.</p>
<?php endif; ?>
<p><a href="/crm/database_appstack.php">← Database Manager</a></p>
</body>
</html>

<?php
/**
 * Migration 211 — invoice_contacts.contact_id nullable
 *
 * The column was originally NOT NULL, but edit.php allows ad-hoc recipients
 * (email typed directly, no linked contact record). Those inserts pass
 * contact_id = NULL and fail the constraint.
 *
 * Steps:
 *   1. Drop the existing FK on contact_id (so we can change the column).
 *   2. Make contact_id NULL DEFAULT NULL.
 *   3. Re-add the FK with ON DELETE SET NULL.
 *
 * Idempotent — checks information_schema before each step.
 */
declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/loginAuth/auth.php';
requireLogin();
requirePermission('database.manage');

$db  = getDB();
$log = [];

// ── Step 1: Find and drop the FK that references contacts(id) ──────────────
$fkRow = $db->query("
    SELECT CONSTRAINT_NAME
    FROM information_schema.KEY_COLUMN_USAGE
    WHERE TABLE_SCHEMA   = DATABASE()
      AND TABLE_NAME     = 'invoice_contacts'
      AND COLUMN_NAME    = 'contact_id'
      AND REFERENCED_TABLE_NAME = 'contacts'
    LIMIT 1
")->fetch(PDO::FETCH_ASSOC);

if ($fkRow) {
    $fkName = $fkRow['CONSTRAINT_NAME'];
    try {
        $db->exec("ALTER TABLE `invoice_contacts` DROP FOREIGN KEY `{$fkName}`");
        $log[] = "✅ Dropped FK `{$fkName}` on invoice_contacts.contact_id";
    } catch (\PDOException $e) {
        $log[] = "❌ Could not drop FK `{$fkName}`: " . $e->getMessage();
    }
} else {
    $log[] = "⏭ No FK on invoice_contacts.contact_id — already dropped or never existed";
}

// ── Step 2: Make contact_id nullable ──────────────────────────────────────
$colRow = $db->query("
    SELECT IS_NULLABLE
    FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME   = 'invoice_contacts'
      AND COLUMN_NAME  = 'contact_id'
")->fetch(PDO::FETCH_ASSOC);

if ($colRow && $colRow['IS_NULLABLE'] === 'NO') {
    try {
        $db->exec("ALTER TABLE `invoice_contacts` MODIFY COLUMN `contact_id` INT NULL DEFAULT NULL");
        $log[] = "✅ Made invoice_contacts.contact_id nullable";
    } catch (\PDOException $e) {
        $log[] = "❌ Could not make contact_id nullable: " . $e->getMessage();
    }
} else {
    $log[] = "⏭ invoice_contacts.contact_id already nullable";
}

// ── Step 3: Re-add FK with ON DELETE SET NULL ──────────────────────────────
$fkExists = $db->query("
    SELECT COUNT(*) FROM information_schema.KEY_COLUMN_USAGE
    WHERE TABLE_SCHEMA   = DATABASE()
      AND TABLE_NAME     = 'invoice_contacts'
      AND COLUMN_NAME    = 'contact_id'
      AND REFERENCED_TABLE_NAME = 'contacts'
")->fetchColumn();

if (!$fkExists) {
    try {
        $db->exec("
            ALTER TABLE `invoice_contacts`
            ADD CONSTRAINT `fk_invoice_contacts_contact`
            FOREIGN KEY (`contact_id`) REFERENCES `contacts`(`id`) ON DELETE SET NULL
        ");
        $log[] = "✅ Re-added FK fk_invoice_contacts_contact with ON DELETE SET NULL";
    } catch (\PDOException $e) {
        $log[] = "❌ Could not add FK: " . $e->getMessage();
    }
} else {
    $log[] = "⏭ FK on invoice_contacts.contact_id already exists";
}

$failed = array_filter($log, fn($l) => str_starts_with($l, '❌'));
?>
<!DOCTYPE html><html lang="en"><head><meta charset="UTF-8"><title>Migration 211</title>
<style>body{font-family:monospace;background:#f5f5f5;padding:2rem;max-width:720px;margin:0 auto}h1{color:#0D3B2E}ul{list-style:none;padding:0}li{padding:.5rem .75rem;border-bottom:1px solid #e0e0e0;background:#fff;margin-bottom:4px;border-radius:6px}.ok{color:#2D8659;font-weight:bold}.err{color:#DC2626;font-weight:bold}.skip{color:#6B7280}</style>
</head><body>
<h1>Migration 211 — invoice_contacts.contact_id nullable</h1>
<p>Allows ad-hoc recipients (typed email, no linked contact record) when editing invoices.</p>
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

<?php
/**
 * Migration 1011 — properties.billing_entity_name
 *
 * Adds a free-text "billed entity name" to the properties table
 * (e.g. "VR14-50") so the contract_billing cron can auto-populate
 * invoices.bill_to_name as "{billing_entity_name} C/O {company_name}"
 * when generating monthly contract invoices.
 *
 * Idempotent — checks information_schema before ADD COLUMN.
 * Run once via: /crm/api/run-migration-1011.php
 */
declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/loginAuth/auth.php';
requireLogin();
requirePermission('database.manage');

$db  = getDB();
$log = [];

function mig1011_add_column(PDO $db, string $table, string $column, string $ddl): string {
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

$log[] = mig1011_add_column(
    $db,
    'properties',
    'billing_entity_name',
    "ALTER TABLE `properties`
     ADD COLUMN `billing_entity_name` VARCHAR(255) NULL DEFAULT NULL
     COMMENT 'Strata / numbered company / legal entity name for this property. Used by contract_billing cron to populate invoices.bill_to_name as \"{billing_entity_name} C/O {company_name}\". Example: \"VR14-50\".'
     AFTER `property_name`"
);

$failed = array_filter($log, fn($l) => str_starts_with($l, '❌'));
?>
<!DOCTYPE html><html lang="en"><head><meta charset="UTF-8"><title>Migration 1011</title>
<style>body{font-family:monospace;background:#f5f5f5;padding:2rem;max-width:720px;margin:0 auto}h1{color:#0D3B2E}ul{list-style:none;padding:0}li{padding:.5rem .75rem;border-bottom:1px solid #e0e0e0;background:#fff;margin-bottom:4px;border-radius:6px}.ok{color:#2D8659;font-weight:bold}.err{color:#DC2626;font-weight:bold}.skip{color:#6B7280}</style>
</head><body>
<h1>Migration 1011 — Property Billing Entity Name</h1>
<p>Adds <code>billing_entity_name</code> to the <code>properties</code> table
   so contract invoices can auto-populate their Bill To header with values
   like "VR14-50 C/O MACDONALD REALTY".</p>
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

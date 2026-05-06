<?php
/**
 * Migration 1030 — Payment flow v2 columns
 *
 * Adds three columns to `invoices`:
 *   - stripe_client_secret VARCHAR(255) NULL — PI client_secret stored at send
 *   - payment_flow_version TINYINT NOT NULL DEFAULT 1 — 1=v1 (click-to-create), 2=v2 (send-to-create)
 *   - pi_created_at DATETIME NULL — staleness check
 *
 * Plus an index on payment_flow_version for the migration query that backfills
 * the customer-facing routing decision.
 *
 * Idempotent — checks information_schema before each ADD COLUMN/CREATE INDEX.
 * Protected by requireLogin() + database.manage permission.
 *
 * Run once via: /crm/api/run-migration-1030.php
 */
declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/loginAuth/auth.php';
requireLogin();
requirePermission('database.manage');

$db  = getDB();
$log = [];

function mig1030_add_column(PDO $db, string $table, string $column, string $ddl): string {
    try {
        $check = $db->prepare("
            SELECT COUNT(*) FROM information_schema.columns
            WHERE table_schema = DATABASE() AND table_name = ? AND column_name = ?
        ");
        $check->execute([$table, $column]);
        if ((int)$check->fetchColumn() > 0) {
            return "SKIP {$table}.{$column} — already exists";
        }
        $db->exec($ddl);
        return "OK   {$table}.{$column} — added";
    } catch (\PDOException $e) {
        return "ERR  {$table}.{$column} — " . $e->getMessage();
    }
}

function mig1030_add_index(PDO $db, string $table, string $index, string $ddl): string {
    try {
        $check = $db->prepare("
            SELECT COUNT(*) FROM information_schema.statistics
            WHERE table_schema = DATABASE() AND table_name = ? AND index_name = ?
        ");
        $check->execute([$table, $index]);
        if ((int)$check->fetchColumn() > 0) {
            return "SKIP {$table}.{$index} — already exists";
        }
        $db->exec($ddl);
        return "OK   {$table}.{$index} — created";
    } catch (\PDOException $e) {
        return "ERR  {$table}.{$index} — " . $e->getMessage();
    }
}

$log[] = mig1030_add_column(
    $db, 'invoices', 'stripe_client_secret',
    "ALTER TABLE `invoices`
     ADD COLUMN `stripe_client_secret` VARCHAR(255) NULL DEFAULT NULL
     COMMENT 'Stripe PaymentIntent client_secret created at invoice send (v2 flow)'
     AFTER `stripe_payment_intent_id`"
);

$log[] = mig1030_add_column(
    $db, 'invoices', 'payment_flow_version',
    "ALTER TABLE `invoices`
     ADD COLUMN `payment_flow_version` TINYINT NOT NULL DEFAULT 1
     COMMENT '1=create PI on customer click (legacy); 2=create PI at invoice send'
     AFTER `stripe_client_secret`"
);

$log[] = mig1030_add_column(
    $db, 'invoices', 'pi_created_at',
    "ALTER TABLE `invoices`
     ADD COLUMN `pi_created_at` DATETIME NULL DEFAULT NULL
     COMMENT 'When the v2 PaymentIntent was created (used to detect staleness)'
     AFTER `payment_flow_version`"
);

$log[] = mig1030_add_index(
    $db, 'invoices', 'idx_invoices_payment_flow_version',
    "CREATE INDEX `idx_invoices_payment_flow_version` ON `invoices`(`payment_flow_version`)"
);

$failed = array_filter($log, fn($l) => strpos($l, 'ERR') === 0);
?>
<!DOCTYPE html><html lang="en"><head><meta charset="UTF-8"><title>Migration 1030</title>
<style>
body{font-family:ui-monospace,monospace;background:#f5f5f5;padding:2rem;max-width:760px;margin:0 auto}
h1{color:#0D3B2E;margin-top:0}
ul{list-style:none;padding:0}
li{padding:.55rem .75rem;border-bottom:1px solid #e5e5e5;background:#fff;margin-bottom:4px;border-radius:6px;font-size:13px}
li.ok{border-left:3px solid #2D8659}
li.err{border-left:3px solid #DC2626;color:#7F1D1D}
li.skip{border-left:3px solid #9CA3AF;color:#6B7280}
.summary{margin-top:1.5rem;padding:.75rem 1rem;border-radius:6px}
.summary.ok{background:#D1FAE5;color:#065F46}
.summary.err{background:#FEE2E2;color:#991B1B}
</style>
</head><body>
<h1>Migration 1030 — Invoice Payment Flow v2</h1>
<p>Adds <code>stripe_client_secret</code>, <code>payment_flow_version</code>, and
<code>pi_created_at</code> to <code>invoices</code>. All additive and nullable —
existing v1 code paths ignore them.</p>
<ul>
<?php foreach ($log as $line):
    $cls = 'ok';
    if (strpos($line, 'ERR') === 0)  $cls = 'err';
    elseif (strpos($line, 'SKIP') === 0) $cls = 'skip';
?>
    <li class="<?php echo $cls; ?>"><?php echo htmlspecialchars($line); ?></li>
<?php endforeach; ?>
</ul>
<div class="summary <?php echo $failed ? 'err' : 'ok'; ?>">
<?php if ($failed): ?>
    <strong>Migration failed.</strong> See errors above. Safe to re-run after fixing.
<?php else: ?>
    <strong>Migration successful.</strong> All columns + index in place. Existing v1 invoices remain untouched (default <code>payment_flow_version = 1</code>).
<?php endif; ?>
</div>
</body></html>

<?php
/**
 * One-shot migration — convert quote-based plans' price_per_visit to NET (pre-GST).
 *
 * Background: plans created from a quote stored the quote's GST-INCLUSIVE total
 * (quotes.amount) into job_plans.price_per_visit, while every consumer (invoicing,
 * dashboards) treats price_per_visit as NET. This converts those gross values to the
 * quote's pre-GST subtotal so storage matches the rest of the system.
 *
 * SAFE BY DEFAULT:
 *   - No ?confirm=1  → DRY RUN. Classifies + reports, changes nothing.
 *   - ?confirm=1     → backs up affected rows to job_plans_ppv_backup, then UPDATEs.
 *
 * Classification (±$0.02 tolerance) for plans with quote_id set:
 *   - stored ≈ quote gross (amount/total_amount) AND quote.subtotal present → CONVERT to subtotal
 *   - stored ≈ quote.subtotal already                                       → SKIP (already net)
 *   - neither (quote edited since / manual override / no subtotal)          → LEAVE, report for review
 * Converted plans that already have invoices are flagged separately — their
 * issued invoices are NOT altered (business decision, out of scope).
 *
 * Restore:
 *   UPDATE job_plans jp JOIN job_plans_ppv_backup b ON b.orig_id = jp.id
 *   SET jp.price_per_visit = b.old_price_per_visit, jp.estimated_amount = b.old_estimated_amount;
 *
 * Protected by CRM login + database.manage permission.
 */
declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/loginAuth/auth.php';
requireLogin();
requirePermission('database.manage');

$db      = getDB();
$confirm = isset($_GET['confirm']) && $_GET['confirm'] === '1';
$TOL     = 0.02;
$log     = [];

$rows = $db->query("
    SELECT jp.id, jp.plan_number, jp.price_per_visit, jp.estimated_amount,
           q.subtotal AS q_subtotal, q.amount AS q_amount, q.total_amount AS q_total,
           EXISTS(SELECT 1 FROM invoices i WHERE i.plan_id = jp.id) AS has_invoices
    FROM job_plans jp
    JOIN quotes q ON jp.quote_id = q.id
    WHERE jp.quote_id IS NOT NULL
      AND jp.price_per_visit IS NOT NULL
")->fetchAll(PDO::FETCH_ASSOC);

$convert = [];   // rows to update
$skip    = [];   // already net
$review  = [];   // neither — leave, human review

foreach ($rows as $r) {
    $ppv      = (float)$r['price_per_visit'];
    $subtotal = $r['q_subtotal'] !== null ? (float)$r['q_subtotal'] : null;
    $gross    = $r['q_amount']   !== null ? (float)$r['q_amount']
              : ($r['q_total']   !== null ? (float)$r['q_total'] : null);

    $isNet   = $subtotal !== null && abs($ppv - $subtotal) <= $TOL;
    $isGross = $gross    !== null && abs($ppv - $gross)    <= $TOL;

    if ($isNet) {
        $skip[] = $r;                       // already correct (skip even if also ≈ gross)
    } elseif ($isGross && $subtotal !== null) {
        $r['target'] = round($subtotal, 2); // convert gross → net subtotal
        $convert[] = $r;
    } else {
        $review[] = $r;                     // edited/no-subtotal/custom — leave alone
    }
}

$deleted = 0; // (reused as "updated" count)
if ($confirm && $convert) {
    $db->exec("
        CREATE TABLE IF NOT EXISTS job_plans_ppv_backup (
            bak_id               INT AUTO_INCREMENT PRIMARY KEY,
            orig_id              INT NOT NULL,
            plan_number          VARCHAR(50) NULL,
            old_price_per_visit  DECIMAL(10,2) NULL,
            old_estimated_amount DECIMAL(10,2) NULL,
            new_value            DECIMAL(10,2) NULL,
            migrated_at          DATETIME NOT NULL,
            INDEX idx_orig (orig_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci
    ");

    $bak = $db->prepare("
        INSERT INTO job_plans_ppv_backup
            (orig_id, plan_number, old_price_per_visit, old_estimated_amount, new_value, migrated_at)
        VALUES (?, ?, ?, ?, ?, NOW())
    ");
    $upd = $db->prepare("UPDATE job_plans SET price_per_visit = ?, estimated_amount = ? WHERE id = ?");

    $db->beginTransaction();
    try {
        foreach ($convert as $r) {
            $bak->execute([(int)$r['id'], $r['plan_number'], $r['price_per_visit'], $r['estimated_amount'], $r['target']]);
            $upd->execute([$r['target'], $r['target'], (int)$r['id']]);
            $deleted++;
        }
        $db->commit();
    } catch (Throwable $e) {
        $db->rollBack();
        $deleted = 0;
        $log[] = '❌ Migration failed (rolled back): ' . $e->getMessage();
    }
}

$convInvoiced = array_values(array_filter($convert, fn($r) => (int)$r['has_invoices'] === 1));

function fmt($v) { return $v === null ? '—' : '$' . number_format((float)$v, 2); }
?>
<!DOCTYPE html><html lang="en"><head><meta charset="UTF-8"><title>Migrate price_per_visit → NET</title>
<style>
 body{font-family:monospace;background:#f5f5f5;padding:2rem;max-width:1000px;margin:0 auto;color:#1A5F4A}
 h1{color:#0D3B2E}h3{margin-top:1.5rem}.ok{color:#2D8659;font-weight:bold}.err{color:#c00;font-weight:bold}.warn{color:#e85d04;font-weight:bold}
 table{border-collapse:collapse;margin:.4rem 0 1rem;width:100%}td,th{padding:.3rem .6rem;border:1px solid #ccc;text-align:left;font-size:.85rem}
 .banner{padding:1rem;border-radius:8px;margin-bottom:1rem;font-weight:bold}
 .banner.dry{background:#E8F3F0;border:1px solid #2D8659}.banner.done{background:#d4edda;border:1px solid #2D8659}
 .cta{display:inline-block;margin-top:1rem;padding:.6rem 1.2rem;background:#c00;color:#fff;text-decoration:none;border-radius:6px;font-weight:bold}
</style></head><body>
<h1>Migrate <code>price_per_visit</code> → NET (pre-GST)</h1>

<?php if (!$confirm): ?>
  <div class="banner dry">DRY RUN — nothing changed. <?= count($convert) ?> plan(s) would convert, <?= count($skip) ?> already net, <?= count($review) ?> need review.</div>
<?php else: ?>
  <div class="banner done">✅ Converted <?= $deleted ?> plan(s) to NET. Backed up to <code>job_plans_ppv_backup</code> (recoverable).</div>
  <?php foreach ($log as $l): ?><p class="err"><?= htmlspecialchars($l) ?></p><?php endforeach; ?>
<?php endif; ?>

<h3 class="<?= $convert ? 'warn' : '' ?>">CONVERT — gross → net (<?= count($convert) ?>)</h3>
<?php if ($convert): ?>
<table><tr><th>Plan</th><th>Stored (gross)</th><th>Quote subtotal (net)</th><th>→ New</th><th>Has invoices?</th></tr>
<?php foreach ($convert as $r): ?>
  <tr><td><?= htmlspecialchars((string)$r['plan_number']) ?> (#<?= (int)$r['id'] ?>)</td>
      <td><?= fmt($r['price_per_visit']) ?></td><td><?= fmt($r['q_subtotal']) ?></td>
      <td class="ok"><?= fmt($r['target']) ?></td>
      <td><?= ((int)$r['has_invoices'] === 1) ? '<span class="warn">YES</span>' : 'no' ?></td></tr>
<?php endforeach; ?>
</table>
<?php else: ?><p>None.</p><?php endif; ?>

<?php if ($convInvoiced): ?>
<h3 class="warn">⚠ Converted plans that ALREADY have invoices (<?= count($convInvoiced) ?>)</h3>
<p>Their plan price is now correct, but invoices already issued were billed from the old gross value and are <strong>not</strong> changed here. Review manually if any unsent/draft invoices need re-pricing.</p>
<table><tr><th>Plan</th><th>Old</th><th>New</th></tr>
<?php foreach ($convInvoiced as $r): ?>
  <tr><td><?= htmlspecialchars((string)$r['plan_number']) ?> (#<?= (int)$r['id'] ?>)</td><td><?= fmt($r['price_per_visit']) ?></td><td><?= fmt($r['target']) ?></td></tr>
<?php endforeach; ?>
</table>
<?php endif; ?>

<h3 class="<?= $review ? 'warn' : '' ?>">REVIEW — matched neither gross nor net, left untouched (<?= count($review) ?>)</h3>
<?php if ($review): ?>
<table><tr><th>Plan</th><th>Stored</th><th>Quote subtotal</th><th>Quote gross</th></tr>
<?php foreach ($review as $r): ?>
  <tr><td><?= htmlspecialchars((string)$r['plan_number']) ?> (#<?= (int)$r['id'] ?>)</td>
      <td><?= fmt($r['price_per_visit']) ?></td><td><?= fmt($r['q_subtotal']) ?></td><td><?= fmt($r['q_amount']) ?></td></tr>
<?php endforeach; ?>
</table>
<?php else: ?><p>None.</p><?php endif; ?>

<h3>SKIP — already net (<?= count($skip) ?>)</h3>

<?php if (!$confirm && $convert): ?>
  <a class="cta" href="?confirm=1">⚠ Convert these <?= count($convert) ?> plans now (backed up first)</a>
<?php endif; ?>
<p style="margin-top:2rem"><a href="/crm/jobs/">← Plans</a></p>
</body></html>

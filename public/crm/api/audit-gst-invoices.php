<?php
/**
 * READ-ONLY audit — find invoices double-taxed by the gross price_per_visit bug.
 *
 * A quote-based plan that stored its price GST-inclusive (gross) could be invoiced
 * with that gross figure used as the line subtotal, then GST added again. This audit
 * flags every invoice on a quote-based plan whose subtotal matches the quote GROSS
 * (per visit) rather than the NET subtotal, and quantifies the over-billing.
 *
 * Makes NO changes. Admin-gated (database.manage). Nothing here mutates data.
 */
declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/loginAuth/auth.php';
requireLogin();
requirePermission('database.manage');

$db  = getDB();
$TOL = 0.03; // per-visit tolerance

$rows = $db->query("
    SELECT i.id, i.invoice_number, i.subtotal, i.tax_amount, i.total, i.status,
           i.amount_paid, i.balance_due,
           jp.plan_number,
           q.subtotal AS q_net, q.amount AS q_gross, q.total_amount AS q_total_gross,
           COALESCE(ct.first_name,'') AS first_name, COALESCE(ct.last_name,'') AS last_name,
           comp.company_name,
           (SELECT COUNT(*) FROM job_visits jv WHERE jv.invoice_id = i.id) AS visit_count
    FROM invoices i
    JOIN job_plans jp ON i.plan_id = jp.id
    JOIN quotes q     ON jp.quote_id = q.id
    LEFT JOIN contacts  ct   ON i.contact_id = ct.id
    LEFT JOIN companies comp ON i.company_id = comp.id
    WHERE i.plan_id IS NOT NULL AND jp.quote_id IS NOT NULL
    ORDER BY i.id DESC
")->fetchAll(PDO::FETCH_ASSOC);

$bad = [];   // double-taxed
$ok  = [];   // correct
$rev = [];   // couldn't classify
$totalOver = 0.0;
$totalOverPaid = 0.0;

foreach ($rows as $r) {
    $sub   = (float)$r['subtotal'];
    $net   = $r['q_net']   !== null ? (float)$r['q_net']   : null;
    $gross = $r['q_gross'] !== null ? (float)$r['q_gross'] : ($r['q_total_gross'] !== null ? (float)$r['q_total_gross'] : null);
    $n     = max(1, (int)$r['visit_count']);

    if ($net === null || $gross === null || abs($gross - $net) < 0.01) {
        $rev[] = $r; // no usable reference, or quote had no GST
        continue;
    }

    $expNet   = round($net   * $n, 2);
    $expGross = round($gross * $n, 2);

    if (abs($sub - $expGross) <= $TOL * $n && abs($sub - $expNet) > $TOL * $n) {
        // subtotal looks like the GROSS figure → GST applied twice
        $correctTotal = round($expNet * 1.05, 2);
        $over = round((float)$r['total'] - $correctTotal, 2);
        $r['expected_net_subtotal'] = $expNet;
        $r['correct_total'] = $correctTotal;
        $r['overbilled'] = $over;
        $bad[] = $r;
        $totalOver += $over;
        if (in_array($r['status'], ['paid', 'partial'])) $totalOverPaid += $over;
    } elseif (abs($sub - $expNet) <= $TOL * $n) {
        $ok[] = $r;
    } else {
        $rev[] = $r;
    }
}

function nm($r){ $c = trim((string)($r['company_name'] ?? '')); $p = trim(($r['first_name'].' '.$r['last_name'])); return htmlspecialchars($c ?: $p ?: '—'); }
function m($v){ return $v === null ? '—' : '$'.number_format((float)$v,2); }
?>
<!DOCTYPE html><html lang="en"><head><meta charset="UTF-8"><title>GST Invoice Audit</title>
<style>
 body{font-family:monospace;background:#f5f5f5;padding:2rem;max-width:1100px;margin:0 auto;color:#1A5F4A}
 h1{color:#0D3B2E}h3{margin-top:1.5rem}.err{color:#c00;font-weight:bold}.ok{color:#2D8659;font-weight:bold}.warn{color:#e85d04;font-weight:bold}
 table{border-collapse:collapse;margin:.4rem 0 1rem;width:100%}td,th{padding:.3rem .55rem;border:1px solid #ccc;text-align:left;font-size:.82rem}
 .banner{padding:1rem;border-radius:8px;margin-bottom:1rem;font-weight:bold;background:#fdecea;border:1px solid #c00}
 .paid{background:#ffe9e9}
</style></head><body>
<h1>GST Double-Tax Invoice Audit <small style="font-weight:normal">(read-only)</small></h1>
<div class="banner">
  <?= count($bad) ?> invoice(s) over-billed (double GST). Total over-billed: <span class="err"><?= m($totalOver) ?></span>
  — of which already PAID: <span class="err"><?= m($totalOverPaid) ?></span>.<br>
  <?= count($ok) ?> correct · <?= count($rev) ?> need manual review · <?= count($rows) ?> quote-based-plan invoices scanned.
</div>

<h3 class="err">OVER-BILLED — double GST (<?= count($bad) ?>)</h3>
<?php if ($bad): ?>
<table><tr><th>Invoice</th><th>Customer</th><th>Plan</th><th>Billed total</th><th>Should be</th><th>Over</th><th>Status</th><th>Paid</th></tr>
<?php foreach ($bad as $r): ?>
  <tr class="<?= in_array($r['status'],['paid','partial']) ? 'paid' : '' ?>">
    <td><?= htmlspecialchars((string)$r['invoice_number']) ?></td>
    <td><?= nm($r) ?></td>
    <td><?= htmlspecialchars((string)$r['plan_number']) ?></td>
    <td><?= m($r['total']) ?></td>
    <td class="ok"><?= m($r['correct_total']) ?></td>
    <td class="err"><?= m($r['overbilled']) ?></td>
    <td><?= htmlspecialchars((string)$r['status']) ?></td>
    <td><?= m($r['amount_paid']) ?></td>
  </tr>
<?php endforeach; ?>
</table>
<?php else: ?><p class="ok">None found.</p><?php endif; ?>

<h3 class="<?= $rev ? 'warn' : '' ?>">REVIEW — couldn't auto-classify (<?= count($rev) ?>)</h3>
<?php if ($rev): ?>
<table><tr><th>Invoice</th><th>Customer</th><th>Plan</th><th>Subtotal</th><th>Quote net</th><th>Quote gross</th><th>Visits</th><th>Status</th></tr>
<?php foreach ($rev as $r): ?>
  <tr><td><?= htmlspecialchars((string)$r['invoice_number']) ?></td><td><?= nm($r) ?></td>
      <td><?= htmlspecialchars((string)$r['plan_number']) ?></td><td><?= m($r['subtotal']) ?></td>
      <td><?= m($r['q_net']) ?></td><td><?= m($r['q_gross']) ?></td><td><?= (int)$r['visit_count'] ?></td>
      <td><?= htmlspecialchars((string)$r['status']) ?></td></tr>
<?php endforeach; ?>
</table>
<?php else: ?><p>None.</p><?php endif; ?>

<h3 class="ok">CORRECT — net + GST (<?= count($ok) ?>)</h3>
<p style="margin-top:2rem"><a href="/crm/invoices/">← Invoices</a></p>
</body></html>

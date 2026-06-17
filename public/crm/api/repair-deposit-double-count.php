<?php
/**
 * One-time data repair — historical bank-deposit double-count.
 *
 * Context: before the fix in commit c55d2ba4 (deposit booked as type='transfer'),
 * BankImportService::commit() reconciled an auto-matched invoice deposit by marking
 * the invoice's own income row reconciled AND inserting the bank deposit as a SECOND
 * type='income' row. Every P&L / GST report sums type='income', so revenue for those
 * periods is overstated by the deposit amount. The fix is forward-only; rows committed
 * before it remain double-counted. This tool repairs them.
 *
 * Targeting (precise): a bank_import staging row whose match_status='auto_matched' and
 * whose raw_row JSON carries matched_invoice_tx_id (an INVOICE match, not an expense
 * receipt), linked to an accounting_transactions row that is still
 * reference_type='bank_import' AND type='income'. Post-fix deposits are already
 * type='transfer' and are skipped, so this is idempotent.
 *
 * Repair: flip those deposits to type='transfer', status='reconciled', and link them to
 * the invoice — exactly what the fixed commit() now does. Income stays recognized once
 * on the invoice's own ledger row.
 *
 * SAFETY: admin-only. DRY-RUN by default — reports the overstatement per period and
 * lists every affected row. Pass ?confirm=1 to actually write (inside one transaction).
 *
 * Usage:
 *   /crm/api/repair-deposit-double-count.php            → dry run (no writes)
 *   /crm/api/repair-deposit-double-count.php?confirm=1  → apply the fix
 */
declare(strict_types=1);

$__dir = __DIR__;
for ($__i = 0; $__i < 5; $__i++) {
    $__dir = dirname($__dir);
    if (is_file($__dir . '/app/Core/paths.php')) { require_once $__dir . '/app/Core/paths.php'; break; }
}
unset($__dir, $__i);

require_once PUBLIC_ROOT . '/loginAuth/auth.php';
requireLogin();
if (!function_exists('isAdmin') || !isAdmin()) {
    http_response_code(403);
    header('Content-Type: text/plain');
    echo 'Admin only.';
    exit;
}

$confirm = isset($_GET['confirm']) && $_GET['confirm'] === '1';
$db = getDB();

// ── Identify the double-counted deposits ──────────────────────────────────────
$stmt = $db->query("
    SELECT bir.transaction_id, bir.raw_row,
           t.id AS tx_id, t.amount, t.transaction_date, t.description, t.match_confidence
    FROM bank_import_rows bir
    JOIN accounting_transactions t ON t.id = bir.transaction_id
    WHERE bir.match_status = 'auto_matched'
      AND t.reference_type = 'bank_import'
      AND t.type = 'income'
    ORDER BY t.transaction_date
");

$targets   = [];
$byPeriod  = [];
$grandTotal = 0.0;

foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $r) {
    $raw = json_decode($r['raw_row'] ?? '', true);
    if (!is_array($raw) || empty($raw['matched_invoice_tx_id'])) {
        // Not an invoice match (e.g. an expense receipt) — leave it alone.
        continue;
    }
    $amount    = (float)$r['amount'];
    $invoiceId = (int)($raw['matched_invoice_id'] ?? 0);
    $period    = substr((string)$r['transaction_date'], 0, 7); // YYYY-MM

    $targets[] = [
        'tx_id'       => (int)$r['tx_id'],
        'date'        => $r['transaction_date'],
        'amount'      => $amount,
        'invoice_id'  => $invoiceId,
        'confidence'  => $r['match_confidence'] !== null ? (int)$r['match_confidence'] : null,
        'description' => $r['description'],
    ];
    $byPeriod[$period] = ($byPeriod[$period] ?? 0) + $amount;
    $grandTotal += $amount;
}
ksort($byPeriod);

// ── Apply (only with explicit confirm) ────────────────────────────────────────
$applied = 0;
if ($confirm && $targets) {
    $db->beginTransaction();
    try {
        $up = $db->prepare("
            UPDATE accounting_transactions
            SET type = 'transfer',
                status = 'reconciled',
                matched_invoice_id = COALESCE(matched_invoice_id, ?),
                matched_at = COALESCE(matched_at, NOW()),
                matched_by = COALESCE(matched_by, 'repair-ddc')
            WHERE id = ? AND reference_type = 'bank_import' AND type = 'income'
        ");
        foreach ($targets as $t) {
            $up->execute([$t['invoice_id'] ?: null, $t['tx_id']]);
            $applied += $up->rowCount();
        }
        $db->commit();
    } catch (Throwable $e) {
        if ($db->inTransaction()) $db->rollBack();
        http_response_code(500);
        header('Content-Type: text/plain');
        echo 'Repair failed (no changes committed): ' . $e->getMessage();
        exit;
    }
}

// ── Report ────────────────────────────────────────────────────────────────────
$h = fn($v) => htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8');
$money = fn($v) => '$' . number_format((float)$v, 2);
?><!DOCTYPE html>
<html lang="en"><head>
<meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1">
<title>Deposit double-count repair</title>
<style>
  body{font-family:system-ui,-apple-system,sans-serif;max-width:880px;margin:32px auto;padding:0 20px;color:#1a202c}
  h1{font-size:22px;margin:0 0 4px}
  .sub{color:#718096;margin:0 0 24px;font-size:14px}
  .banner{padding:14px 16px;border-radius:10px;margin:0 0 20px;font-size:15px}
  .dry{background:#FFF7ED;border:1px solid #FED7AA;color:#9A3412}
  .done{background:#E8F3F0;border:1px solid #2D8659;color:#1A5F4A}
  .none{background:#F1F5F9;border:1px solid #CBD5E0;color:#475569}
  table{width:100%;border-collapse:collapse;margin:14px 0;font-size:14px}
  th,td{text-align:left;padding:8px 10px;border-bottom:1px solid #edf2f0}
  th{font-size:12px;text-transform:uppercase;letter-spacing:.04em;color:#94a3a0}
  td.num,th.num{text-align:right;font-variant-numeric:tabular-nums}
  .total td{font-weight:700;border-top:2px solid #2D8659;border-bottom:none}
  .cta{display:inline-block;margin-top:18px;background:#2D8659;color:#fff;padding:11px 18px;border-radius:8px;text-decoration:none;font-weight:500}
  .muted{color:#94a3a0;font-size:13px;margin-top:24px}
  code{background:#f1f5f4;padding:1px 6px;border-radius:4px}
</style></head><body>
<h1>Bank-deposit double-count repair</h1>
<p class="sub">Deposits auto-matched to an invoice but booked as a second income row (pre-fix), overstating revenue &amp; GST.</p>

<?php if (!$targets): ?>
  <div class="banner none">No double-counted deposits found. Nothing to repair — your books are clean on this front.</div>
<?php elseif ($confirm): ?>
  <div class="banner done"><strong>Repair applied.</strong> <?= (int)$applied ?> deposit row(s) flipped to <code>transfer</code> (excluded from income). Revenue for the periods below is now corrected by <strong><?= $money($grandTotal) ?></strong>. Re-running this page is safe (idempotent).</div>
<?php else: ?>
  <div class="banner dry"><strong>DRY RUN — no changes made.</strong> Found <strong><?= count($targets) ?></strong> double-counted deposit(s) overstating income by <strong><?= $money($grandTotal) ?></strong>. Review below, then apply.</div>
<?php endif; ?>

<?php if ($targets): ?>
  <h2 style="font-size:16px">Overstatement by period</h2>
  <table>
    <thead><tr><th>Period</th><th class="num">Overstated income</th></tr></thead>
    <tbody>
      <?php foreach ($byPeriod as $p => $amt): ?>
        <tr><td><?= $h($p) ?></td><td class="num"><?= $money($amt) ?></td></tr>
      <?php endforeach; ?>
      <tr class="total"><td>Total</td><td class="num"><?= $money($grandTotal) ?></td></tr>
    </tbody>
  </table>

  <h2 style="font-size:16px">Affected deposits (<?= count($targets) ?>)</h2>
  <table>
    <thead><tr><th>Tx ID</th><th>Date</th><th>Invoice</th><th>Description</th><th class="num">Amount</th></tr></thead>
    <tbody>
      <?php foreach ($targets as $t): ?>
        <tr>
          <td><?= (int)$t['tx_id'] ?></td>
          <td><?= $h($t['date']) ?></td>
          <td><?= $t['invoice_id'] ? ('#' . (int)$t['invoice_id']) : '—' ?></td>
          <td><?= $h(mb_substr((string)$t['description'], 0, 60)) ?></td>
          <td class="num"><?= $money($t['amount']) ?></td>
        </tr>
      <?php endforeach; ?>
    </tbody>
  </table>

  <?php if (!$confirm): ?>
    <a class="cta" href="?confirm=1" onclick="return confirm('Apply the repair? This flips <?= count($targets) ?> deposit(s) to transfer and corrects <?= $money($grandTotal) ?> of overstated income.');">Apply repair (<?= $money($grandTotal) ?>)</a>
    <p class="muted">This only flips the duplicate <code>bank_import</code> deposit rows to <code>transfer</code>; the invoice's own income row is untouched, so revenue stays recognized exactly once.</p>
  <?php endif; ?>
<?php endif; ?>
</body></html>

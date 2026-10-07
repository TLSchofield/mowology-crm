<?php
/**
 * Split Stripe payouts that were booked as income into the invoices they paid + fees.
 *
 * GET reads up to BATCH payout lines and asks Stripe (read only — list calls) what
 * each one holds; nothing changes. POST (admin, CSRF, typed confirm BOOK-STRIPE-PAYOUTS)
 * books the ones in that batch that add up exactly; the rest stay, with the reason.
 * Run duplicate bank lines (run-bank-duplicates.php) first. Logic: StripePayoutService.
 */
declare(strict_types=1);

$__dir = __DIR__;
for ($__i = 0; $__i < 5; $__i++) {
    $__dir = dirname($__dir);
    if (is_file($__dir . '/app/Core/paths.php')) {
        require_once $__dir . '/app/Core/paths.php';
        break;
    }
}
unset($__dir, $__i);

require_once PUBLIC_ROOT . '/loginAuth/auth.php';
requireLogin();
if (!isAdmin()) {
    http_response_code(403);
    die('Admin only');
}
require_once APP_ROOT . '/Modules/Accounting/Services/StripePayoutService.php';

const CONFIRM_PHRASE = 'BOOK-STRIPE-PAYOUTS';
const BATCH = 40;
set_time_limit(300);

$db = getDB();
$svc = new StripePayoutService($db);
$message = null; $errors = [];
$all = $svc->waiting(1000);
$from = max(0, (int)($_GET['from'] ?? 0));
$batch = array_slice($all, $from, BATCH);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verifyCSRFToken((string)($_POST['csrf_token'] ?? ''))) { http_response_code(400); die('Bad CSRF token'); }
    if (($_POST['confirm'] ?? '') !== CONFIRM_PHRASE) {
        $message = 'Type ' . CONFIRM_PHRASE . ' to confirm. Nothing was changed.';
    } else {
        $booked = 0; $fees = 0.0;
        foreach ($batch as $line) {
            try {
                $r = $svc->apply((int)$line['id'], (int)(getCurrentUser()['id'] ?? 0));
                if ($r['ok']) { $booked++; $fees += (float)$r['fees']; }
                else $errors[] = $line['transaction_date'] . ' $' . number_format((float)$line['amount'], 2) . ': ' . $r['message'];
            } catch (Throwable $e) {
                $errors[] = $line['transaction_date'] . ': ' . $e->getMessage();
            }
        }
        $message = "Booked {$booked} payouts and \$" . number_format($fees, 2) . ' of Stripe fees.' . ($errors ? ' ' . count($errors) . ' left for you (below).' : '');
        $all = $svc->waiting(1000);
        $batch = array_slice($all, $from, BATCH);
    }
}

$read = [];
foreach ($batch as $line) {
    try { $read[] = ['line' => $line, 'r' => $svc->read($line)]; }
    catch (Throwable $e) { $read[] = ['line' => $line, 'r' => ['ok' => false, 'reason' => 'Stripe: ' . $e->getMessage(), 'invoices' => [], 'fees' => 0]]; }
}
$ok = array_filter($read, fn($x) => $x['r']['ok']);
$total = array_sum(array_map(fn($l) => (float)$l['amount'], $all));
$token = generateCSRFToken();
$money = fn($v) => '$' . number_format((float)$v, 2);
?>
<!doctype html>
<html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1"><title>Stripe payouts</title></head>
<body style="font-family: system-ui, sans-serif; max-width: 1000px; margin: 24px auto; padding: 0 16px;">
<h2>Stripe payouts counted as income</h2>
<p>Stripe is only <b>read</b> here — nothing is changed at Stripe.</p>
<?php if ($message): ?><p><strong><?= h($message) ?></strong></p><?php endif; ?>
<?php if ($errors): ?><ul><?php foreach (array_slice($errors, 0, 40) as $e): ?><li><?= h($e) ?></li><?php endforeach; ?></ul><?php endif; ?>
<p><b><?= count($all) ?></b> payout lines (<?= h($money($total)) ?>) are booked as income while their invoices already are.
   Showing <?= $from + 1 ?>–<?= $from + count($batch) ?>: <b><?= count($ok) ?></b> add up exactly and can be booked.</p>
<?php if ($read): ?>
<table border="1" cellpadding="5" style="border-collapse: collapse; font-size: 14px;">
  <tr><th>Date</th><th>Payout</th><th>Invoices it paid</th><th>Stripe fees</th><th></th></tr>
  <?php foreach ($read as $x): $l = $x['line']; $r = $x['r']; ?>
    <tr><td><?= h($l['transaction_date']) ?></td><td align="right"><?= h($money($l['amount'])) ?></td>
        <td><?= $r['ok'] ? h(implode(', ', array_map(fn($i) => $i['number'] . ' ' . $money($i['amount']), $r['invoices']))) : '<i>' . h($r['reason']) . '</i>' ?></td>
        <td align="right"><?= $r['ok'] ? h($money($r['fees'])) : '' ?></td><td><?= $r['ok'] ? '✓' : 'left for you' ?></td></tr>
  <?php endforeach; ?>
</table>
<?php if ($ok): ?>
<form method="post" action="?from=<?= $from ?>" style="margin-top: 16px;">
  <input type="hidden" name="csrf_token" value="<?= h($token) ?>">
  <label>Type <?= h(CONFIRM_PHRASE) ?> to book the <?= count($ok) ?> that add up: <input name="confirm" autocomplete="off"></label>
  <button type="submit">Book them</button>
</form>
<p>Then reload for the next <?= BATCH ?>.</p>
<?php endif; ?>
<?php if (count($all) > $from + BATCH): ?><p><a href="?from=<?= $from + BATCH ?>">Next <?= BATCH ?> →</a></p><?php endif; ?>
<?php else: ?><p>None left. 🎉</p><?php endif; ?>
</body></html>

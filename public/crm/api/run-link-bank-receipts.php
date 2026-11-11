<?php
/**
 * Link bank spending lines to their receipts where the match is clear (BankReceiptSweep).
 *
 * GET shows what would be linked and how much was being counted twice — changes nothing.
 * POST (admin, CSRF, typed confirm LINK-RECEIPTS) links them: each bank line is attached
 * to its receipt (the receipts page's own attach) and the bank line's separate entry in
 * the books is reversed (never deleted), since the receipt's entry already carries that cost. Weaker
 * matches are left for Penny's review on the dashboard. Safe to run again.
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
require_once APP_ROOT . '/Modules/Accounting/Services/BankReceiptSweep.php';

const CONFIRM_PHRASE = 'LINK-RECEIPTS';
set_time_limit(300);

$db = getDB();
$user = getCurrentUser();
$sweep = new BankReceiptSweep($db);
$message = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verifyCSRFToken((string)($_POST['csrf_token'] ?? ''))) {
        http_response_code(400);
        die('Bad CSRF token');
    }
    if (($_POST['confirm'] ?? '') !== CONFIRM_PHRASE) {
        $message = 'Type ' . CONFIRM_PHRASE . ' to confirm. Nothing was changed.';
    } else {
        $r = $sweep->apply((int)$user['id']);
        $message = "Linked {$r['linked']} bank lines to their receipts" . ($r['failed'] ? ", {$r['failed']} could not be linked" : '') . '.';
    }
}
$plan = $sweep->plan();
$token = generateCSRFToken();
$money = fn($v) => '$' . number_format((float)$v, 2);
$doubles = count(array_filter($plan['links'], fn($x) => $x['double']));
?>
<!doctype html>
<html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1"><title>Link bank lines to receipts</title></head>
<body style="font-family: system-ui, sans-serif; max-width: 980px; margin: 24px auto; padding: 0 16px;">
<h2>Link bank lines to their receipts</h2>
<?php if ($message): ?><p><strong><?= h($message) ?></strong></p><?php endif; ?>
<p><?= (int)$plan['lines'] ?> spending lines on your statements have no receipt linked.
   <b><?= count($plan['links']) ?></b> have a clear match (exact amount, within 3 days, vendor name on the statement, no close second).
   <?= (int)$plan['weak'] ?> have only a possible match — Penny will show you those one at a time.</p>
<p><b><?= $doubles ?></b> of these were counted twice in your books (the bank line and the receipt each posted):
   <b><?= h($money($plan['double_counted'])) ?></b> of cost that linking reverses out (a reversing entry — nothing is deleted).</p>

<?php if ($plan['links']): ?>
  <table border="1" cellpadding="5" style="border-collapse: collapse; font-size: 14px;">
    <tr><th>Bank date</th><th>Statement</th><th>Amount</th><th>Receipt</th><th>Counted twice?</th></tr>
    <?php foreach (array_slice($plan['links'], 0, 60) as $x): $r = $x['receipt']; ?>
      <tr><td><?= h($x['line']['transaction_date']) ?></td><td><?= h($x['line']['description']) ?></td>
          <td align="right"><?= h($money($x['line']['amount'])) ?></td>
          <td>#<?= (int)$r['expense_id'] ?> <?= h($r['vendor']) ?> · <?= h($r['date']) ?> · <?= h($r['category'] ?: 'no category') ?> (<?= h($r['status']) ?>)</td>
          <td><?= $x['double'] ? 'yes' : '' ?></td></tr>
    <?php endforeach; ?>
  </table>
  <?php if (count($plan['links']) > 60): ?><p>…and <?= count($plan['links']) - 60 ?> more.</p><?php endif; ?>
  <form method="post" style="margin-top: 16px;">
    <input type="hidden" name="csrf_token" value="<?= h($token) ?>">
    <label>Type <?= h(CONFIRM_PHRASE) ?> to link them: <input name="confirm" autocomplete="off"></label>
    <button type="submit">Link</button>
  </form>
<?php else: ?>
  <p>Nothing clear left to link.</p>
<?php endif; ?>
</body></html>

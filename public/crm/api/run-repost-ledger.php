<?php
/**
 * Re-post the books: move already-posted invoices and receipts to the right accounts.
 *
 * Needs migration 1130 (the income / spending account maps). GET shows what would move
 * and the net change per account — it changes nothing. POST (admin, CSRF, typed
 * confirm REPOST-LEDGER) re-posts only the entries whose account changes; payments,
 * bank lines and manual entries are never touched. Safe to run again: a second run
 * finds nothing left to move. Logic: LedgerRepostService.
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
require_once APP_ROOT . '/Modules/Accounting/Services/LedgerRepostService.php';

const CONFIRM_PHRASE = 'REPOST-LEDGER';
set_time_limit(300);

$db = getDB();
$map = new LedgerAccountMap($db);
$message = null;
$result = null;

if (!$map->ready()) {
    $message = 'Run migration 1130 first (the income and spending account maps).';
} elseif ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verifyCSRFToken((string)($_POST['csrf_token'] ?? ''))) {
        http_response_code(400);
        die('Bad CSRF token');
    }
    if (($_POST['confirm'] ?? '') !== CONFIRM_PHRASE) {
        $message = 'Type ' . CONFIRM_PHRASE . ' to confirm. Nothing was changed.';
    } else {
        $result = (new LedgerRepostService($db))->apply((int)(getCurrentUser()['id'] ?? 0));
        $message = "Re-posted {$result['reposted']} entries" . ($result['failed'] ? ", {$result['failed']} failed (the nightly sync will post those again)" : '') . '.';
    }
}
$plan = $map->ready() ? (new LedgerRepostService($db))->plan() : null;
$token = generateCSRFToken();
$money = fn($v) => '$' . number_format((float)$v, 2);
?>
<!doctype html>
<html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1"><title>Re-post the books</title></head>
<body style="font-family: system-ui, sans-serif; max-width: 960px; margin: 24px auto; padding: 0 16px;">
<h2>Re-post the books to the right accounts</h2>
<?php if ($message): ?><p><strong><?= h($message) ?></strong></p><?php endif; ?>
<?php if ($result && $result['errors']): ?><ul><?php foreach ($result['errors'] as $e): ?><li><?= h($e) ?></li><?php endforeach; ?></ul><?php endif; ?>

<?php if ($plan): ?>
  <p><?= count($plan['invoices']) ?> invoices, <?= count($plan['expenses']) ?> receipts and <?= count($plan['bank'] ?? []) ?> bank lines would move to a different account.
     Payments and manual entries are not touched. Corrections are reversing entries — nothing is deleted.</p>

  <h3>Net change per account</h3>
  <table border="1" cellpadding="6" style="border-collapse: collapse;">
    <tr><th>Account</th><th>Moving out</th><th>Moving in</th><th>Net</th></tr>
    <?php foreach ($plan['by_account'] as $code => $a): ?>
      <tr><td><?= h($code . ' ' . $a['name']) ?></td><td align="right"><?= h($money($a['out'])) ?></td>
          <td align="right"><?= h($money($a['in'])) ?></td><td align="right"><b><?= h($money($a['net'])) ?></b></td></tr>
    <?php endforeach; ?>
  </table>

  <?php if ($plan['unmapped']): ?>
    <h3>Income with no account yet (stays on 4900 — Penny will ask you)</h3>
    <ul><?php foreach ($plan['unmapped'] as $u): ?>
      <li><?= h($u['service_type']) ?>: <?= (int)$u['invoices'] ?> invoices, <?= h($money($u['amount'])) ?></li>
    <?php endforeach; ?></ul>
  <?php endif; ?>

  <h3>Examples</h3>
  <ul>
    <?php foreach (array_slice($plan['invoices'], 0, 8) as $m): ?>
      <li>Invoice #<?= (int)$m['id'] ?>: <?= h(json_encode($m['from'])) ?> → <?= h(json_encode($m['to'])) ?></li>
    <?php endforeach; ?>
    <?php foreach (array_slice($plan['expenses'], 0, 8) as $m): ?>
      <li>Receipt #<?= (int)$m['id'] ?> (<?= h((string)$m['category']) ?>): <?= h(json_encode($m['from'])) ?> → <?= h(json_encode($m['to'])) ?></li>
    <?php endforeach; ?>
  </ul>

  <?php if ($plan['invoices'] || $plan['expenses'] || !empty($plan['bank'])): ?>
    <form method="post">
      <input type="hidden" name="csrf_token" value="<?= h($token) ?>">
      <label>Type <?= h(CONFIRM_PHRASE) ?> to re-post: <input name="confirm" autocomplete="off"></label>
      <button type="submit">Re-post</button>
    </form>
  <?php else: ?>
    <p>Everything is already on the right accounts.</p>
  <?php endif; ?>
<?php endif; ?>
</body></html>

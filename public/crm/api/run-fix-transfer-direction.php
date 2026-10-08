<?php
/**
 * Fix bank deposits the journal posted as money OUT (2026-10-07).
 *
 * GET  (admin)            dry run: every live bank_import entry for a deposit that is posted
 *                         backwards or doubles its invoice payment's cash, with totals and the
 *                         locked-month lines that will be left alone. Changes nothing.
 *                         ?format=json for the same as JSON.
 * POST (owner, CSRF, typed confirm FIX-TRANSFER-DIRECTION)
 *                         reverses each entry and posts what the line should post now
 *                         (nothing, for a deposit its invoices carry). Append-only; safe to run
 *                         again — a second run finds nothing.
 * Logic: BankTransferDirectionRepair. Needs migration 1131 (reversals) to apply.
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
require_once APP_ROOT . '/Modules/Accounting/Services/BankTransferDirectionRepair.php';

const CONFIRM_PHRASE = 'FIX-TRANSFER-DIRECTION';
set_time_limit(300);

$db = getDB();
$user = getCurrentUser();
$userId = (int)($user['id'] ?? 0);

/** The owner (ops_settings charlie_owner_user_id) when set; otherwise any admin. */
$isOwner = (function () use ($db, $userId): bool {
    try {
        $s = $db->prepare("SELECT setting_value FROM ops_settings WHERE setting_key = 'charlie_owner_user_id'");
        $s->execute();
        $owner = (int)$s->fetchColumn();
        return $owner > 0 ? $owner === $userId : true;
    } catch (Throwable $e) {
        return true;
    }
})();

$repair = new BankTransferDirectionRepair($db);
$message = null;
$result = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verifyCSRFToken((string)($_POST['csrf_token'] ?? ''))) {
        http_response_code(400);
        die('Bad CSRF token');
    }
    if (!$isOwner) {
        http_response_code(403);
        die('Owner only');
    }
    if (($_POST['confirm'] ?? '') !== CONFIRM_PHRASE) {
        $message = 'Type ' . CONFIRM_PHRASE . ' to confirm. Nothing was changed.';
    } else {
        $result = $repair->apply($userId);
        $message = "Corrected {$result['fixed']} entries"
                 . ($result['failed'] ? ", {$result['failed']} failed" : '')
                 . ($result['skipped_locked'] ? ", {$result['skipped_locked']} left alone in locked months" : '') . '.';
    }
}

try {
    $plan = $repair->plan();
} catch (Throwable $e) {
    $plan = null;
    $message = trim(($message ?? '') . ' Could not build the dry run: ' . $e->getMessage());
}

if (($_GET['format'] ?? '') === 'json') {
    header('Content-Type: application/json');
    header('Cache-Control: no-store');
    echo json_encode(['ok' => $plan !== null, 'message' => $message, 'result' => $result, 'plan' => $plan]);
    exit;
}

$token = generateCSRFToken();
$money = fn($v) => ($v < 0 ? '−' : '') . '$' . number_format(abs((float)$v), 2);
$kinds = ['backwards' => 'Posted as money out', 'double_cash' => 'Doubles the invoice payment\'s cash'];
?>
<!doctype html>
<html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1"><title>Fix deposit direction</title></head>
<body style="font-family: system-ui, sans-serif; max-width: 1000px; margin: 24px auto; padding: 0 16px;">
<h2>Bank deposits posted the wrong way in the journal</h2>
<?php if ($message): ?><p><strong><?= h($message) ?></strong></p><?php endif; ?>
<?php if ($result && $result['errors']): ?><ul><?php foreach ($result['errors'] as $e): ?><li><?= h($e) ?></li><?php endforeach; ?></ul><?php endif; ?>

<?php if ($plan): ?>
  <p><?= (int)$plan['count'] ?> entries, <?= h($money($plan['amount'])) ?> of deposits. Cash in the ledger changes by
     <b><?= h($money($plan['bank_effect'])) ?></b> once they are corrected.
     Each entry is reversed and the line posted again as it should be — nothing in a deposit its invoices carry
     (their payment entry already puts the cash in the bank). Nothing is deleted.</p>
  <?php foreach ($plan['by_kind'] as $k => $v): ?>
    <p><?= h($kinds[$k] ?? $k) ?>: <?= (int)$v['count'] ?> (<?= h($money($v['amount'])) ?>)</p>
  <?php endforeach; ?>

  <?php if ($plan['locked']): ?>
    <h3>In locked months — left alone (<?= (int)$plan['locked_count'] ?>, <?= h($money($plan['locked_amount'])) ?>)</h3>
    <ul><?php foreach ($plan['locked'] as $i): ?>
      <li>#<?= (int)$i['id'] ?> <?= h($i['date']) ?> <?= h($money($i['amount'])) ?> — <?= h($i['description']) ?></li>
    <?php endforeach; ?></ul>
  <?php endif; ?>

  <?php if ($plan['items']): ?>
    <table border="1" cellpadding="6" style="border-collapse: collapse; width: 100%;">
      <tr><th>Bank line</th><th>Date</th><th>Amount</th><th>Description</th><th>Problem</th><th>Bank now</th><th>Bank after</th><th>Entry</th></tr>
      <?php foreach ($plan['items'] as $i): ?>
        <tr><td>#<?= (int)$i['id'] ?></td><td><?= h($i['date']) ?></td><td align="right"><?= h($money($i['amount'])) ?></td>
            <td><?= h($i['description']) ?></td><td><?= h($kinds[$i['kind']] ?? $i['kind']) ?><?= $i['settled'] ? ' (carried by invoices)' : '' ?></td>
            <td align="right"><?= h($money($i['posted_bank'])) ?></td><td align="right"><?= h($money($i['want_bank'])) ?></td>
            <td>#<?= (int)$i['entry_id'] ?></td></tr>
      <?php endforeach; ?>
    </table>
    <?php if ($isOwner): ?>
      <form method="post" style="margin-top: 16px;">
        <input type="hidden" name="csrf_token" value="<?= h($token) ?>">
        <label>Type <?= h(CONFIRM_PHRASE) ?> to correct these entries: <input name="confirm" autocomplete="off"></label>
        <button type="submit">Correct</button>
      </form>
    <?php else: ?>
      <p>Only the owner can apply this.</p>
    <?php endif; ?>
  <?php else: ?>
    <p>Every deposit is posted the right way.</p>
  <?php endif; ?>
<?php endif; ?>
</body></html>

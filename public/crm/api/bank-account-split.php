<?php
/**
 * Move the Vancity savings accounts' lines out of 1010 Chequing (2026-10-07).
 *
 * GET  (admin)  dry run: every separate running-balance chain inside 1010 (dates, balance range,
 *               lines, the guess and why), each line, the chequing-side twin of every
 *               chequing ↔ savings transfer, and the chequing chain before / after. Changes
 *               nothing. ?format=json for the same as JSON.
 * POST (owner, CSRF, typed confirm)
 *   action=apply  pick[<chain key>] = 1020 | 1025 | keep — only picked chains move. Each line:
 *                 bank account → the pick, its journal entry reversed and posted again
 *                 (append-only); its chequing twin, if any, becomes the transfer. Locked months
 *                 are skipped. Logged for undo.
 *   action=undo   batch=<undo key> — puts a batch back the same way.
 * Logic: BankAccountSplitService. Needs migrations 1131 (reversals) and 1234.
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
require_once APP_ROOT . '/Modules/Accounting/Services/BankAccountSplitService.php';
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

$svc = new BankAccountSplitService($db);
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
    $action = (string)($_POST['action'] ?? '');
    if ($action === 'apply') {
        if (($_POST['confirm'] ?? '') !== BankAccountSplitService::CONFIRM_PHRASE) {
            $message = 'Type ' . BankAccountSplitService::CONFIRM_PHRASE . ' to confirm. Nothing was changed.';
        } else {
            $picks = is_array($_POST['pick'] ?? null) ? array_map('strval', $_POST['pick']) : [];
            $result = $svc->apply($picks, $userId);
            $message = $result['message'];
        }
    } elseif ($action === 'undo') {
        if (($_POST['confirm'] ?? '') !== BankAccountSplitService::UNDO_PHRASE) {
            $message = 'Type ' . BankAccountSplitService::UNDO_PHRASE . ' to undo. Nothing was changed.';
        } else {
            $result = $svc->undo(trim((string)($_POST['batch'] ?? '')), $userId);
            $message = $result['message'];
        }
    }
}

try {
    $plan = $svc->plan();
    $batches = $svc->batches();
} catch (Throwable $e) {
    $plan = null;
    $batches = [];
    $message = trim(($message ?? '') . ' Could not build the dry run: ' . $e->getMessage());
}

if (($_GET['format'] ?? '') === 'json') {
    header('Content-Type: application/json');
    header('Cache-Control: no-store');
    echo json_encode(['ok' => $plan !== null, 'message' => $message, 'result' => $result, 'plan' => $plan, 'batches' => $batches]);
    exit;
}

$token = generateCSRFToken();
$money = fn($v) => $v === null ? '—' : (($v < 0 ? '−' : '') . '$' . number_format(abs((float)$v), 2));
$flagText = [
    'sign'    => 'the CRM has it the other way round',
    'self'    => 'category is the savings account itself',
    'revenue' => 'booked as revenue',
    'matched' => 'tied to an invoice / receipt (its cash posts from there)',
];
$confText = ['statement' => 'the statement names the account', 'high' => 'one unbroken chain, printed alongside chequing',
             'medium' => 'pieces joined by balance size', 'low' => 'a single line'];
$breakList = function (array $breaks) use ($money): string {
    if (!$breaks) return 'none';
    $out = [];
    foreach ($breaks as $b) $out[] = h($b['from'] . ' → ' . $b['to'] . ': ' . $money($b['before']) . ' → ' . $money($b['after']) . ' (' . $money($b['missing']) . ' missing)');
    return implode('<br>', $out);
};
?>
<!doctype html>
<html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1"><title>Savings lines in chequing</title></head>
<body style="font-family: system-ui, sans-serif; max-width: 1100px; margin: 24px auto; padding: 0 16px;">
<h2>Savings lines sitting in 1010 Chequing</h2>
<p>Vancity prints three accounts on one statement: chequing ••6801, Reserve funds ••6819 and GST Reserves ••6827.
   Lines that follow a running balance of their own are another account's. Pick where each chain goes — nothing moves without your pick.</p>
<?php if ($message): ?><p><strong><?= h($message) ?></strong></p><?php endif; ?>
<?php if ($result && !empty($result['errors'])): ?><ul><?php foreach ($result['errors'] as $e): ?><li><?= h($e) ?></li><?php endforeach; ?></ul><?php endif; ?>

<?php if ($plan): ?>
  <?php foreach ($plan['problems'] as $p): ?><p style="color: #b00;"><?= h($p) ?></p><?php endforeach; ?>
  <p><?= (int)$plan['lines'] ?> statement lines on chequing (<?= (int)$plan['loose'] ?> without a balance — they stay).
     Chequing chain now: <?= count($plan['before']['breaks']) ?> breaks, <?= count($plan['before']['other_account']) ?> other-account runs.
     Without the chains below: <b><?= count($plan['after']['breaks']) ?> breaks</b>
     <?= $plan['after']['end_to_end'] ? '— links end to end.' : '' ?></p>
  <details><summary>Chequing breaks after the move</summary><p><?= $breakList($plan['after']['breaks']) ?></p></details>

  <?php if (!$plan['chains']): ?>
    <p>No other balance chain in chequing — nothing to move.</p>
  <?php else: ?>
  <form method="post">
    <input type="hidden" name="csrf_token" value="<?= h($token) ?>">
    <input type="hidden" name="action" value="apply">
    <?php foreach ($plan['chains'] as $c): ?>
      <fieldset style="margin: 16px 0; padding: 12px; border: 1px solid #ccc;">
        <legend><b>Chain <?= h($c['key']) ?></b> — <?= (int)$c['count'] ?> lines, <?= h($c['from']) ?> → <?= h($c['to']) ?>,
          balance <?= h($money($c['min_balance'])) ?> – <?= h($money($c['max_balance'])) ?></legend>
        <p>Opens <?= h($money($c['opening'])) ?>, closes <?= h($money($c['closing'])) ?>; <?= (int)$c['segments'] ?> piece(s);
           confidence: <?= h($confText[$c['confidence']] ?? $c['confidence']) ?>.
           <?= (int)$c['movable'] ?> can move (<?= h($money($c['movable_amount'])) ?>)<?= $c['locked'] ? ', ' . (int)$c['locked'] . ' in locked months stay' : '' ?>.</p>
        <p>Guess: <b><?= h(BankAccountSplitService::TARGETS[$c['guess']]['label'] ?? '—') ?></b> — <?= h($c['guess_reason']) ?></p>
        <?php if ($c['joins']): ?><p>Joined across: <?= $breakList(array_map(fn($j) => $j + ['missing' => $j['after'] - $j['before']], $c['joins'])) ?></p><?php endif; ?>
        <p>
          <?php foreach (BankAccountSplitService::TARGETS as $code => $t): ?>
            <label style="margin-right: 16px;"><input type="radio" name="pick[<?= h($c['key']) ?>]" value="<?= h($code) ?>"
              <?= $c['confidence'] === 'statement' && $c['guess'] === (string)$code ? 'checked' : '' ?>>
              <?= h($code . ' ' . ($plan['targets'][$code]['name'] ?? $t['name'])) ?></label>
          <?php endforeach; ?>
          <label><input type="radio" name="pick[<?= h($c['key']) ?>]" value="keep"> leave in chequing</label>
        </p>
        <details><summary>Lines</summary>
          <table border="1" cellpadding="4" style="border-collapse: collapse; width: 100%; font-size: 13px;">
            <tr><th>Date</th><th>In/out</th><th>Amount</th><th>Balance</th><th>Description</th><th>Category</th><th>Chequing twin</th><th>Notes</th></tr>
            <?php foreach ($c['items'] as $i): ?>
              <tr<?= $i['movable'] ? '' : ' style="color:#888"' ?>><td><?= h($i['date']) ?></td>
                <td><?= $i['dir'] === 1 ? 'in' : ($i['dir'] === -1 ? 'out' : '?') ?></td>
                <td align="right"><?= h($money($i['amount'])) ?></td><td align="right"><?= h($money($i['balance'])) ?></td>
                <td><?= h($i['description']) ?></td><td><?= h($i['category']) ?></td>
                <td><?= $i['mirror'] ? '#' . (int)$i['mirror']['tx_id'] . ' ' . h($i['mirror']['description']) . ' → one transfer' : '' ?></td>
                <td><?= h(implode('; ', array_filter(array_merge([$i['why_not']], array_map(fn($f) => $flagText[$f] ?? $f, $i['flags']))))) ?></td></tr>
            <?php endforeach; ?>
          </table>
        </details>
      </fieldset>
    <?php endforeach; ?>
    <?php if ($isOwner && $plan['ready']): ?>
      <label>Type <?= h(BankAccountSplitService::CONFIRM_PHRASE) ?> to move the picked chains: <input name="confirm" autocomplete="off"></label>
      <button type="submit">Move</button>
    <?php elseif (!$isOwner): ?>
      <p>Only the owner can apply this.</p>
    <?php endif; ?>
  </form>
  <?php endif; ?>
<?php endif; ?>

<?php if ($batches): ?>
  <h3>Moves so far</h3>
  <table border="1" cellpadding="4" style="border-collapse: collapse;">
    <tr><th>Undo key</th><th>When</th><th>Lines</th><th>Transfers paired</th><th>To</th><th>Undone</th></tr>
    <?php foreach ($batches as $b): ?>
      <tr><td><?= h($b['batch_id']) ?></td><td><?= h($b['moved_at']) ?></td><td><?= (int)$b['moved'] ?></td><td><?= (int)$b['transfers'] ?></td>
          <td><?= h((string)$b['to_codes']) ?></td><td><?= (int)$b['undone'] ?></td></tr>
    <?php endforeach; ?>
  </table>
  <?php if ($isOwner): ?>
    <form method="post" style="margin-top: 12px;">
      <input type="hidden" name="csrf_token" value="<?= h($token) ?>">
      <input type="hidden" name="action" value="undo">
      <label>Undo key <input name="batch" autocomplete="off"></label>
      <label>Type <?= h(BankAccountSplitService::UNDO_PHRASE) ?> <input name="confirm" autocomplete="off"></label>
      <button type="submit">Undo</button>
    </form>
  <?php endif; ?>
<?php endif; ?>
</body></html>

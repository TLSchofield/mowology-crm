<?php
/**
 * Take out bank lines that overlapping statement imports added more than once.
 *
 * Needs migration 1133. GET shows the groups (which line is kept, which copies go) and
 * changes nothing. POST (admin, CSRF, typed confirm REMOVE-DUPLICATES) snapshots each
 * copy, reverses its journal entry, marks its statement row duplicate and takes it out
 * of the live list. Copies a payment or receipt depends on are left for a human.
 * Logic: BankDuplicateCleanup.
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
require_once APP_ROOT . '/Modules/Accounting/Services/BankDuplicateCleanup.php';

const CONFIRM_PHRASE = 'REMOVE-DUPLICATES';
set_time_limit(300);

$db = getDB();
$svc = new BankDuplicateCleanup($db);
$message = null; $errors = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verifyCSRFToken((string)($_POST['csrf_token'] ?? ''))) { http_response_code(400); die('Bad CSRF token'); }
    if (($_POST['confirm'] ?? '') !== CONFIRM_PHRASE) {
        $message = 'Type ' . CONFIRM_PHRASE . ' to confirm. Nothing was changed.';
    } else {
        $r = $svc->apply((int)(getCurrentUser()['id'] ?? 0));
        $message = "Took out {$r['removed']} duplicate lines" . ($r['failed'] ? ", {$r['failed']} failed" : '') . '. Each is snapshotted in bank_duplicate_removals; journal entries were reversed.';
        $errors = $r['errors'];
    }
}
$plan = $svc->plan();
$token = generateCSRFToken();
$money = fn($v) => '$' . number_format((float)$v, 2);
?>
<!doctype html>
<html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1"><title>Duplicate bank lines</title></head>
<body style="font-family: system-ui, sans-serif; max-width: 1000px; margin: 24px auto; padding: 0 16px;">
<h2>Bank lines imported more than once</h2>
<?php if ($message): ?><p><strong><?= h($message) ?></strong></p><?php endif; ?>
<?php if ($errors): ?><ul><?php foreach ($errors as $e): ?><li><?= h($e) ?></li><?php endforeach; ?></ul><?php endif; ?>
<?php if (!$svc->ready()): ?><p><b>Run migration 1133 first.</b></p><?php endif; ?>
<p><b><?= (int)$plan['extras'] ?></b> extra copies (<?= h($money($plan['amount'])) ?>) in <?= count($plan['groups']) ?> groups.
   <?= count($plan['skipped']) ?> group(s) left alone because a payment or receipt depends on more than one copy.</p>
<?php if ($plan['groups']): ?>
<table border="1" cellpadding="5" style="border-collapse: collapse; font-size: 14px;">
  <tr><th>Date</th><th>Line</th><th>Amount</th><th>Keep</th><th>Take out</th></tr>
  <?php foreach (array_slice($plan['groups'], 0, 120) as $g): $k = $g['keep'][0]; ?>
    <tr><td><?= h($k['transaction_date']) ?></td><td><?= h(mb_substr((string)$k['description'], 0, 60)) ?></td><td align="right"><?= h($money($k['amount'])) ?></td>
        <td><?= implode(', ', array_map(fn($x) => '#' . (int)$x['id'] . ' (import ' . (int)$x['import_session_id'] . ')', $g['keep'])) ?></td>
        <td><?= implode(', ', array_map(fn($x) => '#' . (int)$x['id'] . ' (import ' . (int)$x['import_session_id'] . ')', $g['remove'])) ?></td></tr>
  <?php endforeach; ?>
</table>
<?php if ($svc->ready()): ?>
<form method="post" style="margin-top: 16px;">
  <input type="hidden" name="csrf_token" value="<?= h($token) ?>">
  <label>Type <?= h(CONFIRM_PHRASE) ?> to take them out: <input name="confirm" autocomplete="off"></label>
  <button type="submit">Take out duplicates</button>
</form>
<?php endif; ?>
<?php else: ?><p>No duplicates left.</p><?php endif; ?>
</body></html>

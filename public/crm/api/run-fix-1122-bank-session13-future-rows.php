<?php
/**
 * Data fix 1122: remove bank-import session 13 (122 rows dated Dec 2026).
 *
 * Session 13 (2026-04-29) imported the December-2025 Vancity statement with the
 * year inferred as the import year, so every row landed in Dec 2026 — the future.
 * The same statement was re-imported correctly on 2026-06-20 as session 23, whose
 * running balances chain unbroken 6,567.50 → 2,679.92. So:
 *   - 119 rows have an exact twin in session 23 (same day, amount, type, text) —
 *     shifting them back a year would double-count December 2025;
 *   - 3 rows (MOBILE DEPOSIT 3,981.38, ETRANSFER CREDIT GALLENTINE 1,336.13,
 *     INTEREST 8.72) chain to their own balance (6,984.53 → 8,320.66 → 8,329.38):
 *     a second account on the same statement, not bank account 2.
 * The fix is therefore to roll the whole session back, plus its journal entries
 * (BankImportService::rollback() does not touch the journal).
 *
 * GET  → dry-run report, changes nothing.
 * POST (admin, CSRF, confirm=REMOVE-SESSION-13) → deletes, in one transaction,
 *      only if every guard passes. Idempotent: a second run finds nothing to do.
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
require_once APP_ROOT . '/Modules/Accounting/Services/BankImportService.php';

const FIX_SESSION   = 13;
const TWIN_SESSION  = 23;
const BANK_ACCOUNT  = 2;
const EXPECT_ROWS   = 122;
const EXPECT_TWINS  = 119;
const CONFIRM_PHRASE = 'REMOVE-SESSION-13';

$db = getDB();

/** Everything the decision rests on, re-read from the live tables. */
function inspect(PDO $db): array
{
    $r = ['errors' => []];

    $s = $db->prepare("SELECT id, status, bank_account_id, created_at, date_from, date_to FROM bank_import_sessions WHERE id = ?");
    $s->execute([FIX_SESSION]);
    $r['session'] = $s->fetch(PDO::FETCH_ASSOC) ?: null;
    $s->execute([TWIN_SESSION]);
    $r['twin_session'] = $s->fetch(PDO::FETCH_ASSOC) ?: null;

    $tx = $db->prepare("
        SELECT id, transaction_date, type, amount, description, bank_account_id, account_id,
               matched_invoice_id, matched_expense_id, created_at
        FROM accounting_transactions
        WHERE import_session_id = ? AND reference_type = 'bank_import'
        ORDER BY id
    ");
    $tx->execute([FIX_SESSION]);
    $rows = $tx->fetchAll(PDO::FETCH_ASSOC);
    $r['rows'] = $rows;
    $ids = array_map('intval', array_column($rows, 'id'));
    $r['ids'] = $ids;

    if (!$rows) {
        $r['done'] = true;
        return $r;
    }
    $r['done'] = false;

    if (!$r['session'] || $r['session']['status'] !== 'imported') $r['errors'][] = 'Session 13 is missing or not in status "imported".';
    if (!$r['twin_session'] || $r['twin_session']['status'] !== 'imported') $r['errors'][] = 'Session 23 (the correct December 2025 import) is missing or not "imported".';
    if (count($rows) !== EXPECT_ROWS) $r['errors'][] = sprintf('Expected %d rows in session 13, found %d.', EXPECT_ROWS, count($rows));
    foreach ($rows as $t) {
        if (substr($t['transaction_date'], 0, 7) !== '2026-12' || (int)$t['bank_account_id'] !== BANK_ACCOUNT
            || substr((string)$t['created_at'], 0, 10) !== '2026-04-29') {
            $r['errors'][] = "Row #{$t['id']} is not a Dec-2026 / bank-account-2 / 2026-04-29 row.";
        }
        if ($t['matched_invoice_id'] || $t['matched_expense_id']) $r['errors'][] = "Row #{$t['id']} is matched to an invoice/expense.";
    }

    // Twins in session 23: same day of December 2025, amount, type and (space-free) text.
    $twin = $db->prepare("
        SELECT at.id FROM accounting_transactions at
        WHERE at.import_session_id = ? AND at.bank_account_id = ?
          AND at.transaction_date = ? AND at.amount = ? AND at.type = ?
          AND REPLACE(UPPER(at.description), ' ', '') = REPLACE(UPPER(?), ' ', '')
    ");
    $used = []; $twins = 0; $orphans = [];
    foreach ($rows as $t) {
        $twin->execute([TWIN_SESSION, BANK_ACCOUNT, '2025' . substr($t['transaction_date'], 4), $t['amount'], $t['type'], $t['description']]);
        $match = null;
        foreach ($twin->fetchAll(PDO::FETCH_COLUMN) as $cand) {
            if (!isset($used[$cand])) { $match = (int)$cand; break; }
        }
        if ($match !== null) { $used[$match] = true; $twins++; } else { $orphans[] = $t; }
    }
    $r['twins'] = $twins;
    $r['orphans'] = $orphans;
    if ($twins !== EXPECT_TWINS) $r['errors'][] = sprintf('Expected %d twins in session 23, found %d.', EXPECT_TWINS, $twins);

    $in = implode(',', array_fill(0, count($ids), '?'));
    $count = function (string $sql) use ($db, $ids): int {
        $q = $db->prepare($sql);
        $q->execute($ids);
        return (int)$q->fetchColumn();
    };
    $r['refs'] = [
        'invoice_payment_allocations' => $count("SELECT COUNT(*) FROM invoice_payment_allocations WHERE transaction_id IN ($in)"),
        'etransfer_notifications'     => tableExists($db, 'etransfer_notifications')
            ? $count("SELECT COUNT(*) FROM etransfer_notifications WHERE bank_transaction_id IN ($in)") : 0,
        'other_sessions_duplicate_of' => $count("SELECT COUNT(*) FROM bank_import_rows WHERE session_id <> " . FIX_SESSION . " AND duplicate_of_id IN ($in)"),
    ];
    foreach ($r['refs'] as $table => $n) {
        if ($n > 0) $r['errors'][] = "$n row(s) in $table still reference session 13 — resolve those first.";
    }
    $r['journal_entries'] = $count("SELECT COUNT(*) FROM journal_entries WHERE source_type = 'bank_import' AND source_id IN ($in)");

    $p = $db->query("SELECT status FROM accounting_periods WHERE year = 2026 AND month = 12")->fetchColumn();
    $r['period_2026_12'] = $p === false ? 'none' : $p;
    if ($p === 'locked') $r['errors'][] = 'Accounting period 2026-12 is locked.';

    $r['totals'] = ['income' => 0.0, 'expense' => 0.0];
    foreach ($rows as $t) $r['totals'][$t['type'] === 'income' ? 'income' : 'expense'] += (float)$t['amount'];

    return $r;
}

function tableExists(PDO $db, string $t): bool
{
    $q = $db->prepare("SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = ?");
    $q->execute([$t]);
    return (bool)$q->fetchColumn();
}

$message = null;
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verifyCSRFToken((string)($_POST['csrf_token'] ?? ''))) {
        http_response_code(403);
        die('Bad CSRF token');
    }
    $pre = inspect($db);
    if ($pre['done']) {
        $message = 'Nothing to do — session 13 has no rows left.';
    } elseif (($_POST['confirm'] ?? '') !== CONFIRM_PHRASE) {
        $message = 'Not run: type the confirmation phrase exactly.';
    } elseif ($pre['errors']) {
        $message = 'Not run: guards failed (see below).';
    } else {
        $user = getCurrentUser();
        $db->beginTransaction();
        try {
            $in = implode(',', array_fill(0, count($pre['ids']), '?'));
            $je = $db->prepare("DELETE FROM journal_entries WHERE source_type = 'bank_import' AND source_id IN ($in)");
            $je->execute($pre['ids']);   // journal_lines cascade
            $deleted = (new BankImportService($db))->rollback(FIX_SESSION);
            $db->prepare("UPDATE bank_import_sessions SET notes = CONCAT(COALESCE(notes, ''), ?) WHERE id = ?")->execute([
                sprintf(' [fix-1122 %s by user %d: Dec-2025 statement mis-dated 2026; superseded by session 23]', date('Y-m-d H:i'), (int)($user['id'] ?? 0)),
                FIX_SESSION,
            ]);
            $db->commit();
            $message = sprintf('Done: %d transactions and %d journal entries removed; session 13 marked rolled_back.', $deleted, $je->rowCount());
            error_log('fix-1122: ' . $message);
        } catch (Throwable $e) {
            if ($db->inTransaction()) $db->rollBack();
            error_log('fix-1122 failed: ' . $e->getMessage());
            $message = 'Failed, nothing changed: ' . $e->getMessage();
        }
    }
}

$r = inspect($db);
$token = generateCSRFToken();
?>
<!DOCTYPE html>
<html lang="en"><head><meta charset="utf-8"><title>Fix 1122 — bank session 13</title></head>
<body>
<h2>Fix 1122 — remove mis-dated bank-import session 13</h2>
<?php if ($message): ?><p><strong><?= h($message) ?></strong></p><?php endif; ?>

<?php if ($r['done']): ?>
  <p>Session 13 has no transactions left. Nothing to do.</p>
<?php else: ?>
  <ul>
    <li>Session 13 rows: <?= count($r['rows']) ?> (income <?= number_format($r['totals']['income'], 2) ?>, expense <?= number_format($r['totals']['expense'], 2) ?>)</li>
    <li>Exact twins already in session 23 (Dec 2025): <?= (int)$r['twins'] ?></li>
    <li>Journal entries to remove: <?= (int)$r['journal_entries'] ?></li>
    <li>References: <?= h(json_encode($r['refs'])) ?></li>
    <li>Period 2026-12: <?= h((string)$r['period_2026_12']) ?></li>
  </ul>
  <h3>Rows with no twin (second account on the statement — re-enter by hand if they are business money)</h3>
  <ul>
  <?php foreach ($r['orphans'] as $o): ?>
    <li>#<?= (int)$o['id'] ?> <?= h($o['transaction_date']) ?> <?= h($o['type']) ?> <?= h($o['amount']) ?> — <?= h($o['description']) ?></li>
  <?php endforeach; ?>
  </ul>
  <?php if ($r['errors']): ?>
    <h3>Guards failed — will not run</h3>
    <ul><?php foreach ($r['errors'] as $e): ?><li><?= h($e) ?></li><?php endforeach; ?></ul>
  <?php else: ?>
    <form method="post">
      <input type="hidden" name="csrf_token" value="<?= h($token) ?>">
      <label>Type <code><?= CONFIRM_PHRASE ?></code> to remove session 13:
        <input name="confirm" autocomplete="off"></label>
      <button type="submit">Remove session 13</button>
    </form>
  <?php endif; ?>
<?php endif; ?>
</body></html>

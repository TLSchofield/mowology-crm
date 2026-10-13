<?php
/**
 * Ledger → Exceptions: every journal change touching a locked period after its lock
 * (LockExceptionService). ?export=csv downloads it.
 */
define('MW_LEDGER_PAGE', true);
require __DIR__ . '/_ledger.php';

$db  = getDB();
$rep = (new LockExceptionService($db))->report();
$labels = [
    'inside'   => 'Posted inside a locked month',
    'late'     => 'Late entry for a locked month (moved to the current month)',
    'reversed' => 'Locked-month entry reversed after the lock',
];

if (($_GET['export'] ?? '') === 'csv') {
    $rows = [['Entry', 'Kind', 'Locked period', 'Locked at', 'Entry date', 'Made at', 'By', 'Proposed by', 'Amount', 'Memo (why)', 'Original entry date']];
    foreach ($rep['rows'] as $r) {
        $rows[] = [(int)$r['id'], $labels[$r['kind']], $r['period'], $r['locked_at'], $r['entry_date'], $r['created_at'],
                   $r['created_by_name'], $r['proposed_by'], (float)$r['amount'], $r['memo'], $r['orig_date'] ?? null];
    }
    $csv = LedgerExport::csv(array_merge(
        LedgerExport::headerRows(LedgerExport::entity($db), 'Locked-period exception report', 'All locked periods',
                                 count($rep['locked']) . ' locked month(s)', date('Y-m-d H:i:s T')), $rows));
    LedgerExport::send('locked-period-exceptions-' . date('Y-m-d') . '.csv', $csv, 'exceptions.csv');
}

$lockedList = array_keys($rep['locked']);
sort($lockedList);
$pageTitle = 'Exceptions — Ledger';
?>
<?php include dirname(__DIR__) . '/includes/appstack_head.php'; ?>

<div class="mw-ledger">
  <?php mwLedgerNav('exceptions', userHasPermission('users.manage')); ?>

  <header class="mw-ledger-head">
    <div>
      <h1 class="mw-ledger-title">Locked-period exceptions</h1>
      <p class="mw-ledger-sub"><?= count($rep['rows']) ?> change<?= count($rep['rows']) === 1 ? '' : 's' ?> after a lock ·
        <?= count($lockedList) ?> locked month<?= count($lockedList) === 1 ? '' : 's' ?><?= $lockedList ? ' (' . h(reset($lockedList)) . ' – ' . h(end($lockedList)) . ')' : '' ?></p>
    </div>
    <a class="btn btn-sm btn-outline-secondary" href="?export=csv">Export CSV</a>
  </header>

  <p class="mw-ledger-explain">A locked month never changes: a late automatic posting lands in the current month, a manual one is refused,
    and a correction is a reversal dated today. This list shows every one of those events, who made it and the reason it carries.</p>

  <div class="mw-ledger-tablewrap">
    <table class="mw-ledger-table" data-mw-keytable data-mw-detail-url="/crm/ledger/entry.php?id=">
      <thead><tr>
        <th scope="col">Made</th><th scope="col">Entry</th><th scope="col">What happened</th><th scope="col">Period</th>
        <th scope="col" class="mw-ledger-hide-sm">Locked</th><th scope="col">By</th><th scope="col" class="mw-num">Amount</th><th scope="col" class="mw-ledger-memo">Why (memo)</th>
      </tr></thead>
      <tbody>
      <?php if (!$rep['rows']): ?>
        <tr><td colspan="8" class="mw-ledger-empty">Nothing has changed inside a locked period since it was locked.</td></tr>
      <?php endif; ?>
      <?php foreach ($rep['rows'] as $r): ?>
        <tr tabindex="0" data-id="<?= (int)$r['id'] ?>" class="mw-ledger-row">
          <td class="mw-nowrap"><?= h((string)$r['created_at']) ?></td>
          <td class="mw-code">#<?= (int)$r['id'] ?></td>
          <td><span class="mw-ledger-status mw-ledger-status--<?= $r['kind'] === 'inside' ? 'unmapped' : 'suggested' ?>"><?= h($labels[$r['kind']]) ?></span></td>
          <td class="mw-nowrap"><?= h($r['period']) ?></td>
          <td class="mw-nowrap mw-ledger-hide-sm"><?= h((string)$r['locked_at']) ?></td>
          <td class="mw-nowrap"><?= h($r['created_by_name'] ?: ($r['proposed_by'] ?: 'system')) ?></td>
          <td class="mw-num"><?= mwLedgerMoney($r['amount']) ?></td>
          <td class="mw-ledger-memo"><?= h((string)$r['memo']) ?></td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
  </div>
</div>

<script src="/crm/js/mw-ledger.js?v=20261013b" defer></script>
<?php include dirname(__DIR__) . '/includes/appstack_footer.php'; ?>

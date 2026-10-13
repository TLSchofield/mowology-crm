<?php
/**
 * Ledger → Overview: the accountant's landing page. Trial balance status, GIFI readiness, exceptions,
 * the journal's latest entries.
 */
define('MW_LEDGER_PAGE', true);
require __DIR__ . '/_ledger.php';
require_once APP_ROOT . '/Modules/Accounting/Services/ReportingService.php';

$db   = getDB();
$year = (int)date('Y');
$tb   = (new ReportingService($db))->getTrialBalance(date('Y-m-d'));
$gifi = (new GifiService($db))->report($year);
$exc  = (new LockExceptionService($db))->report();
$jr   = new JournalBrowserService($db);
$latest = $jr->search(JournalBrowserService::normalizeFilters(['per' => 50]));

$pageTitle = 'Ledger';
?>
<?php include dirname(__DIR__) . '/includes/appstack_head.php'; ?>

<div class="mw-ledger">
  <?php mwLedgerNav('overview', userHasPermission('users.manage')); ?>

  <header class="mw-ledger-head">
    <div>
      <h1 class="mw-ledger-title"><?= h(LedgerExport::entity($db)) ?> — the books</h1>
      <p class="mw-ledger-sub">Fiscal year ends December 31. FY<?= $year - 1 ?> is filed and locked; FY<?= $year ?> is open.
        Everything here is read-only<?= $ledgerIsAccountant ? ', and each page you open is recorded' : '' ?>.</p>
    </div>
  </header>

  <div class="mw-ledger-stats">
    <a class="mw-ledger-stat" href="/crm/accounting/trial-balance.php">
      <span class="mw-ledger-stat-label">Trial balance today</span>
      <span class="mw-ledger-stat-value mw-num"><?= mwLedgerMoney($tb['total_debit']) ?></span>
      <span class="mw-ledger-stat-note <?= $tb['in_balance'] ? 'is-ok' : 'is-bad' ?>"><?= $tb['in_balance'] ? 'Debits equal credits' : 'OUT OF BALANCE' ?></span>
    </a>
    <a class="mw-ledger-stat" href="/crm/ledger/journal.php">
      <span class="mw-ledger-stat-label">Journal entries</span>
      <span class="mw-ledger-stat-value mw-num"><?= number_format((int)$db->query("SELECT COUNT(*) FROM journal_entries")->fetchColumn()) ?></span>
      <span class="mw-ledger-stat-note">every one traced to its source</span>
    </a>
    <a class="mw-ledger-stat" href="/crm/ledger/gifi.php">
      <span class="mw-ledger-stat-label">GIFI FY<?= $year ?></span>
      <span class="mw-ledger-stat-value"><?= $gifi['ok'] ? 'Ready' : count($gifi['problems']) . ' to resolve' ?></span>
      <span class="mw-ledger-stat-note <?= $gifi['ok'] ? 'is-ok' : 'is-warn' ?>"><?= $gifi['ok'] ? 'validates' : 'export blocked until mapped and confirmed' ?></span>
    </a>
    <a class="mw-ledger-stat" href="/crm/ledger/exceptions.php">
      <span class="mw-ledger-stat-label">Changes after a lock</span>
      <span class="mw-ledger-stat-value mw-num"><?= count($exc['rows']) ?></span>
      <span class="mw-ledger-stat-note"><?= count($exc['locked']) ?> locked months</span>
    </a>
  </div>

  <h2 class="mw-ledger-h2">Latest entries</h2>
  <div class="mw-ledger-tablewrap">
    <table class="mw-ledger-table" data-mw-keytable data-mw-detail-url="/crm/ledger/entry.php?id=">
      <thead><tr><th scope="col">Date</th><th scope="col">Entry</th><th scope="col">Kind</th><th scope="col" class="mw-ledger-memo">Memo</th><th scope="col" class="mw-num">Amount</th></tr></thead>
      <tbody>
      <?php foreach (array_slice($latest['rows'], 0, 15) as $r): ?>
        <tr tabindex="0" data-id="<?= (int)$r['id'] ?>" class="mw-ledger-row">
          <td class="mw-nowrap"><?= h($r['entry_date']) ?></td>
          <td class="mw-code">#<?= (int)$r['id'] ?></td>
          <td><span class="mw-ledger-kind mw-ledger-kind--<?= h(strtolower($r['display_kind'])) ?>"><?= h($r['display_kind']) ?></span></td>
          <td class="mw-ledger-memo"><?= h((string)$r['memo']) ?></td>
          <td class="mw-num"><?= mwLedgerMoney($r['amount']) ?></td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
  </div>
</div>

<script src="/crm/js/mw-ledger.js?v=20261013a" defer></script>
<?php include dirname(__DIR__) . '/includes/appstack_footer.php'; ?>

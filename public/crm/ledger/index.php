<?php
/**
 * Ledger → Overview: the accountant's landing page. Trial balance status, GIFI readiness, exceptions,
 * the journal's latest entries.
 */
define('MW_LEDGER_PAGE', true);
require __DIR__ . '/_ledger.php';
require_once APP_ROOT . '/Modules/Accounting/Services/ReportingService.php';

$db   = getDB();
$fye  = FiscalYear::setting($db);
$today = date('Y-m-d');
$year = FiscalYear::of($today, $fye);
$tb   = (new ReportingService($db))->getTrialBalance($today);
// GIFI readiness and the exception count each scan every journal line; five minutes stale is fine for
// an overview (review 2026-10-13). A GIFI save on the mapping page drops the cached readiness.
$gifi = LedgerSite::remember('gifi-' . $year, static function () use ($db, $year): array {
    $r = (new GifiService($db))->report($year);
    return ['ok' => (bool)$r['ok'], 'problems' => count($r['problems'])];
});
$lex  = new LockExceptionService($db);
$locked = $lex->lockedPeriods();
$excCount = (int)LedgerSite::remember('exceptions', static fn(): int => count($lex->report()['rows']));
$prior = FiscalYear::lockState($year - 1, $locked, $fye);
$jr   = new JournalBrowserService($db);
$latest = $jr->search(JournalBrowserService::normalizeFilters(['per' => 50]));
$entryCount = (int)$latest['total'];

$pageTitle = 'Ledger';
?>
<?php include dirname(__DIR__) . '/includes/appstack_head.php'; ?>

<div class="mw-ledger">
  <?php mwLedgerNav('overview', userHasPermission('users.manage')); ?>

  <header class="mw-ledger-head">
    <div>
      <h1 class="mw-ledger-title"><?= h(LedgerExport::entity($db)) ?> — the books</h1>
      <p class="mw-ledger-sub">Fiscal year ends <?= h(FiscalYear::endLabel($fye)) ?>.
        <?php if ($prior['closed']): ?>FY<?= $year - 1 ?> is filed and locked (all twelve months).
        <?php elseif ($prior['locked'] > 0): ?>FY<?= $year - 1 ?> has <?= (int)$prior['locked'] ?> of <?= (int)$prior['months'] ?> months locked.
        <?php else: ?>FY<?= $year - 1 ?> is not locked.<?php endif; ?>
        FY<?= $year ?> is open.
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
      <span class="mw-ledger-stat-value mw-num"><?= number_format($entryCount) ?></span>
      <span class="mw-ledger-stat-note">every one traced to its source</span>
    </a>
    <a class="mw-ledger-stat" href="/crm/ledger/gifi.php">
      <span class="mw-ledger-stat-label">GIFI FY<?= $year ?></span>
      <span class="mw-ledger-stat-value"><?= $gifi['ok'] ? 'Ready' : (int)$gifi['problems'] . ' to resolve' ?></span>
      <span class="mw-ledger-stat-note <?= $gifi['ok'] ? 'is-ok' : 'is-warn' ?>"><?= $gifi['ok'] ? 'validates' : 'export blocked until mapped and confirmed' ?></span>
    </a>
    <a class="mw-ledger-stat" href="/crm/ledger/exceptions.php">
      <span class="mw-ledger-stat-label">Changes after a lock</span>
      <span class="mw-ledger-stat-value mw-num"><?= number_format($excCount) ?></span>
      <span class="mw-ledger-stat-note"><?= count($locked) ?> locked months</span>
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

<script src="/crm/js/mw-ledger.js?v=20261013b" defer></script>
<?php include dirname(__DIR__) . '/includes/appstack_footer.php'; ?>

<?php
/**
 * Ledger → Trial balance (comparative): unadjusted, proposed AJE (step 3), AJE, adjusted, prior year
 * (as filed) and the year before, with GIFI code and lead group on every line
 * (ComparativeTrialBalanceService). A row opens that account's general ledger. Exports through
 * export.php?report=tb — refused when a column doesn't balance.
 */
define('MW_LEDGER_PAGE', true);
require __DIR__ . '/_ledger.php';
require_once APP_ROOT . '/Modules/Ledger/Services/ComparativeTrialBalanceService.php';

$db   = getDB();
$asOf = FiscalYear::cleanDate($_GET['as_of'] ?? null);
$tb   = (new ComparativeTrialBalanceService($db))->build($asOf);
$p    = $tb['periods'];
$lab  = ComparativeTrialBalanceService::columnLabels($p, $tb['present']);
$cols = ComparativeTrialBalanceService::COLUMNS;
$typeLabel = ['asset' => 'Assets', 'liability' => 'Liabilities', 'equity' => 'Equity', 'revenue' => 'Revenue', 'expense' => 'Expenses'];
$fyStart = $p['fy_start'];
$exp = static fn(string $fmt): string => '/crm/ledger/export.php?' . http_build_query(['report' => 'tb', 'format' => $fmt, 'as_of' => $asOf]);
$cell = static function ($v): string {
    if ($v === null) return '<span class="mw-muted">—</span>';
    return mwLedgerMoney($v, true);
};

$pageTitle = 'Trial balance — Ledger';
?>
<?php include dirname(__DIR__) . '/includes/appstack_head.php'; ?>

<div class="mw-ledger">
  <?php mwLedgerNav('tb', userHasPermission('users.manage')); ?>

  <header class="mw-ledger-head">
    <div>
      <h1 class="mw-ledger-title">Trial balance</h1>
      <p class="mw-ledger-sub">As at <?= h($asOf) ?> · FY<?= (int)$p['fy'] ?> from <?= h($fyStart) ?> ·
        <?php if ($tb['in_balance']): ?><span class="mw-ledger-ok">every column balances</span>
        <?php else: ?><span class="mw-ledger-bad">OUT OF BALANCE — exports are refused</span><?php endif; ?>
        · <kbd>j</kbd>/<kbd>k</kbd> move · <kbd>Enter</kbd> open the account's ledger</p>
    </div>
    <div class="mw-ledger-exports" role="group" aria-label="Export the trial balance">
      <?php foreach (['xlsx' => 'XLSX', 'csv' => 'CSV', 'pdf' => 'PDF'] as $fmt => $label): ?>
        <?php if ($tb['in_balance']): ?><a class="btn btn-sm btn-outline-secondary" href="<?= h($exp($fmt)) ?>"><?= $label ?></a>
        <?php else: ?><span class="btn btn-sm btn-outline-secondary disabled" aria-disabled="true"><?= $label ?></span><?php endif; ?>
      <?php endforeach; ?>
    </div>
  </header>

  <form class="mw-ledger-filters" method="get" action="/crm/ledger/trial-balance.php">
    <?php mwLedgerDate('tb-asof', 'as_of', $asOf, '', '', 'As at'); ?>
    <div class="mw-ledger-actions"><button class="btn btn-sm btn-primary" type="submit">Show</button>
      <a class="btn btn-sm btn-link" href="/crm/ledger/trial-balance.php?as_of=<?= h($p['py_end']) ?>">FY<?= (int)$p['py'] ?> year end</a></div>
  </form>

  <div class="mw-ledger-tablewrap mw-ledger-tablewrap--tall">
    <table class="mw-ledger-table mw-ledger-table--tb" data-mw-keytable>
      <thead><tr>
        <th scope="col">Account</th><th scope="col" class="mw-ledger-hide-sm">GIFI</th><th scope="col" class="mw-ledger-hide-sm">Lead</th>
        <?php foreach ($cols as $k): ?>
          <th scope="col" class="mw-num mw-ledger-col--<?= $k ?><?= in_array($k, ['proposed', 'aje', 'py2'], true) ? ' mw-ledger-hide-sm' : '' ?>"><?= h($lab[$k]) ?></th>
        <?php endforeach; ?>
      </tr></thead>
      <tbody>
      <?php if (!$tb['rows']): ?><tr><td colspan="9" class="mw-ledger-empty">Nothing is posted on or before <?= h($asOf) ?>.</td></tr><?php endif; ?>
      <?php $prevType = null; foreach ($tb['rows'] as $r):
            if ($r['type'] !== $prevType): $prevType = $r['type']; ?>
        <tr class="mw-ledger-group"><th scope="rowgroup" colspan="9"><?= h($typeLabel[$r['type']] ?? ucfirst($r['type'])) ?></th></tr>
      <?php endif;
            $gl = '/crm/ledger/general-ledger.php?' . http_build_query(['account' => $r['code'], 'from' => $fyStart, 'to' => $asOf]); ?>
        <tr tabindex="0" class="mw-ledger-row" data-mw-row-href="<?= h($gl) ?>">
          <td><a class="mw-ledger-acct" href="<?= h($gl) ?>" tabindex="-1"><span class="mw-code"><?= h($r['code']) ?></span> <?= h($r['name']) ?></a></td>
          <td class="mw-code mw-ledger-hide-sm"><?= h((string)$r['gifi_code']) ?></td>
          <td class="mw-code mw-ledger-hide-sm"><?= h((string)$r['lead_group']) ?></td>
          <?php foreach ($cols as $k): ?>
            <td class="mw-num mw-ledger-col--<?= $k ?><?= in_array($k, ['proposed', 'aje', 'py2'], true) ? ' mw-ledger-hide-sm' : '' ?>"><?= $cell($r[$k]) ?></td>
          <?php endforeach; ?>
        </tr>
      <?php endforeach; ?>
      </tbody>
      <tfoot>
        <tr><th scope="row" colspan="3" class="mw-ledger-hide-sm">Net (must be zero)</th><th scope="row" class="mw-ledger-show-sm">Net</th>
          <?php foreach ($cols as $k): $has = ComparativeTrialBalanceService::hasColumn($tb, $k); ?>
            <td class="mw-num mw-ledger-col--<?= $k ?><?= in_array($k, ['proposed', 'aje', 'py2'], true) ? ' mw-ledger-hide-sm' : '' ?><?= $tb['balanced'][$k] ? '' : ' is-bad' ?>"><?= $has ? mwLedgerMoney($tb['totals'][$k]) : '—' ?></td>
          <?php endforeach; ?></tr>
        <tr><th scope="row" colspan="3" class="mw-ledger-hide-sm">Total debits</th><th scope="row" class="mw-ledger-show-sm">Debits</th>
          <?php foreach ($cols as $k): $has = ComparativeTrialBalanceService::hasColumn($tb, $k); ?>
            <td class="mw-num mw-ledger-col--<?= $k ?><?= in_array($k, ['proposed', 'aje', 'py2'], true) ? ' mw-ledger-hide-sm' : '' ?>"><?= $has ? mwLedgerMoney($tb['debits'][$k]) : '—' ?></td>
          <?php endforeach; ?></tr>
      </tfoot>
    </table>
  </div>

  <p class="mw-ledger-explain mw-ledger-small">Debits positive, credits in parentheses. <strong>Adjusted</strong> is every posted line to
    <?= h($asOf) ?> (the same figures as the office trial balance); <strong>unadjusted</strong> is that before posted AJEs.
    <strong>Proposed AJE</strong> fills when the accountant can propose entries (Ledger step 3).
    <strong>FY<?= (int)$p['py'] ?></strong> is the balance at <?= h($p['py_end']) ?> after the FY opening entry that brings it to the filed
    statements — income accounts closed into retained earnings.</p>

  <?php if ($tb['filed']): ?>
  <h2 class="mw-ledger-h2">Filed statements beside the ledger</h2>
  <div class="mw-ledger-tablewrap mw-ledger-tablewrap--auto">
    <table class="mw-ledger-table mw-ledger-table--narrow">
      <thead><tr><th scope="col">Year</th><th scope="col">Filed balance-sheet line</th><th scope="col" class="mw-num">Filed</th><th scope="col" class="mw-num">Ledger (FY<?= (int)$p['py'] ?> column)</th><th scope="col" class="mw-num">Difference</th></tr></thead>
      <tbody>
      <?php foreach ($tb['filed'] as $f): ?>
        <tr>
          <td class="mw-nowrap">FY<?= (int)$f['fiscal_year'] ?></td>
          <td><?= h($f['label']) ?></td>
          <td class="mw-num"><?= mwLedgerMoney($f['filed']) ?></td>
          <td class="mw-num"><?= $f['ledger'] === null ? '<span class="mw-muted">not in the journal</span>' : mwLedgerMoney($f['ledger']) ?></td>
          <td class="mw-num<?= $f['diff'] !== null && abs($f['diff']) >= 1 ? ' is-bad' : '' ?>"><?= $f['diff'] === null ? '' : mwLedgerMoney($f['diff'], true) ?></td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
  </div>
  <p class="mw-ledger-explain mw-ledger-small">Filed figures are whole dollars, on each line's normal side (assets debit; liabilities and equity credit).</p>
  <?php endif; ?>
</div>

<script src="/crm/js/mw-ledger.js?v=20261013c" defer></script>
<?php include dirname(__DIR__) . '/includes/appstack_footer.php'; ?>

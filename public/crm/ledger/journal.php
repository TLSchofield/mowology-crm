<?php
/**
 * Ledger → Journal: every journal entry, filterable; Enter (or a click) opens the entry in place —
 * lines, reversal links, the source document. ?export=csv downloads the filtered entries with lines.
 */
define('MW_LEDGER_PAGE', true);
require __DIR__ . '/_ledger.php';

$db  = getDB();
$svc = new JournalBrowserService($db);
$q   = $_GET;
if (!isset($q['from']) && !isset($q['to']) && !isset($q['q'])) { $q['from'] = date('Y') . '-01-01'; $q['to'] = date('Y-m-d'); }
$f   = JournalBrowserService::normalizeFilters($q);

if (($_GET['export'] ?? '') === 'csv') {
    // One streamed query, every line, no cap (review 2026-10-13).
    $from = $f['from'] ?: '0000-01-01'; $to = $f['to'] ?: date('Y-m-d');
    $header = LedgerExport::headerRows(LedgerExport::entity($db), 'Journal', ($f['from'] ?: 'start') . ' to ' . ($f['to'] ?: 'today'),
                                       LedgerExport::lockStatus($db, $from, $to), date('Y-m-d H:i:s T'));
    $fh = LedgerExport::csvStream('journal-' . ($f['from'] ?: 'all') . '-' . ($f['to'] ?: date('Y-m-d')) . '.csv', 'journal.csv',
                                  array_filter($f, fn($v) => $v !== null));
    foreach ($header as $h) LedgerExport::csvLine($fh, $h);
    LedgerExport::csvLine($fh, ['Entry', 'Date', 'Kind', 'Source type', 'Source id', 'Memo', 'Account', 'Account name', 'GIFI', 'Debit', 'Credit',
                                'Line description', 'By', 'Created at', 'Reversed by entry']);
    $svc->streamLines($f, static function (array $l) use ($fh): void {
        LedgerExport::csvLine($fh, [(int)$l['id'], $l['entry_date'], $l['display_kind'], $l['source_type'], $l['source_id'] !== null ? (int)$l['source_id'] : null,
                                    $l['memo'], $l['code'], $l['account_name'], $l['gifi_code'], (float)$l['debit'], (float)$l['credit'],
                                    $l['description'], $l['created_by_name'] ?: ($l['proposed_by'] ?: 'system'), $l['created_at'],
                                    $l['reversed_by_entry_id'] !== null ? (int)$l['reversed_by_entry_id'] : null]);
    });
    fclose($fh);
    exit;
}

$res    = $svc->search($f);
$facets = $svc->facets();
$qs = static function (array $over) use ($f): string {
    $p = array_filter(array_merge($f, $over), fn($v) => $v !== null && $v !== '');
    return '?' . http_build_query($p);
};

$pageTitle = 'Journal — Ledger';
?>
<?php include dirname(__DIR__) . '/includes/appstack_head.php'; ?>

<div class="mw-ledger">
  <?php mwLedgerNav('journal', userHasPermission('users.manage')); ?>

  <header class="mw-ledger-head">
    <div>
      <h1 class="mw-ledger-title">Journal</h1>
      <p class="mw-ledger-sub"><?= number_format($res['total']) ?> entr<?= $res['total'] === 1 ? 'y' : 'ies' ?>
        <?= $f['from'] || $f['to'] ? '· ' . h($f['from'] ?: 'start') . ' to ' . h($f['to'] ?: 'today') : '' ?>
        · <kbd>/</kbd> search · <kbd>j</kbd>/<kbd>k</kbd> move · <kbd>Enter</kbd> open</p>
    </div>
    <a class="btn btn-sm btn-outline-secondary" href="<?= h($qs(['export' => 'csv', 'page' => null])) ?>">Export CSV</a>
  </header>

  <form class="mw-ledger-filters" method="get" action="/crm/ledger/journal.php" role="search">
    <?php mwLedgerDate('jf-from', 'from', $f['from'], 'jf-range', 'start', 'From'); ?>
    <?php mwLedgerDate('jf-to', 'to', $f['to'], 'jf-range', 'end', 'To'); ?>
    <label class="mw-ledger-field"><span>Account</span>
      <select name="account" class="mw-searchable">
        <option value="">Any account</option>
        <?php foreach ($facets['accounts'] as $code => $name): ?>
          <option value="<?= h((string)$code) ?>"<?= $f['account'] === (string)$code ? ' selected' : '' ?>><?= h($code . ' ' . $name) ?></option>
        <?php endforeach; ?>
      </select></label>
    <label class="mw-ledger-field"><span>Source</span>
      <select name="source">
        <option value="">Any source</option>
        <?php foreach ($facets['sources'] as $s => $n): ?>
          <option value="<?= h((string)$s) ?>"<?= $f['source'] === $s ? ' selected' : '' ?>><?= h(str_replace('_', ' ', (string)$s)) ?> (<?= (int)$n ?>)</option>
        <?php endforeach; ?>
      </select></label>
    <label class="mw-ledger-field"><span>Kind</span>
      <select name="kind">
        <option value="">Any kind</option>
        <?php foreach (['system' => 'System', 'manual' => 'Manual', 'aje' => 'AJE', 'rje' => 'RJE', 'reversal' => 'Reversal'] as $k => $lab): ?>
          <option value="<?= $k ?>"<?= $f['kind'] === $k ? ' selected' : '' ?>><?= $lab ?></option>
        <?php endforeach; ?>
      </select></label>
    <label class="mw-ledger-field"><span>By</span>
      <select name="user">
        <option value="">Anyone</option>
        <?php foreach ($facets['users'] as $uid => $uname): ?>
          <option value="<?= (int)$uid ?>"<?= $f['user'] === (int)$uid ? ' selected' : '' ?>><?= h((string)$uname) ?></option>
        <?php endforeach; ?>
      </select></label>
    <label class="mw-ledger-field mw-ledger-field--grow"><span>Text, entry #, or amount</span>
      <input type="search" name="q" value="<?= h((string)$f['q']) ?>" placeholder="memo, description, #4310, 1194.39" data-mw-ledger-search></label>
    <div class="mw-ledger-actions">
      <button class="btn btn-sm btn-primary" type="submit">Filter</button>
      <a class="btn btn-sm btn-link" href="/crm/ledger/journal.php?from=&amp;to=">Clear</a>
    </div>
  </form>

  <div class="mw-ledger-tablewrap">
    <table class="mw-ledger-table" data-mw-keytable data-mw-detail-url="/crm/ledger/entry.php?id=">
      <thead><tr>
        <th scope="col">Date</th><th scope="col">Entry</th><th scope="col">Kind</th><th scope="col">Source</th>
        <th scope="col" class="mw-ledger-memo">Memo</th><th scope="col" class="mw-ledger-hide-sm">Accounts</th>
        <th scope="col" class="mw-num">Amount</th><th scope="col" class="mw-ledger-hide-sm">By</th>
      </tr></thead>
      <tbody>
      <?php if (!$res['rows']): ?>
        <tr><td colspan="8" class="mw-ledger-empty">No entries match these filters.</td></tr>
      <?php endif; ?>
      <?php foreach ($res['rows'] as $r): $kind = strtolower($r['display_kind']); ?>
        <tr tabindex="0" data-id="<?= (int)$r['id'] ?>" class="mw-ledger-row<?= $r['reversed_by_entry_id'] ? ' is-reversed' : '' ?>">
          <td class="mw-nowrap"><?= h($r['entry_date']) ?></td>
          <td class="mw-code">#<?= (int)$r['id'] ?></td>
          <td><span class="mw-ledger-kind mw-ledger-kind--<?= h($kind) ?>"><?= h($r['display_kind']) ?></span><?= $r['reversed_by_entry_id'] ? ' <span class="mw-ledger-flag" title="Reversed by #' . (int)$r['reversed_by_entry_id'] . '">reversed</span>' : '' ?></td>
          <td class="mw-nowrap"><?= h(str_replace('_', ' ', (string)$r['source_type'])) ?><?= $r['source_id'] ? ' <span class="mw-muted">' . (int)$r['source_id'] . '</span>' : '' ?></td>
          <td class="mw-ledger-memo"><?= h((string)$r['memo']) ?></td>
          <td class="mw-code mw-ledger-hide-sm"><?= h((string)$r['accounts']) ?></td>
          <td class="mw-num"><?= mwLedgerMoney($r['amount']) ?></td>
          <td class="mw-ledger-hide-sm mw-nowrap"><?= h($r['created_by_name'] ?: ($r['proposed_by'] ?: 'system')) ?></td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
  </div>

  <?php if ($res['pages'] > 1): ?>
  <nav class="mw-ledger-pager" aria-label="Pages">
    <?php if ($f['page'] > 1): ?><a href="<?= h($qs(['page' => $f['page'] - 1])) ?>" rel="prev">← Newer</a><?php endif; ?>
    <span>Page <?= (int)$f['page'] ?> of <?= (int)$res['pages'] ?></span>
    <?php if ($f['page'] < $res['pages']): ?><a href="<?= h($qs(['page' => $f['page'] + 1])) ?>" rel="next">Older →</a><?php endif; ?>
  </nav>
  <?php endif; ?>
</div>

<script src="/crm/js/mw-ledger.js?v=20261013b" defer></script>
<?php include dirname(__DIR__) . '/includes/appstack_footer.php'; ?>

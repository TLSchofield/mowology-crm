<?php
/**
 * Ledger → General ledger: one account over a date range — opening balance, every line with its running
 * balance, closing balance (GeneralLedgerService). Enter (or a click) on a line opens its journal entry
 * and source document in place (entry.php, JournalBrowserService::source). Exports through
 * export.php?report=gl (this account, or every account).
 */
define('MW_LEDGER_PAGE', true);
require __DIR__ . '/_ledger.php';
require_once APP_ROOT . '/Modules/Ledger/Services/GeneralLedgerService.php';

$db   = getDB();
$fye  = FiscalYear::setting($db);
$svc  = new GeneralLedgerService($db);
$accounts = $svc->accounts();
$to   = FiscalYear::cleanDate($_GET['to'] ?? null);
$from = FiscalYear::cleanDate($_GET['from'] ?? null, FiscalYear::bounds(FiscalYear::of($to, $fye), $fye)[0]);
if ($from > $to) [$from, $to] = [$to, $from];
$code = (string)($_GET['account'] ?? '');
if (!isset($accounts[$code])) $code = isset($accounts['1010']) ? '1010' : (string)(array_key_first($accounts) ?? '');
$g = $code !== '' ? $svc->account($code, $from, $to) : null;
$exp = static fn(string $fmt, bool $all = false): string => '/crm/ledger/export.php?' . http_build_query(array_filter(
    ['report' => 'gl', 'format' => $fmt, 'account' => $all ? null : $code, 'from' => $from, 'to' => $to], static fn($v) => $v !== null));

$pageTitle = 'General ledger — Ledger';
?>
<?php include dirname(__DIR__) . '/includes/appstack_head.php'; ?>

<div class="mw-ledger">
  <?php mwLedgerNav('gl', userHasPermission('users.manage')); ?>

  <header class="mw-ledger-head">
    <div>
      <h1 class="mw-ledger-title">General ledger<?= $g ? ' — <span class="mw-code">' . h($g['account']['code']) . '</span> ' . h($g['account']['name']) : '' ?></h1>
      <p class="mw-ledger-sub"><?= h($from) ?> to <?= h($to) ?><?= $g ? ' · ' . number_format(count($g['lines'])) . ' line' . (count($g['lines']) === 1 ? '' : 's') : '' ?>
        · <kbd>j</kbd>/<kbd>k</kbd> move · <kbd>Enter</kbd> open the entry and its source</p>
    </div>
    <div class="mw-ledger-exports" role="group" aria-label="Export the general ledger">
      <span class="mw-ledger-exports-label">This account</span>
      <a class="btn btn-sm btn-outline-secondary" href="<?= h($exp('xlsx')) ?>">XLSX</a>
      <a class="btn btn-sm btn-outline-secondary" href="<?= h($exp('csv')) ?>">CSV</a>
      <a class="btn btn-sm btn-outline-secondary" href="<?= h($exp('pdf')) ?>">PDF</a>
      <span class="mw-ledger-exports-label">All accounts</span>
      <a class="btn btn-sm btn-outline-secondary" href="<?= h($exp('xlsx', true)) ?>">XLSX</a>
      <a class="btn btn-sm btn-outline-secondary" href="<?= h($exp('csv', true)) ?>">CSV</a>
    </div>
  </header>

  <form class="mw-ledger-filters" method="get" action="/crm/ledger/general-ledger.php">
    <label class="mw-ledger-field mw-ledger-field--grow"><span>Account</span>
      <select name="account" class="mw-searchable" data-mw-autosubmit>
        <?php foreach ($accounts as $c => $a): ?>
          <option value="<?= h((string)$c) ?>"<?= (string)$c === $code ? ' selected' : '' ?>><?= h($c . ' ' . $a['name']) ?></option>
        <?php endforeach; ?>
      </select></label>
    <?php mwLedgerDate('gl-from', 'from', $from, 'gl-range', 'start', 'From'); ?>
    <?php mwLedgerDate('gl-to', 'to', $to, 'gl-range', 'end', 'To'); ?>
    <div class="mw-ledger-actions"><button class="btn btn-sm btn-primary" type="submit">Show</button></div>
  </form>

  <?php if (!$g): ?>
    <p class="mw-ledger-empty">No accounts.</p>
  <?php else: ?>
  <div class="mw-ledger-tablewrap mw-ledger-tablewrap--tall">
    <table class="mw-ledger-table mw-ledger-table--gl" data-mw-keytable data-mw-detail-url="/crm/ledger/entry.php?id=">
      <thead><tr>
        <th scope="col">Date</th><th scope="col">Entry</th><th scope="col" class="mw-ledger-hide-sm">Source</th>
        <th scope="col" class="mw-ledger-memo">Memo / description</th>
        <th scope="col" class="mw-num">Debit</th><th scope="col" class="mw-num">Credit</th><th scope="col" class="mw-num">Balance</th>
      </tr></thead>
      <tbody>
        <tr class="mw-ledger-balance-row"><td class="mw-nowrap"><?= h($from) ?></td><td></td><td class="mw-ledger-hide-sm"></td>
          <td>Opening balance</td><td></td><td></td><td class="mw-num"><?= mwLedgerMoney($g['opening']) ?></td></tr>
        <?php if (!$g['lines']): ?>
          <tr><td colspan="7" class="mw-ledger-empty">No lines on this account from <?= h($from) ?> to <?= h($to) ?>.</td></tr>
        <?php endif; ?>
        <?php foreach ($g['lines'] as $l):
              $kind = JournalBrowserService::displayKind($l);
              $text = trim((string)$l['memo']);
              $desc = trim((string)$l['description']); ?>
        <tr tabindex="0" data-id="<?= (int)$l['entry_id'] ?>" class="mw-ledger-row">
          <td class="mw-nowrap"><?= h($l['entry_date']) ?></td>
          <td class="mw-code mw-nowrap">#<?= (int)$l['entry_id'] ?><?= $kind !== 'System' ? ' <span class="mw-ledger-kind mw-ledger-kind--' . h(strtolower($kind)) . '">' . h($kind) . '</span>' : '' ?></td>
          <td class="mw-nowrap mw-ledger-hide-sm"><?= h(str_replace('_', ' ', (string)$l['source_type'])) ?><?= $l['source_id'] ? ' <span class="mw-muted">' . (int)$l['source_id'] . '</span>' : '' ?></td>
          <td class="mw-ledger-memo"><?= h($text) ?><?= $desc !== '' && $desc !== $text ? '<span class="mw-ledger-desc">' . h($desc) . '</span>' : '' ?></td>
          <td class="mw-num"><?= mwLedgerMoney($l['debit'], true) ?></td>
          <td class="mw-num"><?= mwLedgerMoney($l['credit'], true) ?></td>
          <td class="mw-num"><?= mwLedgerMoney($l['balance']) ?></td>
        </tr>
        <?php endforeach; ?>
      </tbody>
      <tfoot>
        <tr><th scope="row" class="mw-nowrap"><?= h($to) ?></th><th></th><th class="mw-ledger-hide-sm"></th><th scope="row">Closing balance</th>
          <td class="mw-num"><?= mwLedgerMoney($g['debits']) ?></td><td class="mw-num"><?= mwLedgerMoney($g['credits']) ?></td>
          <td class="mw-num"><?= mwLedgerMoney($g['closing']) ?></td></tr>
      </tfoot>
    </table>
  </div>
  <p class="mw-ledger-explain mw-ledger-small">Balance = debits − credits (credits in parentheses). Opening = every posted line before <?= h($from) ?>;
    opening + debits − credits = closing.</p>
  <?php endif; ?>
</div>

<script src="/crm/js/mw-ledger.js?v=20261013c" defer></script>
<?php include dirname(__DIR__) . '/includes/appstack_footer.php'; ?>

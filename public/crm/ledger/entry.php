<?php
/**
 * Ledger → one journal entry: lines, reversal links, the source document (receipt photo through a
 * 15-minute signed link, invoice summary, bank line). Fetched in place by the journal (?part=1 returns
 * just the panel); opened directly it is a full page.
 */
define('MW_LEDGER_PAGE', true);
require __DIR__ . '/_ledger.php';
require_once APP_ROOT . '/Core/Auth/JwtAuth.php';
require_once APP_ROOT . '/Modules/Expenses/Services/ReceiptImageLinks.php';

$id = (int)($_GET['id'] ?? 0);
$receiptKey = null;
try { $receiptKey = ReceiptImageLinks::key(jwtSecret()); } catch (Throwable $e) { $receiptKey = null; }
$e = $id > 0 ? (new JournalBrowserService(getDB()))->entry($id, $receiptKey) : null;
$part = !empty($_GET['part']);
$canSeeInvoices = userHasPermission('billing.view');   // the full invoice page; the accountant sees the summary here

if (!$e) {
    http_response_code(404);
    if ($part) { echo '<div class="mw-ledger-detail"><p>Entry not found.</p></div>'; exit; }
}

$fmtVal = static function ($k, $v): string {
    if ($v === null || $v === '') return '—';
    if (is_numeric($v) && preg_match('/amount|total|subtotal|gst|pst|paid$|balance|tax|before/i', (string)$k) && !preg_match('/on$/i', (string)$k)) {
        return mwLedgerMoney($v);
    }
    return h((string)$v);
};

ob_start();
if ($e):
    $dr = 0.0; $cr = 0.0;
    foreach ($e['lines'] as $l) { $dr += (float)$l['debit']; $cr += (float)$l['credit']; }
    $src = $e['source'];
?>
<section class="mw-ledger-detail" aria-label="Entry #<?= (int)$e['id'] ?>">
  <div class="mw-ledger-detail-head">
    <h2>Entry #<?= (int)$e['id'] ?> <span class="mw-ledger-kind mw-ledger-kind--<?= h(strtolower($e['display_kind'])) ?>"><?= h($e['display_kind']) ?></span></h2>
    <dl class="mw-ledger-meta">
      <div><dt>Dated</dt><dd><?= h($e['entry_date']) ?></dd></div>
      <div><dt>Made</dt><dd><?= h((string)$e['created_at']) ?></dd></div>
      <div><dt>By</dt><dd><?= h($e['created_by_name'] ?: '—') ?><?= $e['proposed_by'] ? ' · proposed by ' . h($e['proposed_by']) : '' ?></dd></div>
      <div><dt>Status</dt><dd><?= h($e['status']) ?></dd></div>
    </dl>
    <?php if ($e['memo']): ?><p class="mw-ledger-memo-full"><?= h($e['memo']) ?></p><?php endif; ?>
    <?php if ($e['reversed_by']): ?>
      <p class="mw-ledger-link-note">Reversed by <a href="/crm/ledger/entry.php?id=<?= (int)$e['reversed_by']['id'] ?>" data-mw-entry="<?= (int)$e['reversed_by']['id'] ?>">#<?= (int)$e['reversed_by']['id'] ?></a>
        on <?= h($e['reversed_by']['entry_date']) ?> — <?= h((string)$e['reversed_by']['memo']) ?></p>
    <?php endif; ?>
    <?php if ($e['reverses']): ?>
      <p class="mw-ledger-link-note">Reverses <a href="/crm/ledger/entry.php?id=<?= (int)$e['reverses']['id'] ?>" data-mw-entry="<?= (int)$e['reverses']['id'] ?>">#<?= (int)$e['reverses']['id'] ?></a>
        dated <?= h($e['reverses']['entry_date']) ?> — <?= h((string)$e['reverses']['memo']) ?></p>
    <?php endif; ?>
  </div>

  <div class="mw-ledger-detail-body">
    <table class="mw-ledger-table mw-ledger-table--lines">
      <thead><tr><th scope="col">Account</th><th scope="col" class="mw-ledger-hide-sm">GIFI</th><th scope="col">Description</th><th scope="col" class="mw-num">Debit</th><th scope="col" class="mw-num">Credit</th></tr></thead>
      <tbody>
      <?php foreach ($e['lines'] as $l): ?>
        <tr>
          <td><span class="mw-code"><?= h($l['code']) ?></span> <?= h($l['account_name']) ?></td>
          <td class="mw-code mw-ledger-hide-sm"><?= h((string)($l['gifi_code'] ?? '')) ?></td>
          <td><?= h((string)$l['description']) ?></td>
          <td class="mw-num"><?= mwLedgerMoney($l['debit'], true) ?></td>
          <td class="mw-num"><?= mwLedgerMoney($l['credit'], true) ?></td>
        </tr>
      <?php endforeach; ?>
      </tbody>
      <tfoot><tr><th scope="row" colspan="2" class="mw-ledger-hide-sm">Totals</th><th scope="row" class="mw-ledger-show-sm">Totals</th>
        <td class="mw-num"><?= mwLedgerMoney($dr) ?></td><td class="mw-num"><?= mwLedgerMoney($cr) ?></td></tr></tfoot>
    </table>

    <aside class="mw-ledger-source" aria-label="Source document">
      <h3><?= h($src['title']) ?></h3>
      <?php if (!$src['found']): ?><p class="mw-ledger-warn">The source record is missing — the entry stands, but nothing links to it.</p><?php endif; ?>
      <?php if (!empty($src['image_url'])): ?>
        <a class="mw-ledger-receipt" href="<?= h($src['image_url']) ?>" target="_blank" rel="noopener">
          <img src="<?= h($src['image_url']) ?>" alt="Receipt photo" loading="lazy"></a>
        <p class="mw-muted">Link valid 15 minutes.</p>
      <?php elseif (($src['type'] ?? '') === 'expense' && empty($src['has_photo'])): ?>
        <p class="mw-ledger-warn">No receipt photo on file.</p>
      <?php endif; ?>
      <?php if ($src['fields']): ?>
        <dl class="mw-ledger-fields">
          <?php foreach ($src['fields'] as $k => $v): if ($v === null || $v === '') continue; ?>
            <div><dt><?= h((string)$k) ?></dt><dd class="<?= is_numeric($v) ? 'mw-num' : '' ?>"><?= $fmtVal($k, $v) ?></dd></div>
          <?php endforeach; ?>
        </dl>
      <?php endif; ?>
      <?php if (!empty($src['penny'])): ?><p class="mw-ledger-penny"><strong>Penny read:</strong> <?= h($src['penny']) ?></p><?php endif; ?>
      <?php if (!empty($src['items'])): ?>
        <table class="mw-ledger-table mw-ledger-table--items">
          <thead><tr><th scope="col">Item</th><th scope="col" class="mw-num">Qty</th><th scope="col" class="mw-num">Amount</th></tr></thead>
          <tbody><?php foreach ($src['items'] as $it): ?>
            <tr><td><?= h((string)$it['description']) ?></td><td class="mw-num"><?= h((string)(float)$it['quantity']) ?></td><td class="mw-num"><?= mwLedgerMoney($it['amount']) ?></td></tr>
          <?php endforeach; ?></tbody>
        </table>
      <?php endif; ?>
      <?php if (!empty($src['bank_line'])): $b = $src['bank_line']; ?>
        <h4><?= h($b['title']) ?></h4>
        <dl class="mw-ledger-fields"><?php foreach ($b['fields'] as $k => $v): if ($v === null || $v === '') continue; ?>
          <div><dt><?= h((string)$k) ?></dt><dd><?= $fmtVal($k, $v) ?></dd></div><?php endforeach; ?></dl>
      <?php endif; ?>
      <?php if (!empty($src['invoice_id']) && $canSeeInvoices && !$ledgerIsAccountant): ?>
        <p><a href="/crm/invoices/view.php?id=<?= (int)$src['invoice_id'] ?>">Open the invoice</a></p>
      <?php endif; ?>
    </aside>
  </div>
</section>
<?php
endif;
$panel = ob_get_clean();

if ($part) { header('Content-Type: text/html; charset=utf-8'); echo $panel; exit; }

$pageTitle = $e ? 'Entry #' . (int)$e['id'] . ' — Ledger' : 'Entry not found — Ledger';
?>
<?php include dirname(__DIR__) . '/includes/appstack_head.php'; ?>
<div class="mw-ledger">
  <?php mwLedgerNav('journal', userHasPermission('users.manage')); ?>
  <p><a href="/crm/ledger/journal.php">← Journal</a></p>
  <?= $panel ?: '<p>Entry not found.</p>' ?>
</div>
<script src="/crm/js/mw-ledger.js?v=20261013b" defer></script>
<?php include dirname(__DIR__) . '/includes/appstack_footer.php'; ?>

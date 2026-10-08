<?php
/**
 * Penny's "Missing receipts" strip — included by dept-heads-deck.php inside Penny's card.
 *   "🧾 6 receipts missing ($412) — I'm asking the crew · 2 marked no receipt (no GST claim)"
 * "Manage" opens the full list (who is asked, why), Tim's card list (card ••last4 → person)
 * and the lines that never have a receipt — /crm/js/penny-missing.js → /crm/api/penny-chase.php.
 * Renders nothing before migration 1245 or on any error.
 */
try {
    require_once APP_ROOT . '/Modules/Expenses/Services/MissingReceiptService.php';
    $__mr = new MissingReceiptService(getDB());
    if (!$__mr->ready()) return;
    $__mrT = $__mr->totals();
    $__mrCards = count($__mr->cards());
} catch (Throwable $__e) {
    error_log('Penny missing strip: ' . $__e->getMessage());
    return;
}
?>
<div class="mw-penny-missing<?= $__mrT['open'] > 0 ? ' is-bad' : '' ?>" id="mw-penny-missing"
     title="Card and bank charges from 2026 with no receipt. Penny asks whoever probably made them, on the crew app.">
  <div class="mw-pm-row">
    <span class="mw-pm-k">🧾 Missing receipts</span>
    <?php if ($__mrT['open'] > 0): ?>
      <b><?= (int)$__mrT['open'] ?> missing (<?= h(MissingReceiptService::money($__mrT['open_amount'], true)) ?>)</b>
      <span>— I'm asking the crew</span>
    <?php else: ?>
      <span class="mw-pm-ok">every card charge has its receipt ✓</span>
    <?php endif; ?>
    <?php if ($__mrT['no_receipt'] > 0): ?>
      <span class="mw-pm-sep">·</span>
      <span class="mw-pm-warn"><?= (int)$__mrT['no_receipt'] ?> marked no receipt (<?= h(MissingReceiptService::money($__mrT['no_receipt_amount'], true)) ?>, no GST claim)</span>
    <?php endif; ?>
    <button type="button" class="mw-pm-link" data-pm-open aria-expanded="false" aria-controls="mw-pm-panel">Manage →</button>
  </div>
  <?php if ($__mrCards === 0): ?>
    <div class="mw-pm-note">Tell me whose card is whose (last 4 digits) and I'll ask the right person every time.</div>
  <?php endif; ?>
  <div class="mw-pm-panel" id="mw-pm-panel" hidden></div>
</div>
<script src="<?= function_exists('_av') ? _av('/crm/js/penny-missing.js') : '/crm/js/penny-missing.js' ?>" defer></script>

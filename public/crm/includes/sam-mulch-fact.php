<?php
/**
 * Sam's card — mulch pricing fact + "price mulch for an address" box.
 *
 * Server-rendered: what bark mulch costs us per yard from Penny's receipt lines (median of the
 * newest few, with the vendor and date). The box asks /crm/api/mulch-pricing.php?mode=hint for
 * the installed price per yard at an address or postcode (sam-mulch-hint.js).
 * Included by sam-card.php. Never breaks the card: any failure renders nothing.
 */
if (!function_exists('userHasPermission') || !userHasPermission('billing.edit')) {
    return;
}
try {
    require_once APP_ROOT . '/Modules/Sales/Services/MulchPricingService.php';
    $__mp = new MulchPricingService(getDB());
    $__mprod = $__mp->product('mulch');
    $__mmat = MulchPricingService::material($__mp->receiptLines('mulch', $__mprod['ids']), $__mprod['base_cost'],
                                            (int)$__mp->settings()['mulch_receipts_n']['value']);
} catch (Throwable $__e) {
    error_log('Sam mulch fact unavailable: ' . $__e->getMessage());
    return;
}
?>
    <div class="mw-sam-mulch" id="mw-sam-mulch">
      <div class="mw-sam-mulch-fact">
        <span class="mw-sam-mulch-k">Bark mulch</span>
        <?php if ($__mmat['per_yard'] !== null): ?>
          costs us <b>$<?= h(number_format((float)$__mmat['per_yard'], 2)) ?>/yd</b> before GST —
          <?php if ($__mmat['source'] === 'receipts'): ?>
            median of <?= (int)$__mmat['n'] ?> receipt line<?= $__mmat['n'] === 1 ? '' : 's' ?><?= $__mmat['usual_vendor'] ? ', mostly ' . h(MulchPricingService::vendorLabel((string)$__mmat['usual_vendor'])) : '' ?>,
            newest <?= h(date('M j', strtotime((string)$__mmat['evidence'][0]['date']))) ?>.
          <?php else: ?>
            the product's cost; no receipt lines yet.
          <?php endif; ?>
        <?php else: ?>
          — no receipts or product cost yet.
        <?php endif; ?>
      </div>
      <form class="mw-sam-mulch-ask" id="mw-sam-mulch-form" autocomplete="off">
        <label for="mw-sam-mulch-q">Installed price for</label>
        <input type="text" id="mw-sam-mulch-q" placeholder="Address or postcode, e.g. 2448 Larch or V6K" maxlength="80">
        <button type="submit" class="btn btn-sm btn-outline-success">Price it</button>
      </form>
      <div class="mw-sam-mulch-out" id="mw-sam-mulch-out" aria-live="polite" hidden></div>
    </div>
    <script src="<?= function_exists('_av') ? _av('/crm/js/sam-mulch-hint.js') : '/crm/js/sam-mulch-hint.js' ?>" defer></script>

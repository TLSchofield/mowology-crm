<?php
/**
 * Sam the Closer — "Closer's price" card on a quote (quotes/view.php, right column).
 *
 * Shows the Closer's price beside the quote's own price for each line, the three tiers and a
 * margin-floor flag. It never replaces a price or changes a rate. The numbers load from
 * /crm/api/closer.php (closer-panel.js), so a failure there can never break the quote page.
 *
 * Renders nothing until migration 1141 has run, or for users without billing.edit.
 * Expects $quoteId (int) from the including page.
 */
if (!function_exists('userHasPermission') || !userHasPermission('billing.edit') || empty($quoteId)) {
    return;
}
try {
    require_once APP_ROOT . '/Modules/Sales/Services/CloserService.php';
    if (!(new CloserService(getDB()))->ready()) {
        return;
    }
} catch (Throwable $e) {
    return;
}
// Sam's face and brain, as on his dashboard card — a bonus, never a reason to hide the panel
$__cbrain = null;
$__cbright = 0.5;
try {
    require_once APP_ROOT . '/Modules/Sales/Services/SamBadgeService.php';
    require_once APP_ROOT . '/Modules/Sales/Services/SamBrainService.php';
    $__cbright = (new SamBadgeService(getDB()))->rightFirstTime() ?? 0.5;
    $__cbrain = (new SamBrainService(getDB()))->learned();
} catch (Throwable $e) { /* no brain — the photo still shows */ }
?>
                  <div class="card mw-closer" id="mw-closer" data-quote-id="<?php echo (int)$quoteId; ?>">
                      <div class="card-header d-flex justify-content-between align-items-center">
                          <div class="mw-closer-head">
                              <div class="mw-closer-photo">
                                  <img src="/crm/img/heads/sam.jpg" alt="Sam, the Closer" width="104" height="104">
                                  <?php if ($__cbrain !== null): ?>
                                  <button type="button" class="mw-head-brain" aria-label="Sam's brain: <?php echo (int)$__cbrain['units']; ?> things learned"
                                          data-head="Sam"
                                          data-units="<?php echo (int)$__cbrain['units']; ?>"
                                          data-bright="<?php echo h((string)$__cbright); ?>"
                                          data-parts="<?php echo h(json_encode($__cbrain['parts'])); ?>"
                                          data-since="<?php echo h(!empty($__cbrain['since']) ? date('M j, Y', strtotime($__cbrain['since'])) : ''); ?>"
                                          data-empty="Nothing learned yet. Every follow-up you send, edit or skip teaches him something."
                                          data-teach="He glows brighter the more of his drafts you send unchanged"><canvas></canvas></button>
                                  <?php endif; ?>
                              </div>
                              <div>
                                  <div class="mw-closer-name">Sam</div>
                                  <span class="mw-closer-who">The Closer</span>
                                  <h5 class="card-title mb-0 mt-1">Closer's price</h5>
                              </div>
                          </div>
                      </div>
                      <div class="card-body mw-closer-body">
                          <p class="mw-closer-loading">Working out what this lot costs…</p>
                      </div>
                  </div>
                  <script src="<?php echo function_exists('_av') ? _av('/crm/js/head-brain.js') : '/crm/js/head-brain.js'; ?>" defer></script>
                  <script src="/crm/js/closer-panel.js?v=2"></script>

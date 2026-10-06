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
?>
                  <div class="card mw-closer" id="mw-closer" data-quote-id="<?php echo (int)$quoteId; ?>">
                      <div class="card-header d-flex justify-content-between align-items-center">
                          <h5 class="card-title mb-0">Closer's price</h5>
                          <span class="mw-closer-who">Sam · Closer</span>
                      </div>
                      <div class="card-body mw-closer-body">
                          <p class="mw-closer-loading">Working out what this lot costs…</p>
                      </div>
                  </div>
                  <script src="/crm/js/closer-panel.js?v=1"></script>

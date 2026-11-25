<?php
/**
 * Mia: Search & Website. The owner's workspace for Mia's website and search work (Phase 1.13).
 *
 *   Presence  your websites, whether Mia may look at them, what she found, Search Console.
 *   Growth    what Mia suggests, with the reason, the evidence and the exact wording. Approve, edit, set aside.
 *   Results   what you have decided so far. (Leads and profit arrive in a later phase: nothing is estimated here.)
 *
 * Thin page: the shell only. Everything is read and written through /crm/api/mia.php (MiaApiHandler), which checks
 * login, marketing.approve and CSRF. /crm/js/mia-workspace.js draws it with textContent only (never innerHTML), so
 * nothing from a crawled website or a search query is ever interpreted as markup.
 * Approving only records the decision: Mia does not change the website in Phase 1.
 */
declare(strict_types=1);
require_once dirname(__DIR__) . '/../loginAuth/auth.php';
require_once dirname(__DIR__) . '/includes/functions.php';
requireLogin();
$user = getCurrentUser();
requirePermission('marketing.approve');

$pageTitle  = 'Mia: Search & Website';
$activePage = 'mia';
?>
<?php include dirname(__DIR__) . '/includes/appstack_head.php'; ?>

          <div class="mw-mws it-mws" id="mw-mws" data-api="/crm/api/mia.php" data-csrf="<?= htmlspecialchars(function_exists('generateCSRFToken') ? generateCSRFToken() : '', ENT_QUOTES, 'UTF-8') ?>">
            <header class="mw-mws-top">
              <h1 class="mw-mws-h">Search &amp; Website</h1>
              <p class="mw-mws-sub" id="mws-sub" role="status" aria-live="polite">Loading&hellip;</p>
            </header>

            <div class="mw-mws-tabs" role="tablist" aria-label="Mia sections">
              <button type="button" class="mw-mws-tab" role="tab" id="mws-tab-presence" aria-controls="mws-presence" data-tab="presence">Presence</button>
              <button type="button" class="mw-mws-tab" role="tab" id="mws-tab-growth" aria-controls="mws-growth" data-tab="growth">Growth <span class="mw-mws-count" id="mws-count" hidden></span></button>
              <button type="button" class="mw-mws-tab" role="tab" id="mws-tab-results" aria-controls="mws-results" data-tab="results">Results</button>
            </div>

            <div class="mw-mws-flash" id="mws-flash" role="alert" hidden></div>

            <section class="mw-mws-panel" id="mws-presence" role="tabpanel" aria-labelledby="mws-tab-presence" hidden></section>
            <section class="mw-mws-panel" id="mws-growth" role="tabpanel" aria-labelledby="mws-tab-growth" hidden></section>
            <section class="mw-mws-panel" id="mws-results" role="tabpanel" aria-labelledby="mws-tab-results" hidden></section>

            <noscript><p class="mw-mws-note">This page needs JavaScript to show Mia&rsquo;s suggestions.</p></noscript>
          </div>

          <script src="<?= _av('/crm/js/mia-workspace.js') ?>" defer></script>

<?php include dirname(__DIR__) . '/includes/appstack_footer.php'; ?>

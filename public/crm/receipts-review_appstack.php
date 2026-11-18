<?php
/**
 * Review receipts — the review layer (owner, 2026-10-20: "I want a review layer … I do still want to work through
 * double-checking receipts accuracy"; duplicates shown under the copy already in the books, green, to merge into).
 *
 * Every waiting receipt with its evidence (paper total vs saved vs bank line, Penny's category and doubts, GST,
 * photo), grouped by vendor; suspected duplicate sets keeper-first. Nothing is approved until the person ticks
 * what they checked and presses Approve checked. Data + actions: /crm/api/receipt-review.php (ReceiptReviewService).
 * Ittai v1.2 (Kanso): ittai-tokens.css + ittai-review.css, no inline styles; the page is drawn by receipt-review.js.
 */
require_once __DIR__ . '/../loginAuth/auth.php';
requireLogin();
$user = getCurrentUser();
if (!userHasPermission('expenses.approve')) {
    http_response_code(403);
    exit('Reviewing receipts needs permission to approve expenses.');
}

$pageTitle = 'Review receipts';
$activePage = 'expenses';
$extraHead = '';
foreach (['ittai-tokens.css', 'ittai-review.css'] as $__css) {
    $extraHead .= '<link href="/crm/css/' . $__css . '?v=' . (int)@filemtime(__DIR__ . '/css/' . $__css) . '" rel="stylesheet">';
}
$extraHead .= '<script src="/crm/js/receipt-review.js?v=' . (int)@filemtime(__DIR__ . '/js/receipt-review.js') . '" defer></script>';
?>
<?php include 'includes/appstack_head.php'; ?>

<div class="it-design it-rv" id="itReview">
  <header class="it-rv-head">
    <div>
      <h1 class="it-rv-title">Review receipts</h1>
      <p class="it-rv-meta" data-rv-counts>Gathering the evidence…</p>
    </div>
    <a class="it-rv-link" href="/crm/expenses_appstack.php">All receipts</a>
  </header>

  <p class="it-rv-how">
    <span class="it-rv-ok" aria-hidden="true">✓</span> agrees &nbsp;·&nbsp; <span class="it-rv-warn" aria-hidden="true">!</span> needs a look.
    Tick each receipt you have checked; nothing is approved until you press <strong>Approve checked</strong>.
  </p>

  <div class="it-rv-filter" role="radiogroup" aria-label="Show">
    <button type="button" role="radio" aria-checked="true" data-rv-filter="all">All</button>
    <button type="button" role="radio" aria-checked="false" data-rv-filter="pass">Pass every check</button>
    <button type="button" role="radio" aria-checked="false" data-rv-filter="look">Needs a look</button>
  </div>

  <section class="it-rv-section" data-rv-dups hidden aria-labelledby="itRvDupsH">
    <h2 class="it-rv-h2" id="itRvDupsH">Suspected duplicates</h2>
    <p class="it-rv-sub">The receipt already in the books is on top, in green. Merge a copy into it, or say it isn't a duplicate.</p>
    <div data-rv-dups-list></div>
  </section>

  <section class="it-rv-section" aria-labelledby="itRvWaitH">
    <h2 class="it-rv-h2" id="itRvWaitH">Waiting, by vendor</h2>
    <div data-rv-groups><p class="it-rv-sub">Loading…</p></div>
  </section>

  <div class="it-rv-bar" data-rv-bar>
    <p class="it-rv-bar-sum" data-rv-sum aria-live="polite">Nothing ticked yet</p>
    <button type="button" class="it-rv-btn it-rv-btn-primary" data-rv-approve disabled>Approve checked</button>
  </div>

  <dialog class="it-rv-photo" data-rv-photo aria-label="Receipt photo">
    <form method="dialog"><button class="it-rv-btn" value="close">Close</button></form>
    <img alt="" data-rv-photo-img>
  </dialog>
</div>

<?php include 'includes/appstack_footer.php'; ?>

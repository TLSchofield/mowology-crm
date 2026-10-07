<?php
/**
 * Action Board — "Needs you now", the strip at the top of the dashboard.
 *
 * Shows Charlie's already-ranked items (the one thing + the rest, up to 6) as rows: the
 * face of the head it came from, the text, how long it has waited, one button and "Not now".
 * Nothing is computed here: /crm/js/action-board.js takes the data Charlie's card already
 * loaded (window.MW_CHARLIE_TODAY / the 'mw:charlie-today' event) and only fetches
 * /crm/api/charlie.php?mode=today itself if neither arrives. Its buttons call Charlie's own
 * actions (open / snooze), so he keeps learning the owner's order.
 *
 * Same gate as charlie-card.php: owner only, and nothing until migration 1170 has run.
 * Never breaks the dashboard: any failure renders nothing.
 */
if (!defined('APP_ROOT')) return;
try {
    require_once APP_ROOT . '/Modules/ChiefOfStaff/Services/CharlieDeskService.php';
    $__abDesk = new CharlieDeskService(getDB());
    $__abUser = (array)($user ?? (function_exists('getCurrentUser') ? getCurrentUser() : []) ?? []);
    if (!$__abDesk->ready() || !$__abDesk->isOwner($__abUser)) return;
} catch (Throwable $__e) {
    error_log('Action board unavailable: ' . $__e->getMessage());
    return;
}
$__abSrc = function_exists('_av') ? _av('/crm/js/action-board.js') : '/crm/js/action-board.js';
?>
<section class="card mw-action-board" id="mw-action-board" aria-labelledby="mw-action-board-title">
  <div class="mw-ab-head">
    <h2 class="mw-ab-title" id="mw-action-board-title">Needs you now</h2>
    <span class="mw-ab-count" data-ab-count hidden></span>
  </div>
  <p class="mw-ab-line" data-ab-status aria-live="polite">Charlie is checking with the team…</p>
  <ul class="mw-ab-rows" data-ab-rows hidden></ul>
  <p class="mw-ab-msg" data-ab-msg aria-live="polite"></p>
</section>
<script src="<?= h($__abSrc) ?>" defer></script>

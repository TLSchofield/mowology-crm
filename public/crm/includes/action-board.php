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
    require_once APP_ROOT . '/Services/HeadIdentity.php';
    require_once APP_ROOT . '/Modules/ChiefOfStaff/Services/CharlieDeskService.php';
    $__abDesk = new CharlieDeskService(getDB());
    $__abUser = (array)($user ?? (function_exists('getCurrentUser') ? getCurrentUser() : []) ?? []);
    if (!$__abDesk->ready() || !$__abDesk->isOwner($__abUser)) return;
} catch (Throwable $__e) {
    error_log('Action board unavailable: ' . $__e->getMessage());
    return;
}
$__abSrc = function_exists('_av') ? _av('/crm/js/action-board.js') : '/crm/js/action-board.js';
// Ittai design (the it_design choice): "Today with the office" above the columns — the hexagon with
// Charlie (or the head whose three you're on) in the centre, today's three, and a carousel to the next head.
$__abIttai = (($_COOKIE['it_design'] ?? '') === '1');
?>
<section class="card mw-action-board<?= $__abIttai ? ' it-today-board' : '' ?>" id="mw-action-board" data-chief-name="<?= h(HeadIdentity::chiefName()) ?>" aria-labelledby="mw-action-board-title">
  <?php if ($__abIttai): ?>
  <div class="it-today" data-ab-hero hidden>
    <section class="it-tc-office" aria-label="Who is on this">
      <svg class="it-tc-hex" data-tc-hex viewBox="-70 0 560 340" role="img" aria-label=""></svg>
      <ul class="it-tc-onthis" data-tc-onthis></ul>
      <div class="it-tc-legend">
        <span><i class="is-done" aria-hidden="true"></i>Has this ready</span>
        <span><i class="is-wait" aria-hidden="true"></i>Waiting on you</span>
      </div>
    </section>
    <section class="it-tc-main" aria-label="What needs you">
      <div class="it-tc-pick">
        <div class="it-tc-three">
          <div><div class="it-tc-eyebrow" data-tc-eyebrow></div><h2 class="it-tc-h" data-tc-h></h2></div>
          <div class="it-tc-tabs" role="group" aria-label="Today's three" data-tc-tabs></div>
        </div>
        <div class="it-tc-queue">
          <div class="it-tc-eyebrow">Next head</div>
          <div data-tc-next></div>
        </div>
      </div>
      <div class="it-tc-card" data-tc-card aria-live="polite"></div>
      <div class="it-tc-foot"><span data-tc-prev></span><span class="it-tc-note" data-tc-note></span></div>
    </section>
  </div>
  <?php endif; ?>
  <div class="mw-ab-head">
    <h2 class="mw-ab-title" id="mw-action-board-title"><?= $__abIttai ? 'Everything with the team' : 'Needs you now' ?></h2>
    <span class="mw-ab-count" data-ab-count hidden></span>
  </div>
  <p class="mw-ab-line" data-ab-status aria-live="polite"><?= h(HeadIdentity::chiefName()) ?> is checking with the team…</p>
  <ul class="mw-ab-rows" data-ab-rows hidden></ul>
  <p class="mw-ab-msg" data-ab-msg aria-live="polite"></p>
</section>
<?php
// Charlie's list as of his last run (cron every 15 min, or the last live answer), printed into the page
// so "Needs you now" shows with it — the live answer (~7 s) replaces it when it lands (speed pass,
// 2026-10-19). Stale (> 30 min) or missing: nothing printed, and the board fetches as before.
$__abSaved = null;
try {
    require_once APP_ROOT . '/Modules/ChiefOfStaff/Services/HeadBriefReadyService.php';
    $__abSaved = (new HeadBriefReadyService(getDB()))->today();
} catch (Throwable $__e) {
    error_log('Action board saved list: ' . get_class($__e));
}
if ($__abSaved): ?>
<script>window.MW_CHARLIE_TODAY = <?= json_encode($__abSaved, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_UNESCAPED_UNICODE) ?>;</script>
<?php endif; ?>
<script src="<?= h($__abSrc) ?>" defer></script>

<?php
/**
 * Charlie — Chief of Staff card on the department heads deck.
 *
 * The deck includes this file in place of Charlie's placeholder when it exists.
 * Owner only (ops_settings charlie_owner_user_id); renders nothing until migration 1170
 * has run, and never breaks the dashboard. The brief itself loads after the page
 * (/crm/js/charlie-card.js → /crm/api/charlie.php?mode=today), so the dashboard never
 * waits on four heads. Badges and the brain are cheap and render here.
 */
if (!defined('APP_ROOT')) return;
try {
    require_once APP_ROOT . '/Modules/ChiefOfStaff/Services/CharlieDeskService.php';
    $__cDesk = new CharlieDeskService(getDB());
    $__cUser = (array)($user ?? (function_exists('getCurrentUser') ? getCurrentUser() : []) ?? []);
    if (!$__cDesk->ready() || !$__cDesk->isOwner($__cUser)) return;

    $__cBadges = ['earned' => [], 'next' => null, 'called_rate' => null];
    $__cBrain = null;
    try {
        require_once APP_ROOT . '/Modules/ChiefOfStaff/Services/CharlieBadgeService.php';
        $__cBadges = (new CharlieBadgeService(getDB()))->badges();
        if (is_file(APP_ROOT . '/Services/HeadBrain.php')) {
            require_once APP_ROOT . '/Services/HeadBrain.php';
            $__cHeads = 1 + count(array_filter(['Sales/Services/SalesDeskService.php', 'Operations/Services/OpsDeskService.php', 'Marketing/Services/MiaDeskService.php'],
                static fn($f) => is_file(APP_ROOT . '/Modules/' . $f)));
            $__cBrain = (new HeadBrain(getDB(), 'charlie'))->learned(
                $__cDesk->brainCounts(count($__cBadges['earned']), $__cHeads), CharlieDeskService::BRAIN_LABELS);
        }
    } catch (Throwable $__e) { /* badges and brain are a bonus — never block the card */ }
} catch (Throwable $__e) {
    error_log('Charlie card unavailable: ' . $__e->getMessage());
    return;
}
$__cName = CharlieVoice::firstName($__cUser);
$__cAv = static fn(string $p) => function_exists('_av') ? _av($p) : $p;
?>
<section class="mw-head-card mw-charlie" id="mw-charlie" data-name="<?= h($__cName) ?>">
  <div class="mw-charlie-top">
    <div class="mw-charlie-photo">
      <img class="mw-charlie-face" src="/crm/img/heads/charlie.jpg" alt="Charlie, Chief of Staff" width="72" height="72">
      <?php if ($__cBrain !== null): ?>
        <button type="button" class="mw-head-brain mw-charlie-brain" aria-label="Charlie's brain: <?= (int)$__cBrain['units'] ?> things learned"
                data-head="Charlie"
                data-units="<?= (int)$__cBrain['units'] ?>"
                data-bright="<?= h((string)($__cBadges['called_rate'] ?? 0.5)) ?>"
                data-parts="<?= h(json_encode($__cBrain['parts'])) ?>"
                data-since="<?= h(!empty($__cBrain['since']) ? date('M j, Y', strtotime($__cBrain['since'])) : '') ?>"
                data-empty="Nothing learned yet — I learn your order from what you deal with first."
                data-teach="It glows brighter the more often you go first to the thing I picked"><canvas></canvas></button>
      <?php endif; ?>
    </div>
    <div class="mw-charlie-id">
      <div class="mw-head-nm">Charlie</div>
      <div class="mw-head-role">Chief of Staff</div>
      <span class="mw-head-pill"><i></i>Working</span>
    </div>
  </div>

  <?php if ($__cBadges['earned'] || $__cBadges['next']): ?>
  <div class="mw-head-badges mw-charlie-badges" aria-label="Charlie's badges">
    <?php foreach ($__cBadges['earned'] as $__b): ?>
      <span class="mw-head-badge" title="<?= h($__b['title']) ?>"><?= h($__b['icon']) ?> <?= h($__b['label']) ?></span>
    <?php endforeach; ?>
    <?php if ($__cBadges['next']): $__n = $__cBadges['next']; ?>
      <span class="mw-head-badge is-next" title="Next badge — <?= h($__n['title']) ?>">
        <?= h($__n['icon']) ?> <?= h($__n['label']) ?> <small><?= (int)$__n['have'] ?>/<?= (int)$__n['need'] ?></small>
        <i style="width: <?= (int)round($__n['have'] / max(1, $__n['need']) * 100) ?>%"></i>
      </span>
    <?php endif; ?>
  </div>
  <?php endif; ?>

  <div class="mw-charlie-say" id="mw-charlie-say" aria-live="polite">
    <span class="mw-charlie-lead">Reading everyone's desks…</span>
  </div>
  <div class="mw-charlie-acts" id="mw-charlie-acts" hidden>
    <button type="button" class="mw-charlie-go" data-what="open">Open it →</button>
    <button type="button" class="mw-charlie-later" data-what="snooze" title="Bring it back tomorrow">Later</button>
    <button type="button" class="mw-charlie-skip" data-what="dismiss" title="Not important today — I'll learn from this">Not today</button>
  </div>
  <div class="mw-charlie-msg" id="mw-charlie-msg" aria-live="polite"></div>
  <div class="mw-charlie-qs" id="mw-charlie-qs" hidden></div>
  <details class="mw-charlie-brief" id="mw-charlie-brief" hidden>
    <summary>Your 7 am brief</summary>
    <div class="mw-charlie-heads"></div>
  </details>
</section>
<script src="<?= h($__cAv('/crm/js/head-brain.js')) ?>" defer></script>
<script src="<?= h($__cAv('/crm/js/charlie-card.js')) ?>" defer></script>

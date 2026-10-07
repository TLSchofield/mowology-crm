<?php
/**
 * Mia — marketing & relationships head, on the dashboard's department heads deck.
 *
 * Included by dept-heads-deck.php in place of her placeholder (any existing
 * includes/<slug>-card.php replaces that head's placeholder). Renders NOTHING — never a
 * fatal — when the viewer can't approve marketing or migration 1160 hasn't run.
 *
 * Server side: her greeting, numbers, badges and brain. The suggestions carousel and her
 * questions are loaded by /crm/js/mia-card.js from /crm/api/mia.php. Mia drafts; Tim sends.
 */
if (!function_exists('userHasPermission') || !userHasPermission('marketing.approve')) {
    return;
}
try {
    require_once APP_ROOT . '/Modules/Marketing/Services/MiaDeskService.php';
    $__mia = new MiaDeskService(getDB());
    if (!$__mia->ready()) {
        return;
    }
    $__miaStats = $__mia->stats();
    $__miaName = MiaDeskService::firstName((array)($user ?? getCurrentUser() ?? []));
    $__miaBadges = ['earned' => [], 'next' => null];
    $__miaBrain = ['units' => 0, 'parts' => [], 'since' => null, 'bright' => 0.5];
    try {
        require_once APP_ROOT . '/Modules/Marketing/Services/MiaBadgeService.php';
        require_once APP_ROOT . '/Modules/Marketing/Services/MiaBrainService.php';
        $__miaBadges = (new MiaBadgeService(getDB()))->badges();
        $__miaBrain = (new MiaBrainService(getDB()))->learned();
    } catch (Throwable $__e) { /* badges and brain are a bonus — never block the card */ }
} catch (Throwable $__e) {
    error_log('Mia card unavailable: ' . $__e->getMessage());
    return;
}
$__ms = $__miaStats;
$__js = function (string $p): string { return function_exists('_av') ? _av($p) : $p; };
?>
<section class="mw-head-card mw-mia" id="mw-mia">
  <div class="mw-mia-top">
    <div class="mw-mia-face">
      <img src="/crm/img/heads/mia.jpg" alt="Mia, marketing &amp; relationships" width="88" height="88">
      <button type="button" class="mw-head-brain mw-mia-brain" aria-label="Mia's brain — what she has learned"
              data-head="Mia" data-units="<?= (int)$__miaBrain['units'] ?>" data-bright="<?= h((string)$__miaBrain['bright']) ?>"
              data-parts='<?= h(json_encode($__miaBrain['parts'])) ?>' data-since="<?= h((string)($__miaBrain['since'] ?? '')) ?>"
              data-empty="Nothing learned yet. Send, edit or skip my first suggestions and I'll start."
              data-teach="It glows brighter the more often you send what I suggest"><canvas></canvas></button>
    </div>
    <div class="mw-mia-id">
      <div class="mw-head-nm">Mia</div>
      <div class="mw-head-role">Marketing &amp; relationships</div>
      <span class="mw-head-pill"><i></i>Working</span>
      <div class="mw-head-badges" aria-label="Mia's badges">
        <?php foreach ($__miaBadges['earned'] as $__mb): ?>
          <span class="mw-head-badge" title="<?= h($__mb['title']) ?>"><?= h($__mb['icon']) ?> <?= h($__mb['label']) ?></span>
        <?php endforeach; ?>
        <?php if ($__miaBadges['next']): $__mn = $__miaBadges['next']; ?>
          <span class="mw-head-badge is-next" title="Next badge — <?= h($__mn['title']) ?>">
            <?= h($__mn['icon']) ?> <?= h($__mn['label']) ?> <small><?= (int)$__mn['have'] ?>/<?= (int)$__mn['need'] ?></small>
            <i style="width: <?= (int)round($__mn['have'] / max(1, $__mn['need']) * 100) ?>%"></i>
          </span>
        <?php endif; ?>
      </div>
    </div>
  </div>

  <div class="mw-head-say mw-mia-say" id="mw-mia-say"><?= h(MiaDeskService::headline($__ms, $__miaName)) ?></div>

  <div class="mw-mia-stats">
    <div class="mw-mia-stat">
      <div class="mw-k">To send</div>
      <div class="mw-v"><?= (int)$__ms['open'] ?></div>
      <div class="mw-n"><?= (int)$__ms['sent_30'] ?> sent · <?= (int)$__ms['skipped_30'] ?> skipped (30 days)</div>
    </div>
    <div class="mw-mia-stat is-money">
      <div class="mw-k">Came back</div>
      <div class="mw-v"><?= (int)$__ms['won'] ?></div>
      <div class="mw-n">Booked or asked for a quote within 30 days<?= $__ms['replies'] ? ' · ' . (int)$__ms['replies'] . ' replied' : '' ?></div>
    </div>
    <div class="mw-mia-stat">
      <div class="mw-k">Reviews</div>
      <div class="mw-v"><?= (int)$__ms['reviewed'] ?></div>
      <div class="mw-n"><?= (int)$__ms['reviews_asked_90'] ?> asked automatically (90 days)</div>
    </div>
    <div class="mw-mia-stat">
      <div class="mw-k">May email</div>
      <div class="mw-v"><?= (int)$__ms['consent_ok'] ?></div>
      <div class="mw-n"><?= (int)$__ms['consent_express'] ?> said yes · the rest from a job or paid invoice (2 years)</div>
    </div>
    <div class="mw-mia-stat">
      <div class="mw-k">Referrals</div>
      <div class="mw-v"><?= (int)$__ms['referrals_in'] ?></div>
      <div class="mw-n"><?= (int)$__ms['referrals_open'] ?> waiting on a first visit</div>
    </div>
  </div>

  <div class="mw-mia-q" id="mw-mia-q" hidden></div>

  <div class="mw-mia-camp" id="mw-mia-camp" hidden></div>

  <div class="mw-mia-rc" id="mw-mia-rc" aria-live="polite" data-name="<?= h($__miaName) ?>">
    <div class="mw-mia-empty">Loading Mia's suggestions…</div>
  </div>

  <div class="mw-mia-chan" id="mw-mia-chan" aria-live="polite" hidden></div>

  <div class="mw-head-foot">
    <span>Learning from what you send, change and skip · I never send on my own</span>
  </div>
</section>
<?php if (!defined('MW_HEAD_BRAIN_JS')): define('MW_HEAD_BRAIN_JS', 1); ?>
<script src="<?= h($__js('/crm/js/head-brain.js')) ?>" defer></script>
<?php endif; ?>
<script src="<?= h($__js('/crm/js/mia-card.js')) ?>" defer></script>
<script src="<?= h($__js('/crm/js/mia-channels.js')) ?>" defer></script>

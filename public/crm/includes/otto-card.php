<?php
/**
 * Otto — Operations head card on the dashboard deck (included by dept-heads-deck.php).
 *
 * Today's crews and stops, weather that changes the plan, GPS and timesheet gaps.
 * Suggestions load from /crm/api/otto.php (public/crm/js/otto-card.js); every change is
 * the owner's click. Shown only to users with jobs.edit, and only once migrations
 * 1150–1152 have run. Never breaks the dashboard: any failure renders nothing.
 */
if (!function_exists('userHasPermission') || !userHasPermission('jobs.edit')) {
    return;
}
try {
    require_once APP_ROOT . '/Modules/Operations/Services/OpsDeskService.php';
    $__od = new OpsDeskService(getDB());
    if (!$__od->ready()) {
        return;
    }
    $__oItems = $__od->current(false);
    $__os = $__od->stats($__oItems);
    $__oName = '';
    $__ou = (array)($user ?? (function_exists('getCurrentUser') ? getCurrentUser() : []) ?? []);
    $__oName = trim((string)($__ou['first_name'] ?? '')) ?: (string)strtok(trim((string)($__ou['full_name'] ?? '')), ' ');
    $__oSay = OttoRules::headline([
        'stops' => $__os['today']['stops'], 'done' => $__os['today']['done'], 'crews' => count($__os['today']['crews']),
        'silent' => $__os['silent'], 'weather' => $__os['weather'], 'gaps' => $__os['gaps'], 'dispatch' => $__os['dispatch'] ?? 0,
    ], $__oName);
    $__oBadges = ['earned' => [], 'next' => null];
    try {
        require_once APP_ROOT . '/Modules/Operations/Services/OttoBadgeService.php';
        $__oBadges = (new OttoBadgeService(getDB()))->badges();
    } catch (Throwable $__e) { /* badges are a bonus */ }
    $__oBrain = null;
    try {
        require_once APP_ROOT . '/Modules/Operations/Services/OttoBrainService.php';
        $__oBrain = (new OttoBrainService(getDB()))->learned($__os['right_first_time']);
    } catch (Throwable $__e) { /* the brain is a bonus */ }
} catch (Throwable $__e) {
    error_log('Otto card unavailable: ' . $__e->getMessage());
    return;
}
$__oT = $__os['today'];
$__oPct = $__oT['stops'] > 0 ? min(100, (int)round($__oT['done'] / $__oT['stops'] * 100)) : 0;
$__oCrewLine = implode(' · ', array_map(fn($c) => $c['name'] . ' ' . $c['done'] . '/' . $c['stops'], array_slice($__oT['crews'], 0, 4)));
?>
<section class="mw-head-card mw-otto" id="mw-otto">
  <div class="mw-otto-top">
    <img class="mw-otto-face" src="/crm/img/heads/otto.jpg" alt="Otto, dispatcher" width="72" height="72">
    <div class="mw-otto-who">
      <div class="mw-head-nm">Otto</div>
      <div class="mw-head-role">Dispatcher · crews, weather, bylaws &amp; equipment</div>
      <span class="mw-head-pill"><i></i>Working</span>
    </div>
    <?php if ($__oBrain !== null): ?>
      <button type="button" class="mw-head-brain mw-otto-brain" aria-label="Otto's brain: <?= h(OttoRules::plural((int)$__oBrain['units'], 'thing')) ?> learned"
              data-head="Otto"
              data-units="<?= (int)$__oBrain['units'] ?>"
              data-bright="<?= h(number_format((float)$__oBrain['bright'], 2, '.', '')) ?>"
              data-parts="<?= h(json_encode($__oBrain['parts'])) ?>"
              data-since="<?= h(!empty($__oBrain['since']) ? date('M j, Y', strtotime($__oBrain['since'])) : '') ?>"
              data-empty="Nothing learned yet. Every move, keep and time you set teaches me."
              data-teach="It glows brighter the more often you keep my call"><canvas></canvas></button>
    <?php endif; ?>
  </div>

  <?php if ($__oBadges['earned'] || $__oBadges['next']): ?>
  <div class="mw-head-badges mw-otto-badges" aria-label="Otto's badges">
    <?php foreach ($__oBadges['earned'] as $__b): ?>
      <span class="mw-head-badge" title="<?= h($__b['title']) ?>"><?= h($__b['icon']) ?> <?= h($__b['label']) ?></span>
    <?php endforeach; ?>
    <?php if ($__oBadges['next']): $__n = $__oBadges['next']; ?>
      <span class="mw-head-badge is-next" title="Next badge — <?= h($__n['title']) ?>">
        <?= h($__n['icon']) ?> <?= h($__n['label']) ?> <small><?= (int)$__n['have'] ?>/<?= (int)$__n['need'] ?></small>
        <i style="width: <?= (int)round($__n['have'] / max(1, $__n['need']) * 100) ?>%"></i>
      </span>
    <?php endif; ?>
  </div>
  <?php endif; ?>

  <div class="mw-otto-say"><?= h($__oSay) ?></div>

  <div class="mw-otto-stats">
    <div class="mw-otto-stat">
      <div class="mw-k">Today</div>
      <div class="mw-v"><?= (int)$__oT['done'] ?><small>/<?= (int)$__oT['stops'] ?> stops</small></div>
      <div class="mw-head-bar"><span style="width: <?= $__oPct ?>%"></span></div>
      <div class="mw-n"><?= $__oCrewLine !== '' ? h($__oCrewLine) : 'No crews on the schedule' ?><?= $__oT['unassigned'] > 0 ? ' · <b>' . (int)$__oT['unassigned'] . ' with no crew</b>' : '' ?></div>
    </div>
    <div class="mw-otto-stat">
      <div class="mw-k">On the clock</div>
      <div class="mw-v"><?= (int)$__oT['on_clock'] ?></div>
      <div class="mw-n"><?= $__os['silent'] ? h(OttoRules::plural(count($__os['silent']), 'phone') . ' quiet') : 'Every phone reporting' ?></div>
    </div>
    <div class="mw-otto-stat<?= $__os['weather'] > 0 ? ' is-alert' : '' ?>">
      <div class="mw-k">Weather</div>
      <div class="mw-v"><?= (int)$__os['weather'] ?> <small>in doubt</small></div>
      <div class="mw-n">Today and tomorrow</div>
    </div>
    <div class="mw-otto-stat<?= $__os['gaps'] > 0 ? ' is-alert' : '' ?>">
      <div class="mw-k">Time gaps</div>
      <div class="mw-v"><?= (int)$__os['gaps'] ?></div>
      <div class="mw-n">Last <?= OttoRules::GAP_DAYS ?> days</div>
    </div>
    <div class="mw-otto-stat<?= ($__os['dispatch'] ?? 0) > 0 ? ' is-alert' : '' ?>">
      <div class="mw-k">Bylaws &amp; kit</div>
      <div class="mw-v"><?= (int)($__os['dispatch'] ?? 0) ?></div>
      <div class="mw-n"><a href="/crm/ops/municipal-rules.php">Rules</a> · <a href="/crm/ops/equipment.php">Equipment</a></div>
    </div>
    <div class="mw-otto-stat">
      <div class="mw-k">Right first time</div>
      <div class="mw-v"><?= $__os['right_first_time'] === null ? '—' : (int)$__os['right_first_time'] . '%' ?></div>
      <div class="mw-n">Calls you kept as I made them</div>
    </div>
  </div>

  <?php if (!empty($__os['outlook'])): ?>
    <div class="mw-otto-outlook">❄ <?= h($__os['outlook']) ?></div>
  <?php endif; ?>

  <div class="mw-otto-qs" id="mw-otto-qs" hidden></div>
  <div class="mw-otto-list" id="mw-otto-list" aria-live="polite">
    <div class="mw-otto-empty">Loading suggestions…</div>
  </div>

  <div class="mw-head-foot mw-otto-foot">
    <span>Learning from every call you make: rain by service · visit lengths · whose phone goes quiet · real truck km</span>
    <a class="btn btn-sm btn-success" href="/crm/jobs/schedule.php">Schedule →</a>
  </div>
</section>
<script src="<?= function_exists('_av') ? _av('/crm/js/head-brain.js') : '/crm/js/head-brain.js' ?>" defer></script>
<script src="<?= function_exists('_av') ? _av('/crm/js/otto-card.js') : '/crm/js/otto-card.js' ?>" defer></script>

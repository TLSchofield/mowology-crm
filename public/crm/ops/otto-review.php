<?php
/**
 * Otto's review — two views over the same evidence Otto's card uses:
 *
 *   ?view=unscheduled  Days in the last 14 the truck and / or crew stayed at a client property with
 *                      nothing scheduled, with every source shown (truck stops, each person's phone,
 *                      clock punches, timers) and what was ignored and why. Acting on one uses the
 *                      same buttons as the card (otto-card.js → /crm/api/otto.php mode=decide).
 *   ?view=durations    Real lawn-cut lengths against the plan: samples, crew- and person-minute
 *                      medians, what was dropped, the proposal; Apply / Apply all confident / Undo
 *                      (/crm/api/otto-durations.php). Plus timer coverage.
 *
 * jobs.edit only. Logic lives in UnscheduledWorkService / VisitDurationService.
 */
require_once dirname(__DIR__) . '/../loginAuth/auth.php';
require_once dirname(__DIR__) . '/includes/functions.php';
requireLogin();
$user = getCurrentUser();
if (!userHasPermission('jobs.edit')) {
    http_response_code(403);
    exit('You need permission to run the schedule (jobs.edit) to see this page.');
}

$view = ($_GET['view'] ?? 'unscheduled') === 'durations' ? 'durations' : 'unscheduled';
$pageTitle = $view === 'durations' ? 'Visit lengths' : 'Unscheduled work';
$activePage = 'schedule';

require_once APP_ROOT . '/Modules/Operations/Services/UnscheduledWorkService.php';
require_once APP_ROOT . '/Modules/Operations/Services/VisitDurationService.php';
$db = getDB();
$unsched = ['days' => [], 'flagged' => 0];
$durations = [];
$coverage = null;
$history = [];
$ready = true;
try {
    if ($view === 'unscheduled') {
        $svc = new UnscheduledWorkService($db);
        $ready = $svc->cacheReady();
        if ($ready) {
            $unsched = $svc->review(date('Y-m-d', strtotime('-' . UnscheduledWorkRules::LOOKBACK_DAYS . ' days')), date('Y-m-d', strtotime('-1 day')), true, true);
        }
    } else {
        $dsvc = new VisitDurationService($db);
        $ready = $dsvc->ready();
        $durations = $dsvc->review(!empty($_GET['all']));
        $coverage = $dsvc->coverage();
        $history = $ready ? $dsvc->history(20) : [];
    }
} catch (Throwable $e) {
    error_log('Otto review page: ' . $e->getMessage());
}
$flagged = [];
$ignored = [];
foreach ($unsched['days'] as $d) {
    foreach ($d['candidates'] as $c) {
        if ($c['flag']) $flagged[] = $c + ['sources' => $d['sources']];
        else $ignored[] = $c;
    }
}
usort($flagged, fn($a, $b) => [$b['date'], $a['start']] <=> [$a['date'], $b['start']]);
$ignoredWhy = ['scheduled' => 'had a visit that day', 'too_short' => 'too short', 'not_work' => 'you said not work (twice)'];
$confident = array_values(array_filter($durations, fn($r) => $r['proposed'] !== null && $r['confident']));
$hm = fn($t) => date('g:i', (int)$t);
?>
<?php include dirname(__DIR__) . '/includes/appstack_head.php'; ?>

<div class="mw-ops-page mw-or" data-view="<?= h($view) ?>">
  <div class="mw-ops-head">
    <img src="/crm/img/heads/otto.jpg" alt="" width="56" height="56">
    <div>
      <h1 class="h3 mb-1"><?= h($pageTitle) ?></h1>
      <p class="text-muted mb-0">
        <?php if ($view === 'unscheduled'): ?>
          Where the truck stopped (or the crew's phones stayed) at a client property with nothing on the schedule. A truck stop of
          <?= (int)UnscheduledWorkRules::MIN_TRUCK_MIN ?> min or more is enough; phones and clock punches back it up. Dumps, suppliers, the office and crew homes never count.
        <?php else: ?>
          What each recurring lawn plan really takes, from the job timers (last <?= (int)VisitDurationRules::SAMPLES ?> timed visits), against the length on the plan.
          The day's capacity reads the plan length, so updating it here is how the schedule learns. Nothing changes until you click.
        <?php endif; ?>
      </p>
    </div>
  </div>

  <nav class="mw-or-tabs" aria-label="Review">
    <a href="?view=unscheduled" class="<?= $view === 'unscheduled' ? 'is-on' : '' ?>">Unscheduled work</a>
    <a href="?view=durations" class="<?= $view === 'durations' ? 'is-on' : '' ?>">Visit lengths</a>
  </nav>

  <?php if (!$ready): ?>
    <div class="alert alert-warning">Run migration <?= $view === 'unscheduled' ? '1265' : '1266' ?> first.</div>
  <?php endif; ?>

  <?php if ($view === 'unscheduled'): ?>

    <?php if ($ready && !$flagged): ?>
      <div class="card"><div class="card-body mw-or-empty">Nothing unscheduled in the last <?= (int)UnscheduledWorkRules::LOOKBACK_DAYS ?> days.</div></div>
    <?php endif; ?>

    <?php foreach ($flagged as $c): $street = UnscheduledWorkService::street($c['address']); ?>
      <div class="card mw-or-case" data-key="<?= h('otto:unsched:' . $c['property_id'] . ':' . $c['date']) ?>">
        <div class="card-body">
          <div class="mw-or-case-hd">
            <div>
              <div class="mw-or-when"><?= h(date('D M j', strtotime($c['date']))) ?> · <?= h($hm($c['start']) . '–' . $hm($c['end'])) ?> · <?= h(UnscheduledWorkRules::hours($c['minutes'])) ?></div>
              <div class="mw-or-where"><a href="/crm/properties/view.php?id=<?= (int)$c['property_id'] ?>"><?= h($street) ?></a><?= $c['client'] !== '' ? ' · ' . h($c['client']) : '' ?></div>
            </div>
            <span class="mw-or-conf is-<?= h($c['confidence']) ?>"><?= h(['truck+crew' => 'Truck + crew', 'truck' => 'Truck', 'crew' => 'Crew phones'][$c['basis']] ?? $c['basis']) ?> · <?= h($c['confidence']) ?></span>
          </div>
          <ul class="mw-or-ev">
            <?php foreach ($c['truck_stops'] as $s): ?>
              <li class="is-truck">Truck stopped <?= h($hm($s['from']) . '–' . $hm($s['to'])) ?> (<?= (int)round($s['minutes']) ?> min)</li>
            <?php endforeach; ?>
            <?php if (!$c['truck_stops']): ?><li class="is-none">No truck stop here<?= (int)($c['sources']['truck_pings'] ?? 0) === 0 ? ' — the truck sent nothing that day' : '' ?></li><?php endif; ?>
            <?php foreach ($c['crew'] as $d): $p = null; foreach ($c['crew_people'] as $cp) if ($cp['id'] === $d['user_id']) $p = $cp; ?>
              <li class="is-crew"><?= h(($p['name'] ?? '#' . $d['user_id']) . (!empty($p['truck']) ? ' (truck tablet)' : '')) ?> here <?= h($hm($d['from']) . '–' . $hm($d['to'])) ?>
                — <?= h(implode(', ', array_map(fn($k, $n) => $n . ' ' . (['phone' => ['phone fix', 'phone fixes'], 'clock' => ['clock punch', 'clock punches'], 'timer' => ['timer start/stop', 'timer starts/stops']][$k] ?? [$k, $k])[$n === 1 ? 0 : 1], array_keys($d['sources']), $d['sources']))) ?></li>
            <?php endforeach; ?>
            <?php foreach ($c['visits_near'] as $v): ?>
              <li class="is-near">Visit <?= h($v['plan']) ?> <?= h($v['status']) ?> on <?= h(date('D M j', strtotime($v['date']))) ?></li>
            <?php endforeach; ?>
            <?php foreach ($c['invoices'] as $inv): ?>
              <li class="is-inv">Invoice <?= h($inv['number']) ?> dated <?= h(date('M j', strtotime($inv['date']))) ?><?= $inv['total'] !== null ? ' · $' . h(number_format($inv['total'], 2)) : '' ?> (<?= h($inv['status']) ?>) — may already cover this</li>
            <?php endforeach; ?>
          </ul>
          <div class="mw-or-act"><small class="text-muted">Loading Otto's buttons…</small></div>
        </div>
      </div>
    <?php endforeach; ?>

    <?php if ($ignored): ?>
      <details class="card mw-or-ignored"><summary class="card-body">Also seen, not flagged (<?= count($ignored) ?>)</summary>
        <ul>
          <?php foreach ($ignored as $c): ?>
            <li><?= h(date('D M j', strtotime($c['date']))) ?> · <?= h(UnscheduledWorkService::street($c['address'])) ?> · <?= h($hm($c['start']) . '–' . $hm($c['end'])) ?>
              — <?= h($ignoredWhy[$c['ignored']] ?? $c['ignored']) ?> <small class="text-muted"><?= h($c['evidence']) ?></small></li>
          <?php endforeach; ?>
        </ul>
      </details>
    <?php endif; ?>

  <?php else: ?>

    <div class="mw-or-cov">
      <div class="mw-otto-stat">
        <div class="mw-k">Timed visits</div>
        <div class="mw-v"><?= $coverage && $coverage['pct'] !== null ? (int)$coverage['pct'] . '%' : '—' ?></div>
        <div class="mw-n"><?= $coverage ? (int)$coverage['timed'] . ' of ' . (int)$coverage['completed'] . ' done in the last ' . (int)$coverage['days'] . ' days' : '' ?></div>
      </div>
      <div class="mw-otto-stat">
        <div class="mw-k">Time typed in</div>
        <div class="mw-v"><?= (int)($coverage['manual'] ?? 0) ?></div>
        <div class="mw-n">No timer, length entered by hand</div>
      </div>
      <div class="mw-otto-stat<?= ($coverage['untimed'] ?? 0) > 0 ? ' is-alert' : '' ?>">
        <div class="mw-k">No time at all</div>
        <div class="mw-v"><?= (int)($coverage['untimed'] ?? 0) ?></div>
        <div class="mw-n">Otto asks about these on the dashboard</div>
      </div>
      <div class="mw-otto-stat">
        <div class="mw-k">Proposals</div>
        <div class="mw-v"><?= count(array_filter($durations, fn($r) => $r['proposed'] !== null)) ?></div>
        <div class="mw-n"><?= count($confident) ?> confident</div>
      </div>
    </div>

    <?php if ($ready && $confident): ?>
      <div class="mw-or-bulk">
        <button type="button" class="btn btn-success btn-sm" data-or="apply_all">Apply all confident (<?= count($confident) ?>)</button>
        <small class="text-muted"><?= (int)VisitDurationRules::CONFIDENT_SAMPLES ?>+ timed visits and an even spread. One click undoes the lot.</small>
        <span class="mw-or-msg" hidden></span>
      </div>
    <?php endif; ?>

    <div class="card"><div class="card-body p-0">
      <div class="table-responsive">
        <table class="table table-sm mb-0 mw-or-table">
          <thead><tr><th>Property · plan</th><th class="text-end">Plan</th><th class="text-end">Real (on site)</th><th class="text-end">Person-min</th><th class="text-end">Timed</th><th>Spread</th><th></th></tr></thead>
          <tbody>
          <?php foreach ($durations as $r): ?>
            <tr id="plan-<?= (int)$r['plan_id'] ?>" class="<?= $r['proposed'] !== null ? 'is-prop' : '' ?>">
              <td><a href="/crm/jobs/view.php?id=<?= (int)$r['plan_id'] ?>"><?= h($r['street']) ?></a>
                <small class="d-block text-muted"><?= h($r['plan_number'] . ' · ' . $r['service_type']) ?><?= $r['detail'] !== '' ? ' · ' . h($r['detail']) : '' ?></small></td>
              <td class="text-end" data-planned><?= $r['planned'] !== null ? (int)$r['planned'] . ' min' : '—' ?></td>
              <td class="text-end"><b><?= $r['median_crew'] !== null ? (int)$r['median_crew'] . ' min' : '—' ?></b></td>
              <td class="text-end"><?= $r['median_person'] !== null ? (int)$r['median_person'] : '—' ?></td>
              <td class="text-end"><?= (int)$r['samples'] ?><small class="text-muted">/<?= (int)$r['completed'] ?></small></td>
              <td><?= $r['spread'] !== null ? (int)round($r['spread'] * 100) . '%' : '—' ?><?= $r['confident'] ? ' <span class="mw-or-ok">confident</span>' : '' ?></td>
              <td class="mw-or-btns">
                <?php if ($r['proposed'] !== null && $ready): ?>
                  <input type="number" min="5" max="600" step="5" value="<?= (int)$r['proposed'] ?>" aria-label="New length in minutes">
                  <button type="button" class="btn btn-sm btn-success" data-or="apply" data-plan="<?= (int)$r['plan_id'] ?>">Apply</button>
                  <span class="mw-or-msg" hidden></span>
                <?php elseif ($r['reason'] === 'close_enough'): ?>
                  <small class="text-muted">Plan is right</small>
                <?php elseif ($r['reason'] === 'too_few'): ?>
                  <small class="text-muted">Needs <?= (int)VisitDurationRules::MIN_TO_PROPOSE ?> timed visits</small>
                <?php endif; ?>
              </td>
            </tr>
          <?php endforeach; ?>
          <?php if (!$durations): ?><tr><td colspan="7" class="mw-or-empty">No recurring lawn plans found.</td></tr><?php endif; ?>
          </tbody>
        </table>
      </div>
    </div></div>

    <?php if ($history): ?>
      <div class="card"><div class="card-body">
        <h2 class="h6">Changes you made from here</h2>
        <ul class="mw-or-hist">
          <?php foreach ($history as $c): ?>
            <li><?= h(date('M j g:i a', strtotime($c['applied_at']))) ?> · <?= h(UnscheduledWorkService::street((string)$c['address'])) ?>:
              <?= $c['old_minutes'] !== null ? (int)$c['old_minutes'] : '—' ?> → <?= (int)$c['new_minutes'] ?> min
              <small class="text-muted">(median <?= (int)$c['median_crew'] ?> over <?= (int)$c['samples'] ?><?= $c['batch_key'] ? ', apply-all' : '' ?>)</small>
              <?php if (empty($c['undone_at'])): ?>
                <button type="button" class="btn btn-link btn-sm p-0" data-or="undo" data-change="<?= (int)$c['id'] ?>">Undo</button>
              <?php else: ?><small class="text-muted">undone</small><?php endif; ?>
              <span class="mw-or-msg" hidden></span></li>
          <?php endforeach; ?>
        </ul>
      </div></div>
    <?php endif; ?>

  <?php endif; ?>
</div>

<script src="<?= function_exists('_av') ? _av('/crm/js/otto-card.js') : '/crm/js/otto-card.js' ?>" defer></script>
<script src="<?= function_exists('_av') ? _av('/crm/js/otto-review.js') : '/crm/js/otto-review.js' ?>" defer></script>
<?php include dirname(__DIR__) . '/includes/appstack_footer.php'; ?>

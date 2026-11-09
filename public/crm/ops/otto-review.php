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
 *   ?view=borders      Pins and borders for every active client property (PropertyBorderService):
 *                      counts from what is stored, Otto's items with their buttons, and the default-
 *                      border dry run → create (/crm/api/otto-borders.php, otto-borders.js).
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

$view = in_array($_GET['view'] ?? '', ['durations', 'borders'], true) ? (string)$_GET['view'] : 'unscheduled';
$pageTitle = ['durations' => 'Visit lengths', 'borders' => 'Pins & borders', 'unscheduled' => 'Unscheduled work'][$view];
$activePage = 'schedule';

require_once APP_ROOT . '/Modules/Operations/Services/UnscheduledWorkService.php';
require_once APP_ROOT . '/Modules/Operations/Services/VisitDurationService.php';
$db = getDB();
$unsched = ['days' => [], 'flagged' => 0];
$durations = [];
$coverage = null;
$history = [];
$autoRows = [];
$borders = null;
$ready = true;
try {
    if ($view === 'borders') {
        require_once APP_ROOT . '/Modules/Operations/Services/PropertyBorderService.php';
        $borders = (new PropertyBorderService($db))->summary();
        $ready = $borders['ready'];
    } elseif ($view === 'unscheduled') {
        $svc = new UnscheduledWorkService($db);
        $ready = $svc->cacheReady();
        if ($ready) {
            $unsched = $svc->review(date('Y-m-d', strtotime('-' . UnscheduledWorkRules::LOOKBACK_DAYS . ' days')), date('Y-m-d', strtotime('-1 day')), true, true);
        }
        require_once APP_ROOT . '/Modules/Operations/Services/OttoContractLogService.php';
        $ocl = new OttoContractLogService($db, $svc);
        $autoRows = $ocl->recent(UnscheduledWorkRules::LOOKBACK_DAYS);
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
if ($view === 'borders' && !$borders) {
    require_once APP_ROOT . '/Modules/Operations/Services/PropertyBorderRules.php';
    $borders = PropertyBorderRules::audit([], [], [], []) + ['measured_at' => null, 'ready' => false];
    $ready = false;
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
$ignoredWhy = ['scheduled' => 'had a visit that day', 'too_short' => 'too short', 'not_work' => 'you said not work (twice)', 'logged_by_otto' => 'Otto logged it (contract)'];
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
          Where the truck stopped (or the crew's phones stayed) at a client property with nothing on the schedule — or far longer than the
          scheduled visit (<?= (int)UnscheduledWorkRules::EXTRA_MIN ?> min more and at least <?= (int)UnscheduledWorkRules::EXTRA_X ?>× its planned length). A truck stop of
          <?= (int)UnscheduledWorkRules::MIN_TRUCK_MIN ?> min or more is enough; phones and clock punches back it up. Dumps, suppliers, the office and crew homes never count.
        <?php elseif ($view === 'borders'): ?>
          Every client property with an active plan or a visit in the last 12 months needs a map pin and an arrival border, or crews
          can't be seen there and Otto can't tell which job they're on. A pin more than <?= (int)PropertyBorderRules::PIN_OFF_M ?> m from where the crews' phones
          actually were is probably on the wrong spot. Nothing changes until you click.
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
    <a href="?view=borders" class="<?= $view === 'borders' ? 'is-on' : '' ?>">Pins &amp; borders</a>
  </nav>

  <?php if (!$ready): ?>
    <div class="alert alert-warning">Run migration <?= ['unscheduled' => '1265', 'durations' => '1266', 'borders' => '1285'][$view] ?> first.</div>
  <?php endif; ?>

  <?php if ($view === 'borders'): ?>

    <div class="mw-or-cov mw-ob-counts">
      <div class="mw-otto-stat"><div class="mw-k">Active properties</div><div class="mw-v"><?= (int)$borders['active'] ?></div><div class="mw-n">Plan, or a visit in 12 months</div></div>
      <div class="mw-otto-stat<?= $borders['without_pin'] ? ' is-alert' : '' ?>"><div class="mw-k">No pin</div><div class="mw-v"><?= (int)$borders['without_pin'] ?></div><div class="mw-n"><a href="/crm/map_appstack.php?geocode=-1">Find missing pins</a></div></div>
      <div class="mw-otto-stat"><div class="mw-k">Drawn border</div><div class="mw-v"><?= (int)$borders['with_drawn_border'] ?></div><div class="mw-n">By a person</div></div>
      <div class="mw-otto-stat"><div class="mw-k">Default border</div><div class="mw-v"><?= (int)$borders['with_default_border'] ?></div><div class="mw-n">Otto's — draw them</div></div>
      <div class="mw-otto-stat<?= $borders['without_border'] ? ' is-alert' : '' ?>"><div class="mw-k">No border</div><div class="mw-v"><?= (int)$borders['without_border'] ?></div><div class="mw-n">150 m radius only</div></div>
      <div class="mw-otto-stat<?= $borders['pin_off'] ? ' is-alert' : '' ?>"><div class="mw-k">Pin off</div><div class="mw-v"><?= (int)$borders['pin_off'] ?></div><div class="mw-n"><?= $borders['measured_at'] ? 'Measured ' . h(date('M j', strtotime($borders['measured_at']))) : 'Not measured yet' ?></div></div>
      <div class="mw-otto-stat<?= $borders['overlaps'] ? ' is-alert' : '' ?>"><div class="mw-k">Overlaps</div><div class="mw-v"><?= (int)$borders['overlaps'] ?></div><div class="mw-n">Neighbours sharing ground</div></div>
    </div>

    <div class="card mw-ob-tools"><div class="card-body">
      <h2 class="h6">Default borders</h2>
      <p class="text-muted small mb-2">For each pinned property with no border: the outline of where crews' phones (and the parked truck) were during past timed visits,
        plus <?= (int)PropertyBorderRules::BUFFER_M ?> m, at most <?= (int)PropertyBorderRules::CAP_M ?> m from the middle — or, with fewer than <?= (int)PropertyBorderRules::MIN_FIXES ?> fixes over
        <?= (int)PropertyBorderRules::MIN_VISITS ?> visits, a ±<?= (int)PropertyBorderRules::SQUARE_HALF_M ?> m square round the pin marked "draw me". A drawn border is never touched, and drawing one replaces Otto's.</p>
      <div class="mw-ob-btns">
        <button type="button" class="btn btn-sm btn-outline-secondary" data-ob="preview"<?= $ready ? '' : ' disabled' ?>>Preview (dry run)</button>
        <button type="button" class="btn btn-sm btn-success" data-ob="apply" disabled>Create them</button>
        <button type="button" class="btn btn-sm btn-outline-secondary" data-ob="measure"<?= $ready ? '' : ' disabled' ?>>Re-measure where crews work</button>
        <span class="mw-or-msg" hidden></span>
      </div>
      <div class="mw-ob-preview" hidden></div>
    </div></div>

    <h2 class="h6 mt-3">Otto's items</h2>
    <div class="mw-otto-list" id="mw-ob-items"><div class="mw-otto-empty">Loading…</div></div>

    <?php if ($borders['pin_off_list']): ?>
      <details class="card mw-or-ignored"><summary class="card-body">Pins far from the work (<?= count($borders['pin_off_list']) ?>)</summary>
        <ul><?php foreach ($borders['pin_off_list'] as $x): ?>
          <li><a href="/crm/jobs/zone-editor.php?property_id=<?= (int)$x['id'] ?>"><?= h(PropertyBorderRules::street($x['address'])) ?></a> — <?= (int)$x['distance_m'] ?> m
            <small class="text-muted"><?= (int)$x['fixes'] ?> fixes, <?= (int)$x['visits'] ?> visits</small></li>
        <?php endforeach; ?></ul>
      </details>
    <?php endif; ?>
    <?php if ($borders['overlap_list']): ?>
      <details class="card mw-or-ignored"><summary class="card-body">Overlapping borders (<?= count($borders['overlap_list']) ?>)</summary>
        <ul><?php foreach ($borders['overlap_list'] as $x): ?>
          <li><a href="/crm/jobs/zone-editor.php?property_id=<?= (int)$x['a'] ?>"><?= h(PropertyBorderRules::street($x['a_address'])) ?></a> and
            <a href="/crm/jobs/zone-editor.php?property_id=<?= (int)$x['b'] ?>"><?= h(PropertyBorderRules::street($x['b_address'])) ?></a></li>
        <?php endforeach; ?></ul>
      </details>
    <?php endif; ?>

  <?php elseif ($view === 'unscheduled'): ?>

    <?php if ($ready && !$flagged): ?>
      <div class="card"><div class="card-body mw-or-empty">Nothing unscheduled in the last <?= (int)UnscheduledWorkRules::LOOKBACK_DAYS ?> days.</div></div>
    <?php endif; ?>

    <?php foreach ($flagged as $c): $street = UnscheduledWorkService::street($c['address']); ?>
      <?php $isExtra = ($c['kind'] ?? '') === 'extra'; ?>
      <div class="card mw-or-case" data-key="<?= h('otto:' . ($isExtra ? 'extra' : 'unsched') . ':' . $c['property_id'] . ':' . $c['date']) ?>">
        <div class="card-body">
          <div class="mw-or-case-hd">
            <div>
              <div class="mw-or-when"><?= h(date('D M j', strtotime($c['date']))) ?> · <?= h($hm($c['start']) . '–' . $hm($c['end'])) ?> · <?= h(UnscheduledWorkRules::hours($c['minutes'])) ?></div>
              <div class="mw-or-where"><a href="/crm/properties/view.php?id=<?= (int)$c['property_id'] ?>"><?= h($street) ?></a><?= $c['client'] !== '' ? ' · ' . h($c['client']) : '' ?></div>
            </div>
            <div><?php if ($isExtra): ?><span class="mw-or-conf is-extra">Beyond the scheduled visit · <?= h(UnscheduledWorkRules::hours((int)$c['extra_min'])) ?> extra</span><?php endif; ?>
            <span class="mw-or-conf is-<?= h($c['confidence']) ?>"><?= h(['truck+crew' => 'Truck + crew', 'truck' => 'Truck', 'crew' => 'Crew phones'][$c['basis']] ?? $c['basis']) ?> · <?= h($c['confidence']) ?></span></div>
          </div>
          <p class="mw-or-say"><?= h(UnscheduledWorkRules::text($c, $street, $c['site_names'] ?? [])) ?></p>
          <?php if (!empty($c['contract'])): ?>
            <p class="mw-or-ctr<?= $c['contract']['log'] ? ' is-auto' : '' ?>">Contract <?= h($c['contract']['contract']['number']) ?>:
              <?= $c['contract']['log'] ? 'Otto logs this one himself on the next pass (' . h($c['contract']['plan']['number'] ?? '') . ').' : h($c['contract']['reason']) ?></p>
          <?php endif; ?>
          <ul class="mw-or-ev">
            <?php foreach ($c['explained'] ?? [] as $x): if ((int)$x['property_id'] === (int)$c['property_id']) continue; ?>
              <li class="is-sched">Explained: <?= h(($c['site_names'][$x['property_id']]['street'] ?? '#' . $x['property_id']) . ' — ' . $x['plan_number'] . ' ' . $x['service_type']) ?>
                (<?= h($x['status']) ?>) <?= h($hm($x['from']) . '–' . $hm($x['to'])) ?>, ~<?= (int)$x['minutes'] ?> min by its <?= $x['basis'] === 'timer' ? 'timer' : 'plan length' ?></li>
            <?php endforeach; ?>
            <?php foreach ($c['truck_stops'] as $s): ?>
              <li class="is-truck">Truck stopped <?= h($hm($s['from']) . '–' . $hm($s['to'])) ?> (<?= (int)round($s['minutes']) ?> min)</li>
            <?php endforeach; ?>
            <?php if (!$c['truck_stops']): ?><li class="is-none">No truck stop here<?= (int)($c['sources']['truck_pings'] ?? 0) === 0 ? ' — the truck sent nothing that day' : '' ?></li><?php endif; ?>
            <?php foreach ($c['crew'] as $d): $p = null; foreach ($c['crew_people'] as $cp) if ($cp['id'] === $d['user_id']) $p = $cp; ?>
              <li class="is-crew"><?= h(($p['name'] ?? '#' . $d['user_id']) . (!empty($p['truck']) ? ' (truck tablet)' : '')) ?> here <?= h($hm($d['from']) . '–' . $hm($d['to'])) ?>
                — <?= h(implode(', ', array_map(fn($k, $n) => $n . ' ' . (['phone' => ['phone fix', 'phone fixes'], 'clock' => ['clock punch', 'clock punches'], 'timer' => ['timer start/stop', 'timer starts/stops']][$k] ?? [$k, $k])[$n === 1 ? 0 : 1], array_keys($d['sources']), $d['sources']))) ?></li>
            <?php endforeach; ?>
            <?php foreach ($c['scheduled_visits'] as $v): ?>
              <li class="is-sched">Scheduled: <?= $v['visit_id'] ? '<a href="/crm/jobs/visit-detail.php?id=' . (int)$v['visit_id'] . '">' . h($v['plan_number'] . ' ' . $v['service_type']) . '</a>' : h($v['status']) ?>
                — <?= h($v['status']) ?>, plan <?= $v['planned_min'] !== null ? (int)$v['planned_min'] . ' min' : 'no length' ?>, timer <?= $v['timer_min'] !== null ? (int)$v['timer_min'] . ' min' : 'none' ?></li>
            <?php endforeach; ?>
            <?php foreach ($c['visits_near'] as $v): if ($c['scheduled_visits'] && $v['date'] === $c['date']) continue; ?>
              <li class="is-near">Visit <?= h($v['plan']) ?> <?= h($v['status']) ?> on <?= h(date('D M j', strtotime($v['date']))) ?></li>
            <?php endforeach; ?>
            <?php foreach ($c['stray_timers'] ?? [] as $t): ?>
              <li class="is-sched"><?= h(UnscheduledWorkRules::strayTimerLine($t)) ?></li>
            <?php endforeach; ?>
            <?php foreach ($c['empty_stops'] ?? [] as $st): ?>
              <li class="is-near"><?= h(ucfirst(UnscheduledWorkRules::emptyStopLine($st))) ?></li>
            <?php endforeach; ?>
            <?php foreach ($c['invoices'] as $inv): ?>
              <li class="is-inv">Invoice <?= h($inv['number']) ?> dated <?= h(date('M j', strtotime($inv['date']))) ?><?= $inv['total'] !== null ? ' · $' . h(number_format($inv['total'], 2)) : '' ?> (<?= h($inv['status']) ?>) — may already cover this</li>
            <?php endforeach; ?>
          </ul>
          <div class="mw-or-act"><small class="text-muted">Loading Otto's buttons…</small></div>
        </div>
      </div>
    <?php endforeach; ?>

    <?php if ($autoRows): ?>
      <div class="card mw-or-auto" id="auto"><div class="card-body">
        <h2 class="h6">Logged by Otto at contract sites</h2>
        <p class="text-muted small mb-2">Covered by the contract, so never invoiced per visit and never sent to the client. Undo cancels the visit and Otto asks about that day instead.</p>
        <ul class="mw-or-hist">
          <?php foreach ($autoRows as $a): ?>
            <li><?= h(date('D M j', strtotime($a['day']))) ?> · <?= h(UnscheduledWorkService::street((string)$a['address'])) ?> ·
              <?php if (($a['kind'] ?? '') === 'move'): ?>
                visit #<?= (int)$a['visit_id'] ?> moved here from <?= h(date('D M j', strtotime((string)$a['moved_from']))) ?> (timed this day)
              <?php else: ?>
              <?= h(substr((string)$a['start_time'], 0, 5) . '–' . substr((string)$a['end_time'], 0, 5)) ?> (<?= h(UnscheduledWorkRules::hours((int)$a['minutes'])) ?>)
              <?php endif; ?>
              · <?= h((string)$a['contract_number']) ?> <?= h((string)$a['plan_number']) ?><?= $a['invoice_number'] ? ' · on ' . h($a['invoice_number']) : '' ?>
              <?php if ($a['visit_id']): ?> · <a href="/crm/jobs/visit-detail.php?id=<?= (int)$a['visit_id'] ?>">visit</a><?php endif; ?>
              <?php if ($a['status'] === 'logged'): ?>
                <button type="button" class="btn btn-link btn-sm p-0" data-or="undo_auto" data-id="<?= (int)$a['id'] ?>">Undo</button>
              <?php else: ?><small class="text-muted"><?= h($a['status']) ?><?= $a['reason'] ? ' — ' . h($a['reason']) : '' ?></small><?php endif; ?>
              <span class="mw-or-msg" hidden></span></li>
          <?php endforeach; ?>
        </ul>
      </div></div>
    <?php endif; ?>

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
<?php if ($view === 'borders'): ?>
<script src="<?= function_exists('_av') ? _av('/crm/js/otto-borders.js') : '/crm/js/otto-borders.js' ?>" defer></script>
<?php endif; ?>
<?php include dirname(__DIR__) . '/includes/appstack_footer.php'; ?>

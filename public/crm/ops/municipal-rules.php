<?php
/**
 * Bylaw rules — Otto the Dispatcher's rule table.
 *
 * When power equipment and leaf blowers may run, per municipality (matched to the
 * property's city) and per area (by postal prefix). Which service types use a blower.
 * BC statutory holidays (computed). The open green-waste question.
 * Rules marked "unverified" only give Otto low-priority suggestions that say so.
 * jobs.edit only. Saves go to /crm/api/dispatch.php.
 */
require_once dirname(__DIR__) . '/../loginAuth/auth.php';
require_once dirname(__DIR__) . '/includes/functions.php';
requireLogin();
$user = getCurrentUser();
if (!userHasPermission('jobs.edit')) {
    http_response_code(403);
    exit('You need permission to run the schedule (jobs.edit) to see this page.');
}

$pageTitle = 'Bylaw Rules';
$activePage = 'schedule';

require_once APP_ROOT . '/Modules/Operations/Services/MunicipalRuleService.php';
$svc = new MunicipalRuleService(getDB());
$ready = $svc->ready();
$rules = $areas = $types = $cities = [];
$greenWaste = '';
if ($ready) {
    try {
        $rules = $svc->rules();
        $areas = $svc->areas();
        $types = $svc->serviceTypes();
        $cities = $svc->cities();
        $s = getDB()->prepare("SELECT setting_value FROM ops_settings WHERE setting_key = 'otto_green_waste'");
        $s->execute();
        $greenWaste = (string)$s->fetchColumn();
    } catch (Throwable $e) {
        error_log('Bylaw rules page: ' . $e->getMessage());
    }
}
$training = null;
$courses = [];
$reqs = [];
try {
    require_once APP_ROOT . '/Modules/Operations/Services/TrainingService.php';
    $training = new TrainingService(getDB());
    if ($training->ready()) {
        $courses = $training->courses();
        $reqs = $training->requirements();
    } else {
        $training = null;
    }
} catch (Throwable $e) {
    $training = null;
}
$year = (int)date('Y');
$holidays = DispatchRules::bcHolidays($year);
$covered = array_unique(array_map(fn($r) => DispatchRules::normalizeCity($r['municipality']), $rules));
$sel = function (string $name, array $opts, ?string $val): string {
    $o = '';
    foreach ($opts as $k => $label) {
        $k = is_int($k) ? $label : $k;
        $o .= '<option value="' . h($k) . '"' . ((string)$val === (string)$k ? ' selected' : '') . '>' . h($label) . '</option>';
    }
    return '<select name="' . h($name) . '" class="form-control form-control-sm">' . $o . '</select>';
};
$statusLabels = ['unverified' => 'Unverified', 'verified' => 'Verified', 'confirm_current_bylaw' => 'Confirm current bylaw'];
$dayLabels = ['weekday' => 'Mon–Fri', 'saturday' => 'Saturday', 'sunday_holiday' => 'Sun / holiday', 'any' => 'Any day'];
$classLabels = ['power_equipment' => 'Power equipment', 'leaf_blower' => 'Leaf blower', 'all' => 'All'];
$ruleRow = function (array $r) use ($sel, $statusLabels, $dayLabels, $classLabels): string {
    ob_start(); ?>
    <tr data-row data-id="<?= (int)($r['id'] ?? 0) ?>" class="mw-ops-status-<?= h($r['status'] ?? 'unverified') ?>">
      <td><input name="municipality" class="form-control form-control-sm" value="<?= h($r['municipality'] ?? '') ?>" placeholder="City"></td>
      <td><input name="area" class="form-control form-control-sm" value="<?= h($r['area'] ?? '') ?>" placeholder="Whole city"></td>
      <td><?= $sel('kind', ['hours' => 'Hours', 'ban' => 'Ban', 'note' => 'Note only'], $r['kind'] ?? 'hours') ?></td>
      <td><?= $sel('equipment_class', $classLabels, $r['equipment_class'] ?? 'power_equipment') ?></td>
      <td><?= $sel('power_source', ['any' => 'Any', 'gas' => 'Gas only'], $r['power_source'] ?? 'any') ?></td>
      <td><?= $sel('day_type', $dayLabels, $r['day_type'] ?? 'weekday') ?></td>
      <td class="mw-ops-time"><input type="time" name="allowed_start" class="form-control form-control-sm" value="<?= h(substr((string)($r['allowed_start'] ?? ''), 0, 5)) ?>"></td>
      <td class="mw-ops-time"><input type="time" name="allowed_end" class="form-control form-control-sm" value="<?= h(substr((string)($r['allowed_end'] ?? ''), 0, 5)) ?>"></td>
      <td><?= $sel('status', $statusLabels, $r['status'] ?? 'unverified') ?></td>
      <td class="mw-ops-note">
        <input name="note" class="form-control form-control-sm" value="<?= h($r['note'] ?? '') ?>" placeholder="Note">
        <input name="source_url" class="form-control form-control-sm mt-1" value="<?= h($r['source_url'] ?? '') ?>" placeholder="Source link">
        <input type="hidden" name="near_homes_m" value="<?= h((string)($r['near_homes_m'] ?? '')) ?>">
        <?php if (!empty($r['source_url'])): ?><a href="<?= h($r['source_url']) ?>" target="_blank" rel="noopener" class="mw-ops-src">Open source</a><?php endif; ?>
      </td>
      <td class="text-nowrap">
        <button class="btn btn-sm btn-success" data-save="save_rule"><?= empty($r['id']) ? 'Add' : 'Save' ?></button>
        <?php if (!empty($r['id'])): ?><button class="btn btn-sm btn-outline-secondary" data-save="delete_rule" data-confirm="Remove this rule?">✕</button><?php endif; ?>
      </td>
    </tr>
    <?php return (string)ob_get_clean();
};
?>
<?php include dirname(__DIR__) . '/includes/appstack_head.php'; ?>

<div class="mw-ops-page">
  <div class="mw-ops-head">
    <img src="/crm/img/heads/otto.jpg" alt="" width="56" height="56">
    <div>
      <h1 class="h3 mb-1">Bylaw rules</h1>
      <p class="text-muted mb-0">When equipment may run, by city. I check every scheduled visit for the next 7 days against these.
        Rows marked <b>unverified</b> only give low-priority suggestions until you confirm them against the bylaw.</p>
    </div>
  </div>

  <?php if (!$ready): ?>
    <div class="alert alert-warning">Run migrations 1153 and 1154 first.</div>
  <?php else: ?>

  <div class="card"><div class="card-body">
    <h2 class="h5">Where your properties are</h2>
    <div class="mw-ops-chips">
      <?php foreach ($cities as $c): $has = in_array(DispatchRules::normalizeCity($c['city']), $covered, true); ?>
        <span class="mw-ops-chip<?= $has ? ' is-covered' : '' ?>" title="<?= $has ? 'Has rules' : 'No rules yet' ?>"><?= h($c['city']) ?> <b><?= (int)$c['n'] ?></b></span>
      <?php endforeach; ?>
    </div>
    <p class="mw-ops-hint">Green = has rules. A blank city reads as Vancouver on older properties (the database default), so check those addresses.</p>
  </div></div>

  <div class="card"><div class="card-body">
    <h2 class="h5">Rules</h2>
    <div class="table-responsive">
      <table class="table table-sm mw-ops-table">
        <thead><tr><th>Municipality</th><th>Area</th><th>Kind</th><th>Equipment</th><th>Power</th><th>Days</th><th>From</th><th>To</th><th>Status</th><th>Note / source</th><th></th></tr></thead>
        <tbody>
          <?php foreach ($rules as $r) echo $ruleRow($r); ?>
          <?= $ruleRow(['municipality' => '', 'kind' => 'hours', 'status' => 'unverified', 'day_type' => 'weekday']) ?>
        </tbody>
      </table>
    </div>
    <p class="mw-ops-hint">Setting a row to Verified records you as the person who confirmed it. "Note only" rows are shown, never enforced.</p>
  </div></div>

  <div class="row">
    <div class="col-lg-6">
      <div class="card"><div class="card-body">
        <h2 class="h5">Areas inside a city</h2>
        <p class="mw-ops-hint">Matched by the first three characters of the postal code. A rule with an area only applies there.</p>
        <table class="table table-sm mw-ops-table">
          <thead><tr><th>Area</th><th>City</th><th>Postal prefixes</th><th>Status</th><th></th></tr></thead>
          <tbody>
          <?php foreach (array_merge($areas, [['name' => '', 'municipality' => '', 'fsa_prefixes' => '', 'status' => 'unverified', 'note' => '']]) as $a): ?>
            <tr data-row>
              <td><input name="name" class="form-control form-control-sm" value="<?= h($a['name']) ?>" placeholder="West End"></td>
              <td><input name="municipality" class="form-control form-control-sm" value="<?= h($a['municipality']) ?>"></td>
              <td><input name="fsa_prefixes" class="form-control form-control-sm" value="<?= h($a['fsa_prefixes']) ?>" placeholder="V6E,V6G">
                  <input type="hidden" name="note" value="<?= h($a['note'] ?? '') ?>"></td>
              <td><?= $sel('status', $statusLabels, $a['status']) ?></td>
              <td><button class="btn btn-sm btn-success" data-save="save_area">Save</button></td>
            </tr>
            <?php if (!empty($a['note'])): ?><tr><td colspan="5" class="mw-ops-hint"><?= h($a['note']) ?></td></tr><?php endif; ?>
          <?php endforeach; ?>
          </tbody>
        </table>
      </div></div>

      <div class="card"><div class="card-body">
        <h2 class="h5">BC statutory holidays <?= $year ?></h2>
        <p class="mw-ops-hint">The bylaws' "holidays". These are rules for equipment hours, separate from the days the company takes off.</p>
        <ul class="mw-ops-list">
          <?php foreach ($holidays as $d => $name): ?><li><b><?= h(date('D M j', strtotime($d))) ?></b> — <?= h($name) ?></li><?php endforeach; ?>
        </ul>
      </div></div>
    </div>

    <div class="col-lg-6">
      <div class="card"><div class="card-body">
        <h2 class="h5">What each service uses</h2>
        <p class="mw-ops-hint">Tick Blower where the crew uses a leaf blower. That drives the blower hours and the West End check. Mower and trimmer ticks count hours for maintenance.</p>
        <table class="table table-sm mw-ops-table">
          <thead><tr><th>Service type</th><th>Mower</th><th>Trimmer</th><th>Blower</th><th>Other</th><th></th></tr></thead>
          <tbody>
          <?php foreach ($types as $t): ?>
            <tr data-row data-service_type="<?= h($t['service_type']) ?>">
              <td><?= h($t['service_type']) ?><?= !$t['classes'] ? ' <span class="mw-ops-flag">not set</span>' : '' ?></td>
              <?php foreach (MunicipalRuleService::EQUIPMENT_CLASSES as $c): ?>
                <td><input type="checkbox" name="classes" value="<?= h($c) ?>"<?= in_array($c, $t['classes'], true) ? ' checked' : '' ?> aria-label="<?= h($t['service_type'] . ' uses ' . $c) ?>"></td>
              <?php endforeach; ?>
              <td><button class="btn btn-sm btn-success" data-save="save_service_map">Save</button></td>
            </tr>
          <?php endforeach; ?>
          </tbody>
        </table>
      </div></div>

      <?php if ($training): ?>
      <div class="card"><div class="card-body">
        <h2 class="h5">Training each service needs</h2>
        <p class="mw-ops-hint">I flag anyone scheduled on a service without this. A lapsed cert counts as missing. Saves to the certification requirements.</p>
        <table class="table table-sm mw-ops-table">
          <thead><tr><th>Service type</th><th>Course</th><th>Min tier</th><th></th></tr></thead>
          <tbody>
          <?php foreach ($types as $t):
              $r = $reqs[TrainingRules::serviceKey($t['service_type'])] ?? null;
              $cur = $r['reqs'][0] ?? null; ?>
            <tr data-row data-service_type="<?= h($t['service_type']) ?>">
              <td><?= h($t['service_type']) ?><?= !$r ? ' <span class="mw-ops-flag">not in service types</span>' : '' ?></td>
              <td><select name="course_id" class="form-control form-control-sm"<?= !$r ? ' disabled' : '' ?>>
                    <option value="">— none —</option>
                    <?php foreach ($courses as $c): ?><option value="<?= (int)$c['id'] ?>"<?= $cur && (int)$cur['course_id'] === (int)$c['id'] ? ' selected' : '' ?>><?= h($c['name']) ?></option><?php endforeach; ?>
                  </select></td>
              <td><select name="min_tier" class="form-control form-control-sm"<?= !$r ? ' disabled' : '' ?>>
                    <?php foreach ([0 => 'None', 1 => 'Tier 1', 2 => 'Tier 2', 3 => 'Tier 3'] as $k => $l): ?><option value="<?= $k ?>"<?= (int)($cur['min_tier_level'] ?? 0) === $k ? ' selected' : '' ?>><?= h($l) ?></option><?php endforeach; ?>
                  </select></td>
              <td><?php if ($r): ?><button class="btn btn-sm btn-success" data-save="save_training_map">Save</button><?php endif; ?></td>
            </tr>
          <?php endforeach; ?>
          </tbody>
        </table>
      </div></div>
      <?php endif; ?>

      <div class="card mw-ops-question"><div class="card-body" data-row>
        <h2 class="h5">Open question: where does green waste go?</h2>
        <p class="mw-ops-hint">Transfer station, compost facility, or client bins? Hours and fees? I'll use it for the truck's day once routes come in.</p>
        <textarea name="text" class="form-control" rows="4" placeholder="e.g. Vancouver Landfill transfer station, Mon–Sat 7–4, $/tonne…"><?= h($greenWaste) ?></textarea>
        <button class="btn btn-sm btn-success mt-2" data-save="save_green_waste">Save</button>
      </div></div>
    </div>
  </div>
  <?php endif; ?>
</div>

<script src="<?= function_exists('_av') ? _av('/crm/js/ops-dispatch.js') : '/crm/js/ops-dispatch.js' ?>" defer></script>
<?php include dirname(__DIR__) . '/includes/appstack_footer.php'; ?>

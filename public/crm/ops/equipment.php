<?php
/**
 * Equipment register — Otto the Dispatcher.
 *
 * Every mower, trimmer, blower, battery pack and truck: purchase, cost (from the linked
 * receipt — never typed twice), CCA class (recorded for the accountant), hours, service
 * intervals and log, battery runs logged by hand, and the Might-E's next days against its
 * range. Otto suggests maintenance tasks from here; he never creates one by himself.
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

$pageTitle = 'Equipment';
$activePage = 'schedule';

require_once APP_ROOT . '/Modules/Operations/Services/EquipmentService.php';
$db = getDB();
$svc = new EquipmentService($db);
$ready = $svc->ready();
$items = $intervals = $log = $runs = $people = [];
$truckDays = $odo = [];
$greenWaste = '';
if ($ready) {
    try {
        $items = $svc->items();
        $intervals = $svc->intervals();
        $log = $svc->serviceLog(30);
        $runs = $svc->runs();
        $people = $db->query("SELECT id, full_name FROM users WHERE is_active = 1 ORDER BY full_name")->fetchAll(PDO::FETCH_ASSOC);
        foreach ($items as $it) {
            if ($it['equipment_class'] === 'truck' && $it['status'] === 'active') {
                $truckDays[(int)$it['id']] = !empty($it['range_km']) && !empty($it['assigned_user_id']) ? $svc->truckDays($it) : [];
                $odo[(int)$it['id']] = $svc->odometerDays($it);
            }
        }
        $s = $db->prepare("SELECT setting_value FROM ops_settings WHERE setting_key = 'otto_green_waste'");
        $s->execute();
        $greenWaste = (string)$s->fetchColumn();
    } catch (Throwable $e) {
        error_log('Equipment page: ' . $e->getMessage());
    }
}
$classLabels = ['mower' => 'Mower', 'trimmer' => 'Trimmer', 'blower' => 'Blower', 'battery_pack' => 'Battery pack', 'truck' => 'Truck', 'other' => 'Other'];
$money = fn($v) => $v === null ? '—' : '$' . number_format((float)$v, 2);
$opts = function (array $o, $val): string {
    $h = '';
    foreach ($o as $k => $label) $h .= '<option value="' . h((string)$k) . '"' . ((string)$val === (string)$k ? ' selected' : '') . '>' . h($label) . '</option>';
    return $h;
};
$peopleOpts = ['' => '—'];
foreach ($people as $p) $peopleOpts[(int)$p['id']] = $p['full_name'];
$itemOpts = [];
foreach ($items as $it) if ($it['status'] === 'active') $itemOpts[(int)$it['id']] = $it['name'];
$packOpts = [];
foreach ($items as $it) if ($it['status'] === 'active' && $it['equipment_class'] === 'battery_pack') $packOpts[(int)$it['id']] = $it['name'];

$form = function (array $it) use ($classLabels, $opts, $peopleOpts): string {
    $v = fn($k) => h((string)($it[$k] ?? ''));
    ob_start(); ?>
    <div class="mw-ops-form" data-row data-id="<?= (int)($it['id'] ?? 0) ?>">
      <label>Name <input name="name" class="form-control form-control-sm" value="<?= $v('name') ?>" placeholder="EGO mower #2"></label>
      <label>Type <select name="equipment_class" class="form-control form-control-sm"><?= $opts($classLabels, $it['equipment_class'] ?? 'mower') ?></select></label>
      <label>Power <select name="power_source" class="form-control form-control-sm"><?= $opts(['battery' => 'Battery', 'gas' => 'Gas', 'electric' => 'Electric', 'diesel' => 'Diesel'], $it['power_source'] ?? 'battery') ?></select></label>
      <label>Make <input name="make" class="form-control form-control-sm" value="<?= $v('make') ?>"></label>
      <label>Model <input name="model" class="form-control form-control-sm" value="<?= $v('model') ?>"></label>
      <label>Serial <input name="serial_no" class="form-control form-control-sm" value="<?= $v('serial_no') ?>"></label>
      <label>Used by <select name="assigned_user_id" class="form-control form-control-sm"><?= $opts($peopleOpts, $it['assigned_user_id'] ?? '') ?></select></label>
      <label>Bought <input type="date" name="purchase_date" class="form-control form-control-sm" value="<?= $v('purchase_date') ?>"></label>
      <label>Receipt # <input type="number" name="expense_id" class="form-control form-control-sm" value="<?= $v('expense_id') ?>" title="The purchase in Expenses — the cost comes from it"></label>
      <label>Cost (no receipt) <input type="number" step="0.01" name="cost_manual" class="form-control form-control-sm" value="<?= $v('cost_manual') ?>"></label>
      <label>CCA class <input name="cca_class" class="form-control form-control-sm" value="<?= $v('cca_class') ?>" placeholder="for the accountant"></label>
      <label>Hours when added <input type="number" step="0.1" name="hours_baseline" class="form-control form-control-sm" value="<?= $v('hours_baseline') ?>"></label>
      <label>Pack: min per charge new <input type="number" name="runtime_new_min" class="form-control form-control-sm" value="<?= $v('runtime_new_min') ?>"></label>
      <label>Truck: range km <input type="number" name="range_km" class="form-control form-control-sm" value="<?= $v('range_km') ?>"></label>
      <label>Truck: reserve % <input type="number" name="reserve_pct" class="form-control form-control-sm" value="<?= $v('reserve_pct') ?>" placeholder="20"></label>
      <label>Truck: top speed km/h <input type="number" name="top_speed_kph" class="form-control form-control-sm" value="<?= $v('top_speed_kph') ?>"></label>
      <label>Truck: trip-report vehicle id <input name="vehicle_id" class="form-control form-control-sm" value="<?= $v('vehicle_id') ?>"></label>
      <label>Truck: yard lat <input name="base_lat" class="form-control form-control-sm" value="<?= $v('base_lat') ?>"></label>
      <label>Truck: yard lng <input name="base_lng" class="form-control form-control-sm" value="<?= $v('base_lng') ?>"></label>
      <label>Status <select name="status" class="form-control form-control-sm"><?= $opts(['active' => 'Active', 'retired' => 'Retired'], $it['status'] ?? 'active') ?></select></label>
      <label class="mw-ops-wide">Notes <input name="notes" class="form-control form-control-sm" value="<?= $v('notes') ?>"></label>
      <div class="mw-ops-wide"><button class="btn btn-sm btn-success" data-save="save_equipment"><?= empty($it['id']) ? 'Add to the register' : 'Save' ?></button></div>
    </div>
    <?php return (string)ob_get_clean();
};
?>
<?php include dirname(__DIR__) . '/includes/appstack_head.php'; ?>

<div class="mw-ops-page">
  <div class="mw-ops-head">
    <img src="/crm/img/heads/otto.jpg" alt="" width="56" height="56">
    <div>
      <h1 class="h3 mb-1">Equipment</h1>
      <p class="text-muted mb-0">Every mower, trimmer, blower, pack and truck. Hours come from job timers, so they are an estimate.
        I suggest a maintenance task when one is due; you decide. Cost comes from the purchase receipt in Expenses.</p>
    </div>
  </div>

  <?php if (!$ready): ?>
    <div class="alert alert-warning">Run migrations 1155–1158 first.</div>
  <?php else: ?>

  <div class="card"><div class="card-body">
    <h2 class="h5">Register</h2>
    <?php if (!$items): ?><p class="mw-ops-hint">Nothing registered yet. Add the Might-E first, then the mowers and packs.</p><?php endif; ?>
    <div class="table-responsive">
      <table class="table table-sm mw-ops-table">
        <thead><tr><th>Item</th><th>Type</th><th>Power</th><th>Used by</th><th>Bought</th><th>Cost</th><th>CCA</th><th>Hours*</th><th>Status</th></tr></thead>
        <tbody>
        <?php foreach ($items as $it):
            $hrs = (float)$it['hours_baseline'] + $svc->hoursSince($it, $it['purchase_date'] ?: substr((string)$it['created_at'], 0, 10)); ?>
          <tr id="eq-<?= (int)$it['id'] ?>" class="<?= $it['status'] === 'retired' ? 'mw-ops-retired' : '' ?>">
            <td><b><?= h($it['name']) ?></b><br><small class="text-muted"><?= h(trim(($it['make'] ?? '') . ' ' . ($it['model'] ?? ''))) ?></small></td>
            <td><?= h($classLabels[$it['equipment_class']] ?? $it['equipment_class']) ?></td>
            <td><?= h(ucfirst((string)$it['power_source'])) ?></td>
            <td><?= h($it['assigned_name'] ?? '—') ?></td>
            <td><?= h($it['purchase_date'] ? date('M j, Y', strtotime($it['purchase_date'])) : '—') ?></td>
            <td><?= h($money($it['cost'])) ?><?php if ($it['cost_source'] === 'receipt'): ?><br><a href="/crm/expenses_appstack.php" class="mw-ops-src">receipt #<?= (int)$it['expense_id'] ?></a><?php endif; ?></td>
            <td><?= h($it['cca_class'] ?: '—') ?></td>
            <td><?= in_array($it['equipment_class'], ['mower', 'trimmer', 'blower'], true) ? number_format($hrs, 1) : '—' ?></td>
            <td><?= h(ucfirst((string)$it['status'])) ?></td>
          </tr>
          <tr class="mw-ops-edit"><td colspan="9"><details><summary>Edit</summary><?= $form($it) ?></details></td></tr>
        <?php endforeach; ?>
        </tbody>
      </table>
    </div>
    <p class="mw-ops-hint">* Hours: the person's job-timer time on visits whose service uses this kind of tool (ticked on <a href="/crm/ops/municipal-rules.php">Bylaw rules</a>), plus the hours it had when added.</p>
    <details class="mw-ops-add"><summary class="btn btn-sm btn-outline-success">Add an item</summary><?= $form([]) ?></details>
  </div></div>

  <?php foreach ($truckDays as $tid => $days): $t = null; foreach ($items as $it) if ((int)$it['id'] === $tid) $t = $it; ?>
  <div class="card"><div class="card-body">
    <h2 class="h5"><?= h($t['name']) ?> — the next 7 days</h2>
    <?php if (empty($t['range_km']) || empty($t['assigned_user_id'])): ?>
      <p class="mw-ops-hint">Set the range and who drives it to see planned km.</p>
    <?php elseif (!$days): ?>
      <p class="mw-ops-hint">No stops with map locations on the schedule for <?= h($t['assigned_name'] ?? 'the driver') ?> in the next 7 days.</p>
    <?php else: ?>
      <table class="table table-sm mw-ops-table">
        <thead><tr><th>Day</th><th>Stops</th><th>Planned km</th><th>Cap</th><th></th></tr></thead>
        <tbody>
        <?php foreach ($days as $d): $over = $d['planned_km'] > $d['cap_km']; ?>
          <tr class="<?= $over ? 'mw-ops-over' : '' ?>"><td><?= h(date('D M j', strtotime($d['date']))) ?></td><td><?= (int)$d['stops'] ?></td>
            <td><?= number_format($d['planned_km'], 1) ?></td><td><?= number_format($d['cap_km'], 1) ?></td><td><?= $over ? 'Over the reserve' : 'OK' ?></td></tr>
        <?php endforeach; ?>
        </tbody>
      </table>
      <p class="mw-ops-hint">Straight-line distance between stops in route order × <?= h((string)$days[0]['factor']) ?><?= $days[0]['learned'] ? ' (learned from odometer days)' : ' (starting estimate — learned after 5 odometer days)' ?>.
        Cap = <?= (int)$t['range_km'] ?> km less <?= (int)($t['reserve_pct'] ?? DispatchRules::RESERVE_PCT) ?>% reserve.</p>
    <?php endif; ?>
    <?php $o = $odo[$tid] ?? []; if ($o): $max = max(array_column($o, 'odometer_km')); ?>
      <p class="mw-ops-hint">Odometer: <?= count($o) ?> days logged in the last 60; longest day <b><?= number_format($max, 0) ?> km</b>. Battery % at the end of the day is not recorded yet, so the real range is still the spec.</p>
    <?php elseif ($t && empty($t['vehicle_id'])): ?>
      <p class="mw-ops-hint">Add the trip-report vehicle id to learn real km from odometer readings.</p>
    <?php endif; ?>
  </div></div>
  <?php endforeach; ?>

  <div class="row">
    <div class="col-lg-6">
      <div class="card"><div class="card-body">
        <h2 class="h5">Service intervals</h2>
        <p class="mw-ops-hint">Your numbers. An interval on one item overrides the one for its type.</p>
        <table class="table table-sm mw-ops-table">
          <thead><tr><th>For</th><th>Task</th><th>Every</th><th></th></tr></thead>
          <tbody>
          <?php foreach ($intervals as $iv): ?>
            <tr data-row data-id="<?= (int)$iv['id'] ?>">
              <td><?= h($iv['equipment_id'] ? ($itemOpts[(int)$iv['equipment_id']] ?? 'Item #' . (int)$iv['equipment_id']) : ($classLabels[$iv['equipment_class']] ?? $iv['equipment_class']) . ' (all)') ?></td>
              <td><?= h($iv['task']) ?></td>
              <td><?= h(trim(($iv['every_hours'] ? (float)$iv['every_hours'] . ' h ' : '') . ($iv['every_days'] ? $iv['every_days'] . ' days' : ''))) ?></td>
              <td><button class="btn btn-sm btn-outline-secondary" data-save="delete_interval" data-confirm="Remove this interval?">✕</button></td>
            </tr>
          <?php endforeach; ?>
          <tr data-row>
            <td><select name="equipment_class" class="form-control form-control-sm"><?= $opts(['' => 'Type…'] + $classLabels, '') ?></select>
                <select name="equipment_id" class="form-control form-control-sm mt-1"><?= $opts(['' => '…or one item'] + $itemOpts, '') ?></select></td>
            <td><input name="task" class="form-control form-control-sm" placeholder="Blade sharpening"></td>
            <td><input type="number" step="0.5" name="every_hours" class="form-control form-control-sm" placeholder="hours">
                <input type="number" name="every_days" class="form-control form-control-sm mt-1" placeholder="or days"></td>
            <td><button class="btn btn-sm btn-success" data-save="save_interval">Add</button></td>
          </tr>
          </tbody>
        </table>
      </div></div>

      <div class="card"><div class="card-body">
        <h2 class="h5">Log a service</h2>
        <div class="mw-ops-form" data-row>
          <label>Item <select name="equipment_id" class="form-control form-control-sm"><?= $opts($itemOpts, '') ?></select></label>
          <label>Task <input name="task" class="form-control form-control-sm" placeholder="Blade sharpening"></label>
          <label>Done on <input type="date" name="done_on" class="form-control form-control-sm" value="<?= date('Y-m-d') ?>"></label>
          <div class="mw-ops-wide"><button class="btn btn-sm btn-success" data-save="log_service">Log it</button></div>
        </div>
        <?php if ($log): ?>
          <ul class="mw-ops-list mt-2">
            <?php foreach ($log as $l): ?><li><b><?= h(date('M j', strtotime($l['done_on']))) ?></b> — <?= h($l['name']) ?>: <?= h($l['task']) ?></li><?php endforeach; ?>
          </ul>
        <?php endif; ?>
      </div></div>
    </div>

    <div class="col-lg-6">
      <div class="card"><div class="card-body">
        <h2 class="h5">Battery packs</h2>
        <p class="mw-ops-hint">Log how long a pack ran on one charge. When its last 3 full runs fall under 70% of new, I'll flag it.</p>
        <div class="mw-ops-form" data-row>
          <label>Pack <select name="equipment_id" class="form-control form-control-sm"><?= $opts($packOpts ?: ['' => 'Add a battery pack first'], '') ?></select></label>
          <label>Minutes <input type="number" name="runtime_min" class="form-control form-control-sm"></label>
          <label>Date <input type="date" name="run_date" class="form-control form-control-sm" value="<?= date('Y-m-d') ?>"></label>
          <label class="mw-ops-check"><input type="checkbox" name="ran_flat" checked> Ran until empty</label>
          <div class="mw-ops-wide"><button class="btn btn-sm btn-success" data-save="log_run">Log the run</button></div>
        </div>
        <ul class="mw-ops-list mt-2">
          <?php foreach ($items as $it): if ($it['equipment_class'] !== 'battery_pack') continue;
              $r = $runs[(int)$it['id']] ?? [];
              $f = DispatchRules::packFading(array_column($r, 'min'), $it['runtime_new_min'] !== null ? (int)$it['runtime_new_min'] : null); ?>
            <li><b><?= h($it['name']) ?></b>: <?= $r ? h(implode(', ', array_map(fn($x) => $x['min'] . ' min', array_slice($r, 0, 5)))) : 'no runs yet' ?>
              <?php if ($f['share'] !== null): ?> — <span class="<?= $f['fading'] ? 'mw-ops-flag' : '' ?>"><?= (int)round($f['share'] * 100) ?>% of new<?= $f['fading'] ? ', fading' : '' ?></span><?php endif; ?></li>
          <?php endforeach; ?>
        </ul>
      </div></div>

      <div class="card mw-ops-question"><div class="card-body">
        <h2 class="h5">Open question: where does green waste go?</h2>
        <p class="mb-0"><?= $greenWaste !== '' ? nl2br(h($greenWaste)) : 'Not answered yet.' ?> <a href="/crm/ops/municipal-rules.php">Answer on Bylaw rules →</a></p>
      </div></div>
    </div>
  </div>
  <?php endif; ?>
</div>

<script src="<?= function_exists('_av') ? _av('/crm/js/ops-dispatch.js') : '/crm/js/ops-dispatch.js' ?>" defer></script>
<?php include dirname(__DIR__) . '/includes/appstack_footer.php'; ?>

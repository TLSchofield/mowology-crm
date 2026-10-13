<?php
/**
 * One machine — Otto's equipment page (migration 1350, 2026-10-13).
 *
 * Shows: service intervals and where each stands (EquipmentService::intervalRows), the service log and
 * "Log service", receipts that are this machine's (purchase + service-log receipts) and the receipts
 * tagged for its kind (asset_tag), the cost this year (EquipmentService::yearCost), and its MODEL from
 * the shared catalogue: specs, parts (part numbers shared by every unit of the model) and the manual's
 * intervals — "Use the manual's intervals" when someone already read it, else "Read the manual"
 * (OttoManualService: Claude, daily cap).
 * jobs.edit only. Saves go to /crm/api/dispatch.php (ops-dispatch.js data-save rows) and the manual
 * upload to /crm/api/label-products.php (ops-equipment.js).
 */
require_once dirname(__DIR__) . '/../loginAuth/auth.php';
require_once dirname(__DIR__) . '/includes/functions.php';
requireLogin();
$user = getCurrentUser();
if (!userHasPermission('jobs.edit')) {
    http_response_code(403);
    exit('You need permission to run the schedule (jobs.edit) to see this page.');
}

require_once APP_ROOT . '/Modules/Operations/Services/EquipmentService.php';
require_once APP_ROOT . '/Modules/Operations/Services/EquipmentCatalogService.php';
require_once APP_ROOT . '/Modules/Operations/Services/OttoManualService.php';
$db = getDB();
$svc = new EquipmentService($db);
$id = (int)($_GET['id'] ?? 0);
$d = null;
$manual = ['ready' => false, 'left' => 0, 'cap' => 0];
$pending = [];
try {
    $d = $svc->ready() ? $svc->detail($id) : null;
    $mSvc = new OttoManualService($db);
    $manual = $mSvc->status();
    $pending = $mSvc->pending()[$id] ?? [];
} catch (Throwable $e) {
    error_log('Equipment item page: ' . $e->getMessage());
}

$pageTitle = $d ? $d['item']['name'] : 'Equipment';
$activePage = 'schedule';
$money = fn($v) => $v === null ? '—' : '$' . number_format((float)$v, 2);
$classLabels = EquipmentService::CLASS_LABELS;
$stateLabel = ['due' => 'Due', 'soon' => 'Soon', 'ok' => 'OK'];
$scopeLabel = ['item' => 'this machine', 'model' => 'every unit of this model', 'class' => 'every ' . strtolower($classLabels[$d['item']['equipment_class'] ?? 'other'] ?? 'item')];
?>
<?php include dirname(__DIR__) . '/includes/appstack_head.php'; ?>

<div class="mw-ops-page">
  <p class="mb-2"><a href="/crm/ops/equipment.php">← Equipment</a></p>
  <?php if (!$d): ?>
    <div class="alert alert-warning">That machine is not in the register.</div>
  <?php else: $it = $d['item']; $m = $d['model']; $c = $d['cost']; ?>
  <div class="mw-ops-head">
    <?php if (!empty($it['photo_media_id'])): ?>
      <img src="/crm/api/serve-receipt.php?id=<?= (int)$it['photo_media_id'] ?>" alt="" width="56" height="56">
    <?php else: ?>
      <img src="/crm/img/heads/otto.jpg" alt="" width="56" height="56">
    <?php endif; ?>
    <div>
      <h1 class="h3 mb-1"><?= h($it['name']) ?></h1>
      <p class="text-muted mb-0">
        <?= h($classLabels[$it['equipment_class']] ?? $it['equipment_class']) ?> · <?= h(ucfirst((string)$it['power_source'])) ?>
        <?= ($it['make'] || $it['model']) ? ' · ' . h(trim($it['make'] . ' ' . $it['model'])) : '' ?>
        <?= $it['serial_no'] ? ' · serial ' . h($it['serial_no']) : '' ?>
        <?= $it['assigned_name'] ? ' · used by ' . h($it['assigned_name']) : '' ?>
        <?= !empty($it['truck_name']) ? ' · on ' . h($it['truck_name']) : '' ?>
        <?= $d['hours'] !== null ? ' · about ' . number_format($d['hours'], 1) . ' h' : '' ?>
        <?= $it['status'] === 'retired' ? ' · retired' : '' ?>
      </p>
    </div>
  </div>

  <div class="row">
    <div class="col-lg-6">
      <div class="card"><div class="card-body">
        <h2 class="h5">Service</h2>
        <?php if (!$d['intervals']): ?>
          <p class="mw-ops-hint">No service intervals yet<?= $m && $m['not_adopted'] ? ' — the manual\'s are below' : '' ?>. Add them on <a href="/crm/ops/equipment.php">Equipment</a>, or read the manual.</p>
        <?php else: ?>
          <table class="table table-sm mw-ops-table">
            <thead><tr><th>Task</th><th>Every</th><th>Since last</th><th></th></tr></thead>
            <tbody>
            <?php foreach ($d['intervals'] as $iv): ?>
              <tr class="<?= $iv['state'] === 'due' ? 'mw-ops-over' : '' ?>">
                <td><?= h($iv['task']) ?><br><small class="text-muted">for <?= h($scopeLabel[$iv['scope']] ?? $iv['scope']) ?></small></td>
                <td><?= h($iv['every']) ?></td>
                <td><?= $iv['every_hours'] ? number_format($iv['hours'], 1) . ' h' : '' ?><?= $iv['every_hours'] && $iv['every_days'] ? ' / ' : '' ?><?= $iv['every_days'] ? (int)$iv['days'] . ' days' : '' ?>
                  <br><small class="text-muted"><?= $iv['last'] ? 'last ' . h(date('M j, Y', strtotime($iv['last']))) : 'never logged' ?></small></td>
                <td><?php if ($iv['state'] !== 'ok'): ?><span class="mw-ops-flag"><?= h($stateLabel[$iv['state']]) ?></span><?php endif; ?></td>
              </tr>
            <?php endforeach; ?>
            </tbody>
          </table>
          <p class="mw-ops-hint">Hours come from job timers on visits that use a <?= h(strtolower($classLabels[$it['equipment_class']] ?? 'machine')) ?> — an estimate.</p>
        <?php endif; ?>

        <h3 class="h6 mt-3">Log service</h3>
        <div class="mw-ops-form" data-row data-equipment_id="<?= (int)$it['id'] ?>">
          <label>Task <input name="task" class="form-control form-control-sm" list="mw-eq-tasks" placeholder="Sharpen blade"></label>
          <datalist id="mw-eq-tasks"><?php foreach ($d['intervals'] as $iv): ?><option value="<?= h($iv['task']) ?>"><?php endforeach; ?></datalist>
          <div class="mw-form-group">
            <label class="d-block mb-1">Done on</label>
            <button type="button" class="mw-datepicker-trigger" data-mw-dp-commit="input" data-mw-dp-target="#mw-eq-done-on" aria-haspopup="true" aria-expanded="false">
              <svg class="mw-datepicker-cal-icon" width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="3" y="4" width="18" height="18" rx="2" ry="2"/><line x1="16" y1="2" x2="16" y2="6"/><line x1="8" y1="2" x2="8" y2="6"/><line x1="3" y1="10" x2="21" y2="10"/></svg>
              <span class="mw-datepicker-date" data-mw-dp-label></span>
              <svg class="mw-datepicker-chevron" width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><polyline points="6 9 12 15 18 9"/></svg>
            </button>
            <input type="date" id="mw-eq-done-on" name="done_on" hidden value="<?= h(date('Y-m-d')) ?>">
          </div>
          <label>Receipt # (parts / repair) <input type="number" name="expense_id" class="form-control form-control-sm"></label>
          <label class="mw-ops-wide">Note <input name="note" class="form-control form-control-sm"></label>
          <div class="mw-ops-wide"><button class="btn btn-sm btn-success" data-save="log_service">Log service</button></div>
        </div>
        <?php if ($d['log']): ?>
          <ul class="mw-ops-list mt-2">
            <?php foreach ($d['log'] as $l): ?>
              <li><b><?= h(date('M j, Y', strtotime($l['done_on']))) ?></b> — <?= h($l['task']) ?>
                <?= $l['expense_id'] ? ' · receipt #' . (int)$l['expense_id'] . ' ' . h($money($l['receipt_total'])) : '' ?><?= $l['note'] ? ' · ' . h($l['note']) : '' ?></li>
            <?php endforeach; ?>
          </ul>
        <?php endif; ?>
      </div></div>

      <div class="card"><div class="card-body">
        <h2 class="h5">Cost in <?= (int)$c['year'] ?></h2>
        <table class="table table-sm mw-ops-table">
          <tbody>
          <?php foreach ($c['own'] as $o): ?>
            <tr><td><?= h($o['kind']) ?><br><small class="text-muted"><?= h(date('M j', strtotime($o['date']))) ?> · <?= h($o['what']) ?><?= $o['expense_id'] ? ' · receipt #' . (int)$o['expense_id'] : '' ?></small></td>
                <td class="text-right"><?= h($money($o['total'])) ?></td></tr>
          <?php endforeach; ?>
          <tr><td>Share of receipts tagged “<?= h($c['tag']) ?>”<br><small class="text-muted">
              <?= $c['pool'] ? h(implode(', ', array_map(fn($k, $v) => $k . ' ' . $money($v), array_keys($c['pool']), $c['pool']))) . ' — ' : '' ?>
              <?= h($money($c['pool_total'])) ?> ÷ <?= (int)$c['units'] ?> active <?= $c['tag'] === 'truck' ? 'truck' . ($c['units'] === 1 ? '' : 's') : 'machines' ?>, an estimate
              <?= in_array($it['power_source'], ['battery', 'electric'], true) ? ' (no fuel: it is ' . h($it['power_source']) . ')' : '' ?></small></td>
              <td class="text-right"><?= h($money($c['share'])) ?></td></tr>
          <tr><td><b>Total this year</b></td><td class="text-right"><b><?= h($money($c['total'])) ?></b></td></tr>
          </tbody>
        </table>
        <?php if ($c['tagged']): ?>
          <details><summary class="mw-ops-hint">The <?= count($c['tagged']) ?> tagged receipt<?= count($c['tagged']) === 1 ? '' : 's' ?></summary>
            <ul class="mw-ops-list mt-1">
              <?php foreach ($c['tagged'] as $x): ?><li><?= h(date('M j', strtotime($x['date']))) ?> — <?= h($x['vendor'] ?: 'receipt') ?> · <?= h($x['category']) ?> · <?= h($money($x['total'])) ?> · #<?= (int)$x['expense_id'] ?></li><?php endforeach; ?>
            </ul>
          </details>
        <?php endif; ?>
      </div></div>
    </div>

    <div class="col-lg-6">
      <div class="card"><div class="card-body">
        <h2 class="h5">Purchase</h2>
        <p class="mb-1">Bought <?= $it['purchase_date'] ? h(date('M j, Y', strtotime($it['purchase_date']))) : '—' ?> ·
          <?= h($money($it['cost'])) ?><?= $it['cost_source'] === 'receipt' ? ' from receipt #' . (int)$it['expense_id'] : ($it['cost_source'] === 'manual' ? ' (typed, no receipt)' : '') ?></p>
        <div class="mw-ops-form" data-row data-id="<?= (int)$it['id'] ?>">
          <label>Purchase receipt # <input type="number" name="expense_id" class="form-control form-control-sm" value="<?= h((string)($it['expense_id'] ?? '')) ?>"></label>
          <div class="mw-ops-wide"><button class="btn btn-sm btn-outline-success" data-save="link_receipt">Link the receipt</button>
            <button type="button" class="btn btn-sm btn-link" data-eq-receipt-find data-make="<?= h((string)$it['make']) ?>" data-model="<?= h((string)$it['model']) ?>"
                    data-date="<?= h((string)$it['purchase_date']) ?>" data-cost="<?= h((string)($it['cost_manual'] ?? '')) ?>">find it</button></div>
          <div class="mw-ops-wide" data-eq-receipts></div>
        </div>
        <?php if (!empty($it['plate_media_id'])): ?>
          <p class="mw-ops-hint mt-2"><a href="/crm/api/serve-receipt.php?id=<?= (int)$it['plate_media_id'] ?>" target="_blank" rel="noopener">The plate photo</a></p>
        <?php endif; ?>
      </div></div>

      <div class="card"><div class="card-body">
        <h2 class="h5">The model<?= $m ? ': ' . h($m['product']['canonical_name']) : '' ?></h2>
        <?php if (!$m): ?>
          <p class="mw-ops-hint">No model number yet. Add the model on <a href="/crm/ops/equipment.php#eq-<?= (int)$it['id'] ?>">Equipment</a> and it joins the shared catalogue —
            specs, parts and the manual's intervals then belong to every unit of that model.</p>
        <?php else: ?>
          <p class="mw-ops-hint">Shared with every company that has this model: specs, parts and what the manual says. Prices and serials never are.
            <?= $m['units'] > 1 ? 'You have ' . (int)$m['units'] . ' of these.' : '' ?></p>
          <?php $show = array_diff_key($m['attributes'], EquipmentCatalogService::PART_KEYS); if ($show): ?>
            <ul class="mw-ops-list"><?php foreach ($show as $k => $v): ?><li><?= h(ucfirst(str_replace('_', ' ', $k))) ?>: <?= h($k === 'equipment_class' ? ($classLabels[$v] ?? $v) : $v) ?></li><?php endforeach; ?></ul>
          <?php endif; ?>

          <h3 class="h6 mt-3">Parts</h3>
          <table class="table table-sm mw-ops-table">
            <tbody>
            <?php foreach ($m['parts'] as $p): ?>
              <tr data-row data-catalog_product_id="<?= (int)$m['product']['id'] ?>" data-key="<?= h($p['key']) ?>">
                <td><?= h($p['label']) ?></td>
                <td><input name="value" class="form-control form-control-sm" value="<?= h($p['value']) ?>" placeholder="part no."></td>
                <td><?= $p['price'] !== null ? h($money($p['price'])) . '<br><small class="text-muted">' . h(($p['supplier'] ?? '') . ' ' . $p['price_date']) . '</small>' : '' ?></td>
                <td><button class="btn btn-sm btn-outline-secondary" data-save="set_part">Save</button></td>
              </tr>
            <?php endforeach; ?>
            </tbody>
          </table>
          <p class="mw-ops-hint">A part number becomes its own catalogue entry, so a dealer's parts list imported on <a href="/crm/suppliers/price-lists.php">Price lists</a> shows your price here.</p>

          <h3 class="h6 mt-3">What the manual says</h3>
          <?php if ($m['shared_intervals']): ?>
            <ul class="mw-ops-list">
              <?php foreach ($m['shared_intervals'] as $sv): ?>
                <li><b><?= h($sv['task']) ?></b> — <?= h(OttoManualService::every($sv['every_hours'] !== null ? (float)$sv['every_hours'] : null, $sv['every_days'] !== null ? (int)$sv['every_days'] : null)) ?>
                  <?= $sv['quote'] ? '<br><small class="text-muted">“' . h($sv['quote']) . '”' . ($sv['page'] ? ' p. ' . h($sv['page']) : '') . '</small>' : '' ?></li>
              <?php endforeach; ?>
            </ul>
            <?php if ($m['not_adopted']): ?>
              <div data-row data-catalog_product_id="<?= (int)$m['product']['id'] ?>">
                <button class="btn btn-sm btn-success" data-save="adopt_intervals">Use the manual's <?= count($m['not_adopted']) ?> interval<?= count($m['not_adopted']) === 1 ? '' : 's' ?> for every unit of this model</button>
              </div>
            <?php else: ?>
              <p class="mw-ops-hint">You use these already.</p>
            <?php endif; ?>
          <?php else: ?>
            <p class="mw-ops-hint">Nobody has read this model's manual yet.</p>
          <?php endif; ?>
        <?php endif; ?>

        <?php if (!$m || !$m['shared_intervals']): ?>
          <div class="mw-ops-form mt-2" data-eq-manual-read data-equipment_id="<?= (int)$it['id'] ?>">
            <label class="mw-ops-wide">Read the manual — the owner's manual PDF or a photo of its maintenance table
              <input type="file" name="manual" accept="application/pdf,image/*" class="form-control form-control-sm"></label>
            <div class="mw-ops-wide">
              <button type="button" class="btn btn-sm btn-outline-success" data-eq-manual-go <?= ($manual['ready'] && $manual['left'] > 0) ? '' : 'disabled' ?>>Ask Otto to read it</button>
              <span class="mw-ops-hint"><?= $manual['ready'] ? (int)$manual['left'] . ' of ' . (int)$manual['cap'] . ' reads left today' : 'Manual reading is not set up (Anthropic key).' ?></span>
              <span class="mw-ops-msg" data-eq-manual-msg></span>
            </div>
          </div>
        <?php endif; ?>

        <?php if ($pending): ?>
          <h3 class="h6 mt-3">Otto read the manual — confirm each line</h3>
          <table class="table table-sm mw-ops-table"><tbody>
          <?php foreach ($pending as $p): ?>
            <tr data-eq-manual-line data-read_id="<?= (int)$p['read_id'] ?>" data-index="<?= (int)$p['index'] ?>">
              <td><input name="task" class="form-control form-control-sm" value="<?= h($p['task']) ?>">
                <small class="text-muted">“<?= h($p['quote']) ?>”<?= $p['page'] ? ' p. ' . h($p['page']) : '' ?></small></td>
              <td><input type="number" step="0.5" name="every_hours" class="form-control form-control-sm" value="<?= h((string)($p['every_hours'] ?? '')) ?>" placeholder="hours">
                  <input type="number" name="every_days" class="form-control form-control-sm mt-1" value="<?= h((string)($p['every_days'] ?? '')) ?>" placeholder="days"></td>
              <td><button type="button" class="btn btn-sm btn-success" data-eq-manual-confirm>Keep</button>
                  <button type="button" class="btn btn-sm btn-outline-secondary mt-1" data-eq-manual-skip>Skip</button></td>
            </tr>
          <?php endforeach; ?>
          </tbody></table>
        <?php endif; ?>
      </div></div>
    </div>
  </div>
  <?php endif; ?>
</div>

<script src="<?= function_exists('_av') ? _av('/crm/js/ops-dispatch.js') : '/crm/js/ops-dispatch.js' ?>" defer></script>
<script src="<?= function_exists('_av') ? _av('/crm/js/ops-equipment.js') : '/crm/js/ops-equipment.js' ?>" defer></script>
<?php include dirname(__DIR__) . '/includes/appstack_footer.php'; ?>

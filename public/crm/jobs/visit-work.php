<?php
/**
 * Visit Work Page — Crew Mobile Flow
 * ─────────────────────────────────────
 * The active-work screen for crew members during a visit.
 * Designed for mobile (Capacitor app + PWA).
 *
 * Flow: Start → GPS collects → Tabs: Checklist / Materials / Notes / Photos → End Visit → Generate PDF
 *
 * GPS points buffer locally in IndexedDB, synced to server every 30 seconds.
 * Works offline: all inputs buffer locally, synced on reconnect.
 */
require_once dirname(__DIR__) . '/../loginAuth/auth.php';
require_once dirname(__DIR__) . '/includes/functions.php';
requireLogin();
$user = getCurrentUser();
requirePermission('jobs.view');

$visitId = isset($_GET['id']) ? (int)$_GET['id'] : 0;
if (!$visitId) {
    header('Location: index.php');
    exit;
}

$db = getDB();

// Load visit + plan
$stmt = $db->prepare("
    SELECT v.*, p.title AS plan_title, p.checklist_template, p.service_type AS plan_service_type,
           p.plan_number, pr.address AS property_address,
           c.first_name AS contact_first, c.last_name AS contact_last
    FROM job_visits v
    JOIN job_plans p ON v.plan_id = p.id
    LEFT JOIN properties pr ON p.property_id = pr.id
    LEFT JOIN contacts c ON pr.site_contact_id = c.id
    WHERE v.id = ?
");
$stmt->execute([$visitId]);
$visit = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$visit) {
    header('Location: index.php');
    exit;
}

$isMyCrew = (int)($visit['assigned_crew_id'] ?? 0) === (int)$user['id'];
$isAdmin  = ($user['role'] ?? '') === 'admin' || userHasPermission('jobs.edit');
if (!$isAdmin && !$isMyCrew) {
    header('Location: index.php');
    exit;
}

$visit['service_type'] = $visit['service_type'] ?? $visit['plan_service_type'] ?? 'general';
$isLocked = ($visit['locked_at'] !== null);

// Load existing checklist / materials / service data
$checklist = !empty($visit['checklist_json'])    ? json_decode($visit['checklist_json'], true)    : [];
$materials = !empty($visit['materials_json'])    ? json_decode($visit['materials_json'], true)    : [];
$svcData   = !empty($visit['service_data_json']) ? json_decode($visit['service_data_json'], true) : [];

// Seed checklist from plan template if empty
if (empty($checklist) && !empty($visit['checklist_template'])) {
    $tpl       = json_decode($visit['checklist_template'], true) ?? [];
    $checklist = array_map(fn($t) => ['item'=>$t,'checked'=>false,'note'=>''], $tpl);
}

$csrfToken = generateCSRFToken();

// Extras billing rate (per 5-min block)
try {
    $rateRow = $db->query("SELECT setting_value FROM ops_settings WHERE setting_key = 'extras_rate_per_5min' LIMIT 1")->fetch(PDO::FETCH_ASSOC);
    $extrasRatePerBlock = round(floatval($rateRow['setting_value'] ?? 5.00), 2);
} catch (PDOException $e) {
    $extrasRatePerBlock = 5.00;
}

$pageTitle  = 'Work: ' . ($visit['visit_number'] ?? "Visit #{$visitId}");
$activePage = 'jobs';
?>
<?php include dirname(__DIR__) . '/includes/appstack_head.php'; ?>

<!-- ── Mobile work shell ─────────────────────────────────────────────────── -->
<div id="pow-work-app" class="mw-pow-work">

  <!-- ─ Header ─ -->
  <div class="pow-work-header">
    <div class="pow-work-header-info">
      <div class="pow-work-visit"><?= htmlspecialchars($visit['visit_number'] ?? "Visit #{$visitId}") ?></div>
      <div class="pow-work-addr"><?= htmlspecialchars($visit['property_address'] ?? '') ?></div>
    </div>
    <div class="pow-work-status-dot" id="status-dot" title="GPS status"></div>
  </div>

  <!-- ─ Timer + GPS Stats ─ -->
  <div class="pow-work-stats-bar" id="stats-bar">
    <div class="pow-stat">
      <span class="pow-stat-val" id="timer-display">00:00</span>
      <span class="pow-stat-lbl">Elapsed</span>
    </div>
    <div class="pow-stat">
      <span class="pow-stat-val" id="gps-pts-display">0</span>
      <span class="pow-stat-lbl">GPS pts</span>
    </div>
    <div class="pow-stat">
      <span class="pow-stat-val" id="dist-display">0m</span>
      <span class="pow-stat-lbl">Distance</span>
    </div>
    <div class="pow-stat">
      <span class="pow-stat-val" id="accuracy-display">—</span>
      <span class="pow-stat-lbl">Accuracy</span>
    </div>
  </div>

  <!-- ─ State gate: NOT YET STARTED ─ -->
  <?php if ($visit['status'] === 'scheduled'): ?>
  <div class="pow-start-gate" id="start-gate">
    <div class="pow-start-icon"><i data-feather="play-circle"></i></div>
    <div class="pow-start-title">Ready to Start?</div>
    <div class="pow-start-sub">
      <?= htmlspecialchars(trim(($visit['contact_first']??'').($visit['contact_last']?' '.$visit['contact_last']:''))) ?><br>
      <?= htmlspecialchars(ucfirst(str_replace('_',' ',$visit['service_type']))) ?>
    </div>
    <button class="btn btn-success btn-lg btn-block mt-4" id="btn-start-visit">
      <i data-feather="play" class="mr-2"></i>Start Visit &amp; Begin Tracking
    </button>
  </div>
  <?php endif; ?>

  <!-- ─ State gate: COMPLETED / LOCKED ─ -->
  <?php if ($visit['status'] === 'completed' || $isLocked): ?>
  <div class="alert alert-success mx-3 mt-3">
    <i data-feather="check-circle" class="mr-2"></i>
    This visit is completed<?= $isLocked ? ' and locked' : '' ?>.
    <?php if (!empty($visit['pdf_path'])): ?>
    <a href="<?= htmlspecialchars($visit['pdf_path']) ?>" target="_blank" class="alert-link ml-2">Download PDF</a>
    <?php endif; ?>
  </div>
  <?php endif; ?>

  <!-- ─ Work Tabs (shown when in_progress or admin) ─ -->
  <?php if ($visit['status'] === 'in_progress' || $isAdmin): ?>
  <ul class="nav nav-tabs pow-work-tabs" id="work-tabs">
    <li class="nav-item"><a class="nav-link active" href="#tab-checklist" data-toggle="tab"><i data-feather="check-square"></i><br><small>Checklist</small></a></li>
    <li class="nav-item"><a class="nav-link" href="#tab-materials" data-toggle="tab"><i data-feather="package"></i><br><small>Materials</small></a></li>
    <li class="nav-item"><a class="nav-link" href="#tab-service" data-toggle="tab"><i data-feather="tool"></i><br><small>Details</small></a></li>
    <li class="nav-item"><a class="nav-link" href="#tab-photos" data-toggle="tab" id="photos-tab-link"><i data-feather="camera"></i><br><small>Photos <span id="photo-count-badge" class="badge badge-success" style="display:none;">0</span></small></a></li>
    <li class="nav-item"><a class="nav-link" href="#tab-notes" data-toggle="tab"><i data-feather="edit-3"></i><br><small>Notes</small></a></li>
  </ul>

  <div class="tab-content pow-work-tab-content">

    <!-- ── CHECKLIST TAB ────────────────────────────────────────────── -->
    <div class="tab-pane fade show active" id="tab-checklist">
      <div class="pow-tab-header">
        <strong>Completion Checklist</strong>
        <button class="btn btn-sm btn-success" id="btn-save-checklist">Save</button>
      </div>
      <div id="checklist-items">
        <?php if (empty($checklist)): ?>
        <p class="text-muted p-3">No checklist defined for this plan.</p>
        <?php else: foreach ($checklist as $idx => $item): ?>
        <div class="pow-checklist-row" data-idx="<?= $idx ?>">
          <label class="pow-check-label">
            <input type="checkbox" class="check-item" data-idx="<?= $idx ?>"
                   <?= !empty($item['checked']) ? 'checked' : '' ?>>
            <span><?= htmlspecialchars($item['item'] ?? '') ?></span>
          </label>
          <input type="text" class="form-control form-control-sm check-note mt-1"
                 placeholder="Note (optional)"
                 data-idx="<?= $idx ?>"
                 value="<?= htmlspecialchars($item['note'] ?? '') ?>">
        </div>
        <?php endforeach; endif; ?>
      </div>
      <!-- Add custom item -->
      <div class="p-3 border-top">
        <div class="input-group input-group-sm">
          <input type="text" id="new-check-item" class="form-control" placeholder="Add checklist item…">
          <div class="input-group-append">
            <button class="btn btn-outline-secondary" id="btn-add-check">Add</button>
          </div>
        </div>
      </div>
    </div>

    <!-- ── MATERIALS TAB ─────────────────────────────────────────────── -->
    <div class="tab-pane fade" id="tab-materials">
      <div class="pow-tab-header">
        <strong>Materials Used</strong>
        <button class="btn btn-sm btn-success" id="btn-save-materials">Save</button>
      </div>
      <div id="materials-list">
        <?php if (empty($materials)): ?>
        <p class="text-muted p-3 text-center" id="no-materials-msg">No materials recorded yet.</p>
        <?php else: foreach ($materials as $mi => $m): ?>
        <div class="pow-material-row" data-mi="<?= $mi ?>">
          <div class="row no-gutters align-items-center">
            <div class="col-12 mb-1">
              <input type="text" class="form-control form-control-sm mat-name"
                     placeholder="Product name" value="<?= htmlspecialchars($m['name']??'') ?>">
            </div>
            <div class="col-4 pr-1">
              <input type="number" step="0.01" class="form-control form-control-sm mat-qty"
                     placeholder="Qty" value="<?= htmlspecialchars($m['qty']??'') ?>">
            </div>
            <div class="col-4 px-1">
              <input type="text" class="form-control form-control-sm mat-unit"
                     placeholder="Unit (kg/L)" value="<?= htmlspecialchars($m['unit']??'') ?>">
            </div>
            <div class="col-3 pl-1">
              <button class="btn btn-sm btn-outline-danger btn-block mat-remove">✕</button>
            </div>
          </div>
          <input type="text" class="form-control form-control-sm mt-1 mat-note"
                 placeholder="Note" value="<?= htmlspecialchars($m['note']??'') ?>">
        </div>
        <?php endforeach; endif; ?>
      </div>
      <div class="p-3 border-top">
        <button class="btn btn-outline-primary btn-sm btn-block" id="btn-add-material">
          <i data-feather="plus"></i> Add Material
        </button>
      </div>
    </div>

    <!-- ── SERVICE DETAILS TAB ──────────────────────────────────────── -->
    <div class="tab-pane fade" id="tab-service">
      <div class="pow-tab-header">
        <strong><?= htmlspecialchars(ucfirst(str_replace('_',' ',$visit['service_type']))) ?> Details</strong>
        <button class="btn btn-sm btn-success" id="btn-save-service">Save</button>
      </div>
      <div class="p-3" id="service-fields">
        <?php
        $st = $visit['service_type'];
        // Fertilizer / Lawn Treatment
        if (in_array($st, ['fertilizer','lawn_treatment'])): ?>
        <div class="form-group"><label class="small font-weight-600">Product Name</label>
          <input type="text" class="form-control" name="svc_product" value="<?= htmlspecialchars($svcData['product']??'') ?>"></div>
        <div class="form-group"><label class="small font-weight-600">Application Rate</label>
          <input type="text" class="form-control" name="svc_rate" placeholder="e.g. 5 kg/100m²" value="<?= htmlspecialchars($svcData['rate']??'') ?>"></div>
        <div class="form-group"><label class="small font-weight-600">Area Treated</label>
          <input type="text" class="form-control" name="svc_area" placeholder="e.g. 350 m²" value="<?= htmlspecialchars($svcData['area']??'') ?>"></div>
        <div class="form-group"><label class="small font-weight-600">Weather Conditions</label>
          <input type="text" class="form-control" name="svc_weather_note" placeholder="e.g. Overcast, light wind" value="<?= htmlspecialchars($svcData['weather_note']??'') ?>"></div>
        <div class="form-group"><label class="small font-weight-600">Temperature (°C)</label>
          <input type="number" class="form-control" name="svc_temp_c" value="<?= htmlspecialchars($svcData['temp_c']??'') ?>"></div>
        <?php elseif (in_array($st, ['salt','de_ice'])): ?>
        <div class="form-group"><label class="small font-weight-600">Salt Applied (kg)</label>
          <input type="number" step="0.1" class="form-control" name="svc_qty_kg" value="<?= htmlspecialchars($svcData['qty_kg']??'') ?>"></div>
        <div class="form-group"><label class="small font-weight-600">Area Cleared</label>
          <input type="text" class="form-control" name="svc_area" value="<?= htmlspecialchars($svcData['area']??'') ?>"></div>
        <div class="form-group"><label class="small font-weight-600">Air Temp (°C)</label>
          <input type="number" class="form-control" name="svc_temp_c" value="<?= htmlspecialchars($svcData['temp_c']??'') ?>"></div>
        <div class="form-group"><label class="small font-weight-600">Priority Zones</label>
          <input type="text" class="form-control" name="svc_priority_zones" placeholder="e.g. Front steps, ramp" value="<?= htmlspecialchars($svcData['priority_zones']??'') ?>"></div>
        <div class="form-group"><label class="small font-weight-600">Ice Conditions</label>
          <select class="form-control" name="svc_conditions">
            <?php foreach(['dry','wet','black ice','packed snow','mixed'] as $cond): ?>
            <option value="<?= $cond ?>" <?= ($svcData['conditions']??'')===$cond?'selected':'' ?>><?= ucfirst($cond) ?></option>
            <?php endforeach; ?>
          </select>
        </div>
        <div class="form-group"><label class="small font-weight-600">Compliance Note</label>
          <input type="text" class="form-control" name="svc_compliance" value="<?= htmlspecialchars($svcData['compliance']??'') ?>"></div>
        <?php elseif (in_array($st, ['snow','snow_clearance'])): ?>
        <div class="form-group"><label class="small font-weight-600">Snow Depth (cm)</label>
          <input type="number" step="0.5" class="form-control" name="svc_depth_cm" value="<?= htmlspecialchars($svcData['depth_cm']??'') ?>"></div>
        <div class="form-group"><label class="small font-weight-600">Equipment Used</label>
          <input type="text" class="form-control" name="svc_equipment" placeholder="e.g. Bobcat, shovel" value="<?= htmlspecialchars($svcData['equipment']??'') ?>"></div>
        <div class="form-group"><label class="small font-weight-600">Air Temp (°C)</label>
          <input type="number" class="form-control" name="svc_temp_c" value="<?= htmlspecialchars($svcData['temp_c']??'') ?>"></div>
        <div class="form-group"><label class="small font-weight-600">Hazards Noted</label>
          <input type="text" class="form-control" name="svc_hazards" placeholder="e.g. Icy steps" value="<?= htmlspecialchars($svcData['hazards']??'') ?>"></div>
        <div class="form-group"><label class="small font-weight-600">Push / Stack Location</label>
          <input type="text" class="form-control" name="svc_push_location" value="<?= htmlspecialchars($svcData['push_location']??'') ?>"></div>
        <div class="form-group"><label class="small font-weight-600">
          <input type="checkbox" name="svc_salt_applied" value="1" <?= !empty($svcData['salt_applied'])?'checked':'' ?>>
          Salt applied after clearance
        </label></div>
        <?php else: ?>
        <div class="form-group"><label class="small font-weight-600">General Notes</label>
          <textarea class="form-control" name="svc_notes" rows="5"><?= htmlspecialchars($svcData['notes']??'') ?></textarea>
        </div>
        <?php endif; ?>
      </div>
    </div>

    <!-- ── PHOTOS TAB ────────────────────────────────────────────────── -->
    <div class="tab-pane fade" id="tab-photos">
      <div class="pow-tab-header">
        <strong>Site Photos</strong>
        <span class="text-muted small" id="photo-upload-status"></span>
      </div>

      <!-- Capture buttons: Before / After / Additional -->
      <div class="mw-photo-capture-row">
        <label class="mw-photo-btn mw-photo-btn--before">
          <i data-feather="camera"></i>
          <span>Before</span>
          <input type="file" accept="image/*" capture="environment"
                 class="photo-trigger" data-type="before" style="display:none">
        </label>
        <label class="mw-photo-btn mw-photo-btn--after">
          <i data-feather="camera"></i>
          <span>After</span>
          <input type="file" accept="image/*" capture="environment"
                 class="photo-trigger" data-type="after" style="display:none">
        </label>
        <label class="mw-photo-btn mw-photo-btn--additional">
          <i data-feather="plus-square"></i>
          <span>Additional</span>
          <input type="file" accept="image/*" multiple
                 class="photo-trigger" data-type="additional" style="display:none">
        </label>
      </div>

      <!-- Loading state while fetching existing photos -->
      <div id="photos-loading" class="text-center p-3 text-muted small">
        <span class="spinner-border spinner-border-sm mr-1"></span> Loading photos…
      </div>

      <!-- Photo sections: Before, After, Additional -->
      <div id="photos-container" style="display:none;">
        <div class="mw-photo-section" id="section-before">
          <div class="mw-photo-section-title">
            <span class="mw-photo-type-dot mw-photo-type-dot--before"></span>Before
            <span class="mw-photo-section-count" id="count-before">0</span>
          </div>
          <div class="mw-photo-grid" id="grid-before"></div>
        </div>
        <div class="mw-photo-section" id="section-after">
          <div class="mw-photo-section-title">
            <span class="mw-photo-type-dot mw-photo-type-dot--after"></span>After
            <span class="mw-photo-section-count" id="count-after">0</span>
          </div>
          <div class="mw-photo-grid" id="grid-after"></div>
        </div>
        <div class="mw-photo-section" id="section-additional">
          <div class="mw-photo-section-title">
            <span class="mw-photo-type-dot mw-photo-type-dot--additional"></span>Additional
            <span class="mw-photo-section-count" id="count-additional">0</span>
          </div>
          <div class="mw-photo-grid" id="grid-additional"></div>
        </div>
      </div>
    </div>

    <!-- ── NOTES TAB ─────────────────────────────────────────────────── -->
    <div class="tab-pane fade" id="tab-notes">
      <div class="pow-tab-header"><strong>Crew Notes</strong></div>
      <div class="p-3">
        <textarea id="crew-note-text" class="form-control" rows="5"
                  placeholder="Describe anything notable about this visit…"></textarea>
        <div class="d-flex justify-content-between mt-2">
          <label class="small text-muted"><input type="checkbox" id="note-client-visible"> Visible to client</label>
          <button class="btn btn-success btn-sm" id="btn-submit-note">Add Note</button>
        </div>
        <hr>
        <div id="submitted-notes" class="text-muted small">Notes saved will appear here.</div>
      </div>
    </div>

  </div><!-- /tab-content -->

  <?php endif; ?><!-- /work tabs -->

  <!-- ─ End Visit CTA ─ -->
  <?php if ($visit['status'] === 'in_progress'): ?>
  <div class="pow-end-zone">
    <div id="offline-banner" class="alert alert-warning text-center py-2 mb-2" style="display:none;">
      <i data-feather="wifi-off"></i> Offline — data will sync when reconnected
    </div>
    <div id="pending-sync" class="text-muted text-center small mb-2" style="display:none;">
      <span id="pending-count">0</span> GPS points pending sync
    </div>
    <button class="btn btn-danger btn-lg btn-block" id="btn-end-visit">
      <i data-feather="stop-circle" class="mr-2"></i>End Visit &amp; Finalize
    </button>
    <?php if ($isAdmin): ?>
    <button class="btn btn-outline-secondary btn-sm btn-block mt-2" id="btn-generate-pdf-work">
      <i data-feather="file-text" class="mr-1"></i>Generate PoW PDF
    </button>
    <?php endif; ?>
  </div>
  <?php endif; ?>

</div><!-- /pow-work-app -->

<input type="hidden" id="pow-visit-id" value="<?= $visitId ?>">
<input type="hidden" id="pow-csrf" value="<?= htmlspecialchars($csrfToken) ?>">
<input type="hidden" id="pow-service-type" value="<?= htmlspecialchars($visit['service_type']) ?>">
<input type="hidden" id="pow-status" value="<?= htmlspecialchars($visit['status']) ?>">
<input type="hidden" id="pow-started-at" value="<?= htmlspecialchars($visit['started_at'] ?? '') ?>">
<input type="hidden" id="pow-extras-rate" value="<?= htmlspecialchars((string)$extrasRatePerBlock) ?>">

<!-- ─ Billable Extras Modal ───────────────────────────────────────────────── -->
<?php if ($visit['status'] === 'in_progress'): ?>
<div id="mw-extras-modal" class="mw-extras-modal" role="dialog" aria-modal="true" aria-labelledby="mw-extras-title">
  <div class="mw-extras-modal-inner">

    <div class="mw-extras-header">
      <button class="mw-extras-close" id="btn-extras-close" aria-label="Cancel — return to visit">
        <svg xmlns="http://www.w3.org/2000/svg" width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><line x1="18" y1="6" x2="6" y2="18"/><line x1="6" y1="6" x2="18" y2="18"/></svg>
      </button>
      <h2 class="mw-extras-title" id="mw-extras-title">Add-On Services</h2>
      <p class="mw-extras-subtitle">Did the client ask for anything extra?</p>
    </div>

    <div class="mw-extras-timer-section">
      <div class="mw-extras-timer-display" id="extras-timer-display">0:00</div>
      <button class="mw-extras-timer-btn" id="btn-extras-timer">
        <svg xmlns="http://www.w3.org/2000/svg" width="18" height="18" viewBox="0 0 24 24" fill="currentColor" style="flex-shrink:0"><polygon points="5,3 19,12 5,21"/></svg>
        Start Timer
      </button>
    </div>

    <div class="mw-extras-manual-section">
      <p class="mw-extras-label">Quick-add minutes</p>
      <div class="mw-extras-block-btns">
        <button class="mw-extras-block-btn" data-mins="5">+5</button>
        <button class="mw-extras-block-btn" data-mins="10">+10</button>
        <button class="mw-extras-block-btn" data-mins="15">+15</button>
        <button class="mw-extras-block-btn" data-mins="30">+30</button>
      </div>
    </div>

    <div class="mw-extras-total-section">
      <div class="mw-extras-total-amount" id="extras-total-amount">$0.00</div>
      <p class="mw-extras-total-meta" id="extras-total-meta">No extras added</p>
    </div>

    <div class="mw-extras-note-section">
      <p class="mw-extras-label">Note to client <span style="font-weight:400;text-transform:none;letter-spacing:0;">(optional)</span></p>
      <textarea class="mw-extras-note-input" id="extras-note" rows="3"
                placeholder="e.g. Trimmed hedge by gate as requested — 15 extra min."></textarea>
    </div>

    <div class="mw-extras-actions">
      <button class="mw-extras-skip-btn" id="btn-extras-skip">No Extras — Complete Visit</button>
      <button class="mw-extras-confirm-btn" id="btn-extras-confirm">Add to Invoice &amp; Complete</button>
    </div>

  </div>
</div>
<?php endif; ?>

<script>
(function() {
  'use strict';

  var VISIT_ID    = parseInt(document.getElementById('pow-visit-id').value, 10);
  var CSRF        = document.getElementById('pow-csrf').value;
  var STATUS      = document.getElementById('pow-status').value;
  var STARTED_AT  = document.getElementById('pow-started-at').value;
  var API_ACTIONS = '/crm/api/pow-actions.php';
  var API_GPS     = '/crm/api/pow-gps-sync.php';
  var API_PHOTOS  = '/crm/api/job-photo.php';

  // ── GPS Buffer (IndexedDB-backed) ─────────────────────────────────────────
  var gpsBuffer = [];
  var gpsCount  = 0;
  var totalDist = 0;
  var lastPos   = null;
  var syncTimer = null;
  var isOnline  = navigator.onLine;

  // ── Timer ─────────────────────────────────────────────────────────────────
  var timerEl = document.getElementById('timer-display');
  if (timerEl && STATUS === 'in_progress' && STARTED_AT) {
    var startMs = new Date(STARTED_AT).getTime();
    setInterval(function() {
      var elapsed = Math.floor((Date.now() - startMs) / 1000);
      var h = Math.floor(elapsed / 3600);
      var m = Math.floor((elapsed % 3600) / 60);
      var s = elapsed % 60;
      timerEl.textContent = (h > 0 ? h + ':' : '') + String(m).padStart(2,'0') + ':' + String(s).padStart(2,'0');
    }, 1000);
  }

  function updateStats() {
    var pts = document.getElementById('gps-pts-display');
    var dst = document.getElementById('dist-display');
    if (pts) pts.textContent = gpsCount;
    if (dst) dst.textContent = totalDist >= 1000 ? (totalDist/1000).toFixed(1)+'km' : Math.round(totalDist)+'m';
  }

  function haversine(lat1, lng1, lat2, lng2) {
    var R  = 6371000;
    var dL = (lat2-lat1)*Math.PI/180;
    var dN = (lng2-lng1)*Math.PI/180;
    var a  = Math.sin(dL/2)*Math.sin(dL/2) +
             Math.cos(lat1*Math.PI/180)*Math.cos(lat2*Math.PI/180)*Math.sin(dN/2)*Math.sin(dN/2);
    return R*2*Math.atan2(Math.sqrt(a),Math.sqrt(1-a));
  }

  // ── GPS Tracking ──────────────────────────────────────────────────────────
  var watchId = null;
  var statusDot = document.getElementById('status-dot');

  function startGPS() {
    if (!navigator.geolocation && !(window.MwNative && window.MwNative.isNative)) {
      if (statusDot) statusDot.style.background = '#ccc';
      return;
    }
    if (statusDot) statusDot.style.background = '#ffc107';

    if (window.MwNative && window.MwNative.isNative) {
      window.MwNative.geo.startBackgroundTracking(function(pos, err) {
        if (err || !pos) return;
        acceptPosition(pos.lat, pos.lng, pos.accuracy, pos.speed, pos.heading);
      }, { distanceFilter: 5 });
    } else {
      watchId = navigator.geolocation.watchPosition(function(p) {
        acceptPosition(p.coords.latitude, p.coords.longitude, p.coords.accuracy,
                       p.coords.speed, p.coords.heading);
      }, function(e) {
        console.warn('GPS error:', e.message);
        if (statusDot) statusDot.style.background = '#dc3545';
      }, { enableHighAccuracy: true, maximumAge: 5000, timeout: 30000 });
    }

    syncTimer = setInterval(flushGpsBuffer, 30000);
  }

  function stopGPS() {
    if (watchId !== null) navigator.geolocation.clearWatch(watchId);
    if (window.MwNative && window.MwNative.isNative) window.MwNative.geo.stopBackgroundTracking();
    clearInterval(syncTimer);
    flushGpsBuffer();
  }

  function acceptPosition(lat, lng, accuracy, speed, heading) {
    if (accuracy && accuracy > 50) return;
    if (statusDot) statusDot.style.background = '#2D8659';

    var accEl = document.getElementById('accuracy-display');
    if (accEl && accuracy) accEl.textContent = Math.round(accuracy) + 'm';

    if (lastPos) {
      var d = haversine(lastPos.lat, lastPos.lng, lat, lng);
      if (d < 2) return;
      totalDist += d;
    }
    lastPos = { lat: lat, lng: lng };

    gpsBuffer.push({
      lat: lat, lng: lng, accuracy: accuracy,
      speed: speed || 0, heading: heading || 0,
      timestamp: Date.now(), source: 'fused'
    });
    gpsCount++;
    updateStats();
    saveToIndexedDB({ lat: lat, lng: lng, accuracy: accuracy, speed: speed, heading: heading, timestamp: Date.now() });
  }

  function flushGpsBuffer() {
    if (!isOnline || gpsBuffer.length === 0) { updatePendingBanner(); return; }
    var toSend = gpsBuffer.splice(0, 100);
    fetch(API_GPS, {
      method: 'POST',
      headers: { 'Content-Type': 'application/json' },
      credentials: 'same-origin',
      body: JSON.stringify({ action: 'sync_points', visit_id: VISIT_ID, points: toSend })
    }).then(function(r) { return r.json(); }).then(function(res) {
      if (!res.success) gpsBuffer = toSend.concat(gpsBuffer);
      updatePendingBanner();
    }).catch(function() {
      gpsBuffer = toSend.concat(gpsBuffer);
      updatePendingBanner();
    });
  }

  function updatePendingBanner() {
    var pendingEl = document.getElementById('pending-sync');
    var countEl   = document.getElementById('pending-count');
    if (!pendingEl) return;
    if (gpsBuffer.length > 0) {
      pendingEl.style.display = 'block';
      if (countEl) countEl.textContent = gpsBuffer.length;
    } else {
      pendingEl.style.display = 'none';
    }
  }

  // ── IndexedDB ─────────────────────────────────────────────────────────────
  var db_idb = null;
  (function initIDB() {
    var req = indexedDB.open('mow_pow_v1', 1);
    req.onupgradeneeded = function(e) {
      var db = e.target.result;
      if (!db.objectStoreNames.contains('gps_points')) {
        var store = db.createObjectStore('gps_points', { keyPath: 'id', autoIncrement: true });
        store.createIndex('visit_id', 'visit_id', { unique: false });
      }
    };
    req.onsuccess = function(e) {
      db_idb = e.target.result;
      loadUnsyncedFromIDB();
    };
    req.onerror = function() { console.warn('IndexedDB unavailable'); };
  })();

  function saveToIndexedDB(pt) {
    if (!db_idb) return;
    var tx    = db_idb.transaction('gps_points', 'readwrite');
    var store = tx.objectStore('gps_points');
    store.add(Object.assign({ visit_id: VISIT_ID }, pt));
  }

  function loadUnsyncedFromIDB() {
    if (!db_idb) return;
    var tx    = db_idb.transaction('gps_points', 'readonly');
    var store = tx.objectStore('gps_points');
    var idx   = store.index('visit_id');
    var req   = idx.getAll(VISIT_ID);
    req.onsuccess = function(e) {
      var pts = e.target.result || [];
      if (pts.length > 0) {
        gpsBuffer = pts.map(function(p) {
          return { lat:p.lat, lng:p.lng, accuracy:p.accuracy, speed:p.speed,
                   heading:p.heading, timestamp:p.timestamp, source:'indexed_db' };
        });
        gpsCount  = pts.length;
        updateStats();
        updatePendingBanner();
      }
    };
  }

  // ── Online/offline ────────────────────────────────────────────────────────
  window.addEventListener('online',  function() { isOnline = true;  updateOfflineBanner(); flushGpsBuffer(); });
  window.addEventListener('offline', function() { isOnline = false; updateOfflineBanner(); });
  function updateOfflineBanner() {
    var b = document.getElementById('offline-banner');
    if (b) b.style.display = isOnline ? 'none' : 'block';
  }

  // ── Start Visit ───────────────────────────────────────────────────────────
  var btnStart = document.getElementById('btn-start-visit');
  if (btnStart) {
    btnStart.addEventListener('click', function() {
      btnStart.disabled = true;
      btnStart.innerHTML = '<span class="spinner-border spinner-border-sm mr-2"></span>Starting…';

      function doStart(lat, lng) {
        fetch(API_ACTIONS, {
          method: 'POST',
          headers: { 'Content-Type': 'application/json' },
          credentials: 'same-origin',
          body: JSON.stringify({ action: 'start_visit', visit_id: VISIT_ID, csrf_token: CSRF,
                                 lat: lat, lng: lng })
        }).then(function(r) { return r.json(); }).then(function(res) {
          if (res.success) {
            document.getElementById('pow-started-at').value = res.started_at;
            STARTED_AT = res.started_at;
            STATUS     = 'in_progress';
            location.reload();
          } else {
            alert(res.error || 'Could not start visit');
            btnStart.disabled = false;
            btnStart.innerHTML = '<i data-feather="play" class="mr-2"></i>Start Visit';
          }
        });
      }

      if (navigator.geolocation) {
        navigator.geolocation.getCurrentPosition(function(p) {
          doStart(p.coords.latitude, p.coords.longitude);
        }, function() { doStart(null, null); }, { enableHighAccuracy: true, timeout: 8000 });
      } else {
        doStart(null, null);
      }
    });
  }

  if (STATUS === 'in_progress') startGPS();

  document.addEventListener('mw-visit-gps-point', function(e) {
    var p = e.detail;
    if (p.visit_id && p.visit_id !== VISIT_ID) return;
    acceptPosition(p.lat, p.lng, p.accuracy, p.speed, p.heading);
  });

  // ── Checklist ─────────────────────────────────────────────────────────────
  var btnSaveCheck = document.getElementById('btn-save-checklist');
  if (btnSaveCheck) {
    btnSaveCheck.addEventListener('click', function() {
      var items = [];
      document.querySelectorAll('.pow-checklist-row').forEach(function(row) {
        var cb   = row.querySelector('.check-item');
        var note = row.querySelector('.check-note');
        var text = row.querySelector('span');
        items.push({ item: text ? text.textContent : '', checked: cb ? cb.checked : false, note: note ? note.value : '' });
      });
      powPost('save_checklist', { items: items }).then(function(res) {
        showToast(res.success ? 'Checklist saved' : (res.error || 'Save failed'), res.success ? 'success' : 'danger');
      });
    });
  }

  var btnAddCheck = document.getElementById('btn-add-check');
  if (btnAddCheck) {
    btnAddCheck.addEventListener('click', function() {
      var inp  = document.getElementById('new-check-item');
      var text = inp.value.trim();
      if (!text) return;
      var list = document.getElementById('checklist-items');
      var idx  = list.querySelectorAll('.pow-checklist-row').length;
      var row  = document.createElement('div');
      row.className = 'pow-checklist-row';
      row.dataset.idx = idx;
      row.innerHTML = '<label class="pow-check-label"><input type="checkbox" class="check-item" data-idx="' + idx + '"><span>' + text + '</span></label>'
                    + '<input type="text" class="form-control form-control-sm check-note mt-1" placeholder="Note" data-idx="' + idx + '">';
      list.appendChild(row);
      inp.value = '';
    });
  }

  // ── Materials ─────────────────────────────────────────────────────────────
  var btnAddMat = document.getElementById('btn-add-material');
  if (btnAddMat) {
    btnAddMat.addEventListener('click', function() {
      var list = document.getElementById('materials-list');
      var nm   = document.getElementById('no-materials-msg');
      if (nm) nm.remove();
      var idx  = list.querySelectorAll('.pow-material-row').length;
      var row  = document.createElement('div');
      row.className = 'pow-material-row p-3 border-bottom';
      row.dataset.mi = idx;
      row.innerHTML = '<div class="row no-gutters align-items-center"><div class="col-12 mb-1"><input type="text" class="form-control form-control-sm mat-name" placeholder="Product name"></div><div class="col-4 pr-1"><input type="number" step="0.01" class="form-control form-control-sm mat-qty" placeholder="Qty"></div><div class="col-4 px-1"><input type="text" class="form-control form-control-sm mat-unit" placeholder="Unit (kg/L)"></div><div class="col-3 pl-1"><button class="btn btn-sm btn-outline-danger btn-block mat-remove">✕</button></div></div><input type="text" class="form-control form-control-sm mt-1 mat-note" placeholder="Note">';
      list.appendChild(row);
    });
  }
  document.addEventListener('click', function(e) {
    if (e.target.classList.contains('mat-remove')) e.target.closest('.pow-material-row').remove();
  });

  var btnSaveMat = document.getElementById('btn-save-materials');
  if (btnSaveMat) {
    btnSaveMat.addEventListener('click', function() {
      var items = [];
      document.querySelectorAll('.pow-material-row').forEach(function(row) {
        items.push({
          name: row.querySelector('.mat-name')?.value || '',
          qty:  row.querySelector('.mat-qty')?.value  || '',
          unit: row.querySelector('.mat-unit')?.value || '',
          note: row.querySelector('.mat-note')?.value || ''
        });
      });
      powPost('save_materials', { items: items }).then(function(res) {
        showToast(res.success ? 'Materials saved' : (res.error || 'Save failed'), res.success ? 'success' : 'danger');
      });
    });
  }

  // ── Service data ──────────────────────────────────────────────────────────
  var btnSvcSave = document.getElementById('btn-save-service');
  if (btnSvcSave) {
    btnSvcSave.addEventListener('click', function() {
      var data = {};
      document.querySelectorAll('#service-fields [name]').forEach(function(el) {
        var key = el.name.replace('svc_', '');
        data[key] = el.type === 'checkbox' ? el.checked : el.value;
      });
      powPost('save_service_data', { data: data }).then(function(res) {
        showToast(res.success ? 'Details saved' : (res.error||'Failed'), res.success ? 'success' : 'danger');
      });
    });
  }

  // ── Notes ─────────────────────────────────────────────────────────────────
  var btnNote = document.getElementById('btn-submit-note');
  if (btnNote) {
    btnNote.addEventListener('click', function() {
      var content = document.getElementById('crew-note-text').value.trim();
      var visible = document.getElementById('note-client-visible').checked ? 1 : 0;
      if (!content) { showToast('Enter a note first.', 'warning'); return; }
      powPost('save_notes', { content: content, visible_to_customer: visible }).then(function(res) {
        if (res.success) {
          document.getElementById('crew-note-text').value = '';
          var notes = document.getElementById('submitted-notes');
          var d = document.createElement('div');
          d.className = 'mb-2 p-2 rounded';
          d.style.cssText = 'background:#f5faf7;border-left:3px solid #7FD858;';
          d.innerHTML = '<div>' + content + '</div><small class="text-muted">Just now</small>';
          notes.insertBefore(d, notes.firstChild);
          showToast('Note saved', 'success');
        } else showToast(res.error || 'Failed', 'danger');
      });
    });
  }

  // ── Photo system ──────────────────────────────────────────────────────────
  var photoCounts = { before: 0, after: 0, additional: 0 };

  // Load existing photos when tab is focused
  var photosTabLink = document.getElementById('photos-tab-link');
  var photosLoaded  = false;
  if (photosTabLink) {
    photosTabLink.addEventListener('shown.bs.tab', loadPhotos);
    photosTabLink.addEventListener('show.bs.tab', loadPhotos); // Bootstrap 4
  }
  // Also load on jQuery tab event (Bootstrap 4 uses jQuery)
  if (typeof $ !== 'undefined') {
    $(document).on('shown.bs.tab', 'a[href="#tab-photos"]', loadPhotos);
  }

  function loadPhotos() {
    if (photosLoaded) return;
    photosLoaded = true;
    fetch(API_PHOTOS + '?action=list&visit_id=' + VISIT_ID, { credentials: 'same-origin' })
      .then(function(r) { return r.json(); })
      .then(function(res) {
        document.getElementById('photos-loading').style.display = 'none';
        document.getElementById('photos-container').style.display = 'block';
        if (res.success && res.photos) {
          res.photos.forEach(function(ph) { appendServerPhoto(ph); });
          updatePhotoBadge();
        }
      })
      .catch(function() {
        document.getElementById('photos-loading').style.display = 'none';
        document.getElementById('photos-container').style.display = 'block';
      });
  }

  // If photos tab is already active on load (e.g., user navigated directly), load now
  if (document.getElementById('tab-photos') &&
      document.getElementById('tab-photos').classList.contains('active')) {
    loadPhotos();
  }

  // Update the photo count badge on the tab
  function updatePhotoBadge() {
    var total = photoCounts.before + photoCounts.after + photoCounts.additional;
    var badge = document.getElementById('photo-count-badge');
    if (badge) {
      badge.textContent = total;
      badge.style.display = total > 0 ? 'inline' : 'none';
    }
    ['before','after','additional'].forEach(function(type) {
      var countEl = document.getElementById('count-' + type);
      if (countEl) countEl.textContent = photoCounts[type];
      var section = document.getElementById('section-' + type);
      if (section) section.style.display = photoCounts[type] > 0 ? 'block' : 'none';
    });
  }

  // Append a server-confirmed photo tile to the correct grid
  function appendServerPhoto(ph) {
    var type   = ph.type || 'additional';
    var bucket = ['before','after','additional'].indexOf(type) >= 0 ? type : 'additional';
    var grid   = document.getElementById('grid-' + bucket);
    if (!grid) return;

    photoCounts[bucket] = (photoCounts[bucket] || 0) + 1;

    var tile  = document.createElement('div');
    tile.className = 'mw-photo-tile';
    tile.dataset.photoId   = ph.id || '';
    tile.dataset.photoType = type;
    tile.dataset.viewUrl   = ph.view_url || ph.orig_url || '';
    tile.innerHTML =
      '<img src="' + escHtml(ph.thumb_url || ph.orig_url || '') + '"' +
           ' loading="lazy" alt="' + escHtml(type) + '"' +
           ' onerror="this.src=\'/crm/img/img-error.svg\'">' +
      '<div class="mw-photo-tile-overlay"></div>';
    tile.addEventListener('click', function() {
      openLightbox(tile.dataset.viewUrl, type, null, ph.id);
    });
    grid.appendChild(tile);
  }

  // Photo capture: optimistic upload (supports multiple files for Additional)
  document.querySelectorAll('.photo-trigger').forEach(function(input) {
    input.addEventListener('change', function() {
      var photoType = this.dataset.type;
      var files     = Array.from(this.files);
      if (!files.length) return;

      // Ensure photos container is visible
      photosLoaded = true;
      document.getElementById('photos-loading').style.display = 'none';
      document.getElementById('photos-container').style.display = 'block';

      // Upload each selected file (one at a time, each with its own progress tile)
      files.forEach(function(file) { uploadPhotoFile(file, photoType); });

      this.value = ''; // Reset so the same file(s) can be selected again if needed
    });
  });

  function uploadPhotoFile(file, photoType) {
    var bucket  = ['before','after','additional'].indexOf(photoType) >= 0 ? photoType : 'additional';
    var grid    = document.getElementById('grid-' + bucket);

    // ── Optimistic: blob preview immediately ──────────────────────────────
    var blobUrl = URL.createObjectURL(file);

    photoCounts[bucket] = (photoCounts[bucket] || 0) + 1;
    updatePhotoBadge();

    var tile = document.createElement('div');
    tile.className = 'mw-photo-tile mw-photo-tile--uploading';
    tile.innerHTML =
      '<img src="' + blobUrl + '" alt="Uploading…">' +
      '<div class="mw-photo-tile-overlay">' +
        '<div class="mw-upload-ring"></div>' +
      '</div>';
    grid.appendChild(tile);

    // ── XHR upload ────────────────────────────────────────────────────────
    var fd = new FormData();
    fd.append('photo',      file);
    fd.append('visit_id',   VISIT_ID);
    fd.append('action',     'upload_photo');
    fd.append('csrf_token', CSRF);
    fd.append('photo_type', photoType);

    var xhr = new XMLHttpRequest();
    xhr.open('POST', API_ACTIONS, true);
    xhr.withCredentials = true;

    xhr.upload.onprogress = function(e) {
      if (e.lengthComputable) {
        var pct  = Math.round(e.loaded / e.total * 100);
        var ring = tile.querySelector('.mw-upload-ring');
        if (ring) ring.style.setProperty('--pct', pct + '%');
      }
    };

    xhr.onload = function() {
      var res = {};
      try { res = JSON.parse(xhr.responseText); } catch(e) {}
      URL.revokeObjectURL(blobUrl);

      if (res.success) {
        tile.classList.remove('mw-photo-tile--uploading');
        tile.dataset.photoId = res.photo_id || '';
        tile.dataset.viewUrl = res.view_url  || res.orig_url || '';
        var img = tile.querySelector('img');
        if (img) img.src = res.thumb_url || res.orig_url || '';
        var overlay = tile.querySelector('.mw-photo-tile-overlay');
        if (overlay) overlay.innerHTML = '';
        tile.addEventListener('click', function() {
          openLightbox(tile.dataset.viewUrl, photoType, null, res.photo_id);
        });
        showToast('Photo saved', 'success');
      } else {
        tile.classList.remove('mw-photo-tile--uploading');
        tile.classList.add('mw-photo-tile--failed');
        var overlay2 = tile.querySelector('.mw-photo-tile-overlay');
        if (overlay2) {
          overlay2.innerHTML = '<button class="mw-photo-retry-btn" title="Retry">↺</button>';
          overlay2.querySelector('.mw-photo-retry-btn').addEventListener('click', function(e) {
            e.stopPropagation();
            tile.remove();
            photoCounts[bucket]--;
            updatePhotoBadge();
            showToast('Photo removed. Please try again.', 'warning');
          });
        }
        showToast(res.error || 'Upload failed', 'danger');
      }
    };

    xhr.onerror = function() {
      URL.revokeObjectURL(blobUrl);
      tile.classList.add('mw-photo-tile--failed');
      var ov = tile.querySelector('.mw-photo-tile-overlay');
      if (ov) ov.innerHTML = '<button class="mw-photo-retry-btn" title="Error">✕</button>';
      showToast('Network error — photo not saved', 'danger');
    };

    xhr.send(fd);
  }

  // ── Minimal lightbox ──────────────────────────────────────────────────────
  var lightbox = null;

  function openLightbox(imgUrl, type, caption, photoId) {
    if (!imgUrl) return;
    if (!lightbox) {
      lightbox = document.createElement('div');
      lightbox.className = 'mw-lightbox';
      lightbox.innerHTML =
        '<div class="mw-lightbox-backdrop"></div>' +
        '<div class="mw-lightbox-content">' +
          '<button class="mw-lightbox-close" aria-label="Close">✕</button>' +
          '<div class="mw-lightbox-type-label"></div>' +
          '<img class="mw-lightbox-img" src="" alt="">' +
          '<div class="mw-lightbox-caption"></div>' +
        '</div>';
      document.body.appendChild(lightbox);
      lightbox.querySelector('.mw-lightbox-backdrop').addEventListener('click', closeLightbox);
      lightbox.querySelector('.mw-lightbox-close').addEventListener('click', closeLightbox);
    }
    lightbox.querySelector('.mw-lightbox-img').src = imgUrl;
    lightbox.querySelector('.mw-lightbox-type-label').textContent = type ? type.charAt(0).toUpperCase() + type.slice(1) : '';
    lightbox.querySelector('.mw-lightbox-caption').textContent = caption || '';
    lightbox.style.display = 'flex';
    document.body.style.overflow = 'hidden';
  }

  function closeLightbox() {
    if (lightbox) lightbox.style.display = 'none';
    document.body.style.overflow = '';
  }

  document.addEventListener('keydown', function(e) {
    if (e.key === 'Escape') closeLightbox();
  });

  // ── End Visit + Billable Extras Modal ────────────────────────────────────
  var EXTRAS_RATE        = parseFloat((document.getElementById('pow-extras-rate') || {}).value) || 5.00;
  var extrasTimerIval    = null;
  var extrasSeconds      = 0;   // live timer accumulated seconds
  var extrasManualMins   = 0;   // quick-add manual minutes
  var extrasTimerRunning = false;
  var pendingLat         = null;
  var pendingLng         = null;

  function extrasTotal() {
    return (extrasSeconds > 0 ? Math.ceil(extrasSeconds / 60) : 0) + extrasManualMins;
  }

  function setTimerBtn(state) {
    var btn = document.getElementById('btn-extras-timer');
    if (!btn) return;
    var play  = '<svg xmlns="http://www.w3.org/2000/svg" width="18" height="18" viewBox="0 0 24 24" fill="currentColor" style="flex-shrink:0"><polygon points="5,3 19,12 5,21"/></svg> ';
    var pause = '<svg xmlns="http://www.w3.org/2000/svg" width="18" height="18" viewBox="0 0 24 24" fill="currentColor" style="flex-shrink:0"><rect x="6" y="4" width="4" height="16"/><rect x="14" y="4" width="4" height="16"/></svg> ';
    if (state === 'stop') {
      btn.className = 'mw-extras-timer-btn mw-extras-timer-running';
      btn.innerHTML = pause + 'Stop Timer';
    } else if (state === 'resume') {
      btn.className = 'mw-extras-timer-btn';
      btn.innerHTML = play + 'Resume Timer';
    } else {
      btn.className = 'mw-extras-timer-btn';
      btn.innerHTML = play + 'Start Timer';
    }
  }

  function updateExtrasDisplay() {
    var mins   = extrasTotal();
    var blocks = mins > 0 ? Math.ceil(mins / 5) : 0;
    var amount = blocks * EXTRAS_RATE;
    var amtEl  = document.getElementById('extras-total-amount');
    var metaEl = document.getElementById('extras-total-meta');
    if (amtEl)  amtEl.textContent = '$' + amount.toFixed(2);
    if (metaEl) {
      if (mins === 0) {
        metaEl.textContent = 'No extras added';
      } else {
        var billed = blocks * 5;
        metaEl.textContent = mins + ' min \u2192 ' + billed + ' min billed \u00b7 ' +
                             blocks + ' \u00d7 $' + EXTRAS_RATE.toFixed(2) + '/block';
      }
    }
  }

  function fmtTimer(secs) {
    var m = Math.floor(secs / 60), s = secs % 60;
    return m + ':' + (s < 10 ? '0' : '') + s;
  }

  function showExtrasModal() {
    clearInterval(extrasTimerIval);
    extrasTimerIval    = null;
    extrasSeconds      = 0;
    extrasManualMins   = 0;
    extrasTimerRunning = false;
    var disp = document.getElementById('extras-timer-display');
    if (disp) disp.textContent = '0:00';
    var note = document.getElementById('extras-note');
    if (note) note.value = '';
    setTimerBtn('start');
    updateExtrasDisplay();
    var modal = document.getElementById('mw-extras-modal');
    if (modal) modal.classList.add('mw-extras-modal-open');
    document.body.style.overflow = 'hidden';
  }

  function hideExtrasModal() {
    clearInterval(extrasTimerIval);
    extrasTimerIval    = null;
    extrasTimerRunning = false;
    var modal = document.getElementById('mw-extras-modal');
    if (modal) modal.classList.remove('mw-extras-modal-open');
    document.body.style.overflow = '';
  }

  function completeVisit(lat, lng, extrasMins, extrasNote) {
    var skipBtn    = document.getElementById('btn-extras-skip');
    var closeBtn   = document.getElementById('btn-extras-close');
    var confirmBtn = document.getElementById('btn-extras-confirm');
    if (skipBtn)    skipBtn.disabled    = true;
    if (closeBtn)   closeBtn.disabled   = true;
    if (confirmBtn) { confirmBtn.disabled = true; confirmBtn.textContent = 'Completing\u2026'; }

    powPost('end_visit', { lat: lat, lng: lng, extras_minutes: extrasMins, extras_note: extrasNote })
      .then(function(res) {
        if (res.success) {
          hideExtrasModal();
          showToast('Visit completed! Redirecting\u2026', 'success');
          setTimeout(function() { location.href = 'visit-detail.php?id=' + VISIT_ID; }, 1500);
        } else {
          // Re-enable modal buttons
          if (skipBtn)    skipBtn.disabled    = false;
          if (closeBtn)   closeBtn.disabled   = false;
          if (confirmBtn) { confirmBtn.disabled = false; confirmBtn.innerHTML = 'Add to Invoice &amp; Complete'; }

          // Photo compliance failure — show inline banner with jump-to-photos
          if (res.missing_types && res.missing_types.length) {
            hideExtrasModal();
            var typeLabels = { before: 'Before', after: 'After', additional: 'Additional', during: 'During', issue: 'Issue' };
            var missingNames = res.missing_types.map(function(t) { return typeLabels[t] || t; });
            var banner = document.getElementById('mw-photo-compliance-banner');
            if (!banner) {
              banner = document.createElement('div');
              banner.id = 'mw-photo-compliance-banner';
              banner.className = 'mw-photo-compliance-banner';
              var btnEnd2 = document.getElementById('btn-end-visit');
              if (btnEnd2 && btnEnd2.parentNode) btnEnd2.parentNode.insertBefore(banner, btnEnd2);
            }
            banner.innerHTML =
              '<div class="mw-pcb-icon">&#128247;</div>' +
              '<div class="mw-pcb-body">' +
                '<strong>Photos required before completing</strong>' +
                '<div class="mw-pcb-missing">Missing: <span>' + missingNames.join(', ') + '</span></div>' +
              '</div>' +
              '<button type="button" class="btn btn-sm btn-success mw-pcb-btn" onclick="document.getElementById(\'photos-tab-link\').click();document.getElementById(\'mw-photo-compliance-banner\').style.display=\'none\';">Go to Photos</button>';
            banner.style.display = 'flex';
            banner.scrollIntoView({ behavior: 'smooth', block: 'center' });
          } else {
            showToast(res.error || 'Could not complete visit', 'danger');
          }
        }
      })
      .catch(function() {
        showToast('Network error \u2014 please try again.', 'danger');
        if (skipBtn)    skipBtn.disabled    = false;
        if (closeBtn)   closeBtn.disabled   = false;
        if (confirmBtn) { confirmBtn.disabled = false; confirmBtn.innerHTML = 'Add to Invoice &amp; Complete'; }
      });
  }

  // End visit button → GPS capture → show modal
  var btnEnd = document.getElementById('btn-end-visit');
  if (btnEnd) {
    btnEnd.addEventListener('click', function() {
      stopGPS();
      btnEnd.disabled = true;
      btnEnd.innerHTML = '<span class="spinner-border spinner-border-sm mr-2"></span>Getting location\u2026';

      function gotGPS(lat, lng) {
        pendingLat = lat;
        pendingLng = lng;
        btnEnd.disabled = false;
        btnEnd.innerHTML = '<i data-feather="stop-circle" class="mr-2"></i>End Visit &amp; Finalize';
        feather.replace();
        showExtrasModal();
      }

      if (navigator.geolocation) {
        navigator.geolocation.getCurrentPosition(
          function(p) { gotGPS(p.coords.latitude, p.coords.longitude); },
          function()  { gotGPS(null, null); },
          { enableHighAccuracy: true, timeout: 8000 }
        );
      } else {
        gotGPS(null, null);
      }
    });
  }

  // Timer toggle
  var btnExtrasTimer = document.getElementById('btn-extras-timer');
  if (btnExtrasTimer) {
    btnExtrasTimer.addEventListener('click', function() {
      extrasTimerRunning = !extrasTimerRunning;
      if (extrasTimerRunning) {
        setTimerBtn('stop');
        extrasTimerIval = setInterval(function() {
          extrasSeconds++;
          var disp = document.getElementById('extras-timer-display');
          if (disp) disp.textContent = fmtTimer(extrasSeconds);
          updateExtrasDisplay();
        }, 1000);
      } else {
        setTimerBtn('resume');
        clearInterval(extrasTimerIval);
      }
    });
  }

  // Quick-add block buttons
  document.querySelectorAll('.mw-extras-block-btn').forEach(function(btn) {
    btn.addEventListener('click', function() {
      extrasManualMins += parseInt(this.dataset.mins, 10) || 0;
      updateExtrasDisplay();
    });
  });

  // X button = cancel (return to visit, no API call)
  var btnExtrasClose = document.getElementById('btn-extras-close');
  if (btnExtrasClose) {
    btnExtrasClose.addEventListener('click', function() {
      hideExtrasModal();
    });
  }

  // "No Extras" = complete with 0 extras
  var btnExtrasSkip = document.getElementById('btn-extras-skip');
  if (btnExtrasSkip) {
    btnExtrasSkip.addEventListener('click', function() {
      completeVisit(pendingLat, pendingLng, 0, '');
    });
  }

  // "Add to Invoice & Complete" = complete with extras
  var btnExtrasConfirm = document.getElementById('btn-extras-confirm');
  if (btnExtrasConfirm) {
    btnExtrasConfirm.addEventListener('click', function() {
      var mins = extrasTotal();
      var note = (document.getElementById('extras-note') || {}).value || '';
      completeVisit(pendingLat, pendingLng, mins, note.trim());
    });
  }

  // ── Generate PDF ──────────────────────────────────────────────────────────
  var btnPdfWork = document.getElementById('btn-generate-pdf-work');
  if (btnPdfWork) {
    btnPdfWork.addEventListener('click', function() {
      this.disabled = true;
      this.innerHTML = '<span class="spinner-border spinner-border-sm mr-1"></span>Generating…';
      powPost('generate_pdf', {}).then(function(res) {
        if (res.success && res.path) window.open(res.path, '_blank');
        else showToast(res.error || 'PDF generation failed', 'danger');
        btnPdfWork.disabled = false;
        btnPdfWork.innerHTML = '<i data-feather="file-text" class="mr-1"></i>Generate PoW PDF';
        feather.replace();
      });
    });
  }

  // ── Shared helpers ────────────────────────────────────────────────────────
  function powPost(action, extra) {
    var body = Object.assign({ action: action, visit_id: VISIT_ID, csrf_token: CSRF }, extra || {});
    return fetch(API_ACTIONS, {
      method: 'POST',
      headers: { 'Content-Type': 'application/json' },
      credentials: 'same-origin',
      body: JSON.stringify(body)
    }).then(function(r) { return r.json(); });
  }

  function showToast(msg, type) {
    var d = document.createElement('div');
    d.className = 'pow-toast pow-toast-' + (type || 'success');
    d.textContent = msg;
    document.body.appendChild(d);
    setTimeout(function() { d.classList.add('pow-toast-show'); }, 10);
    setTimeout(function() { d.classList.remove('pow-toast-show'); setTimeout(function(){d.remove();}, 300); }, 3000);
  }

  function escHtml(str) {
    var d = document.createElement('div');
    d.appendChild(document.createTextNode(str || ''));
    return d.innerHTML;
  }

})();
</script>

<?php include dirname(__DIR__) . '/includes/appstack_footer.php'; ?>

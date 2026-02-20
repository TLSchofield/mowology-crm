<?php
/**
 * Visit Detail — Admin/Crew View
 * ────────────────────────────────
 * Full Proof of Work detail page for a single job visit.
 * Shows: timeline, GPS stats, route map, photos, checklist,
 * materials, notes, PDF download/generate, lock/unlock controls.
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
$isAdmin = ($user['role'] ?? '') === 'admin' || userHasPermission('jobs.edit');

// Load visit with plan, property, contact, crew
$stmt = $db->prepare("
    SELECT
        v.*,
        p.plan_number, p.title AS plan_title, p.service_type AS plan_service_type,
        p.checklist_template,
        pr.address AS property_address, pr.city AS property_city,
        pr.province AS property_province, pr.postal_code AS property_postal,
        c.first_name AS contact_first, c.last_name AS contact_last,
        c.email AS contact_email, c.phone AS contact_phone,
        u.full_name AS crew_name
    FROM job_visits v
    JOIN job_plans p ON v.plan_id = p.id
    LEFT JOIN properties pr ON p.property_id = pr.id
    LEFT JOIN contacts c ON pr.site_contact_id = c.id
    LEFT JOIN users u ON v.assigned_crew_id = u.id
    WHERE v.id = ?
");
$stmt->execute([$visitId]);
$visit = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$visit) {
    header('Location: index.php');
    exit;
}

// AuthZ: crew can only view their own visits
$isMyCrew = (int)($visit['assigned_crew_id'] ?? 0) === (int)$user['id'];
if (!$isAdmin && !$isMyCrew) {
    header('Location: index.php');
    exit;
}

// Merge service_type
$visit['service_type'] = $visit['service_type'] ?? $visit['plan_service_type'] ?? 'general';

// Load GPS points summary
$stmtGps = $db->prepare("
    SELECT COUNT(*) AS pt_count,
           MIN(ts) AS first_ts, MAX(ts) AS last_ts,
           MIN(accuracy_m) AS best_accuracy
    FROM visit_gps_points WHERE visit_id = ?
");
$stmtGps->execute([$visitId]);
$gpsSummary = $stmtGps->fetch(PDO::FETCH_ASSOC);

// Load recent GPS points for mini-preview (last 200)
$stmtPts = $db->prepare("
    SELECT lat, lng, accuracy_m, ts FROM visit_gps_points
    WHERE visit_id = ? ORDER BY ts ASC LIMIT 200
");
$stmtPts->execute([$visitId]);
$gpsPoints = $stmtPts->fetchAll(PDO::FETCH_ASSOC);

// Load photos
$stmtPhotos = $db->prepare("
    SELECT id, photo_type, filename, caption, uploaded_at,
           u.full_name AS uploader
    FROM visit_photos vp
    LEFT JOIN users u ON vp.uploaded_by = u.id
    WHERE vp.visit_id = ?
    ORDER BY photo_type, uploaded_at
");
$stmtPhotos->execute([$visitId]);
$photos = $stmtPhotos->fetchAll(PDO::FETCH_ASSOC);

// Load notes
$stmtNotes = $db->prepare("
    SELECT vn.*, u.full_name AS author
    FROM visit_notes vn
    LEFT JOIN users u ON vn.created_by = u.id
    WHERE vn.visit_id = ?
    ORDER BY vn.created_at
");
$stmtNotes->execute([$visitId]);
$notes = $stmtNotes->fetchAll(PDO::FETCH_ASSOC);

// Load audit log
$stmtAudit = $db->prepare("
    SELECT al.*, u.full_name AS actor
    FROM visit_audit_log al
    LEFT JOIN users u ON al.user_id = u.id
    WHERE al.visit_id = ?
    ORDER BY al.created_at DESC
    LIMIT 50
");
$stmtAudit->execute([$visitId]);
$auditLog = $stmtAudit->fetchAll(PDO::FETCH_ASSOC);

// Decode JSON fields
$checklist   = !empty($visit['checklist_json'])    ? json_decode($visit['checklist_json'], true)    : [];
$materials   = !empty($visit['materials_json'])    ? json_decode($visit['materials_json'], true)    : [];
$svcData     = !empty($visit['service_data_json']) ? json_decode($visit['service_data_json'], true) : [];
$confBox     = !empty($visit['confidence_box_json'])? json_decode($visit['confidence_box_json'], true): [];

// Plan checklist template as fallback default for checklist widget
$checklistTemplate = !empty($visit['checklist_template'])
    ? json_decode($visit['checklist_template'], true) : [];

$isLocked  = ($visit['locked_at'] !== null);
$hasPdf    = !empty($visit['pdf_path']);
$hasMap    = !empty($visit['map_snapshot_path']);

$serviceTypeLabel = [
    'fertilizer'     => 'Fertilizer / Lawn Treatment',
    'lawn_treatment' => 'Fertilizer / Lawn Treatment',
    'salt'           => 'Salt / De-Icing',
    'de_ice'         => 'Salt / De-Icing',
    'snow'           => 'Snow Clearance',
    'snow_clearance' => 'Snow Clearance',
    'mowing'         => 'Mowing',
    'general'        => 'General Service',
][$visit['service_type']] ?? ucfirst($visit['service_type']);

$statusColors = [
    'scheduled'   => 'secondary',
    'in_progress' => 'warning',
    'completed'   => 'success',
    'skipped'     => 'info',
    'cancelled'   => 'danger',
    'weather'     => 'info',
];
$statusColor = $statusColors[$visit['status']] ?? 'secondary';

$csrfToken = generateCSRFToken();

$pageTitle  = 'Visit ' . ($visit['visit_number'] ?? $visitId);
$activePage = 'jobs';

$extraHead = <<<HTML
<style>
/* Visit detail — inline overrides for data density */
.pow-photo-grid { display: grid; grid-template-columns: repeat(auto-fill, minmax(160px, 1fr)); gap: 12px; margin-top: 10px; }
.pow-photo-item img { width: 100%; height: 120px; object-fit: cover; border-radius: 6px; border: 1px solid var(--mw-light); cursor: pointer; }
.pow-photo-item .badge { font-size: 10px; }
</style>
HTML;
?>
<?php include dirname(__DIR__) . '/includes/appstack_head.php'; ?>

<!-- ── Page header ──────────────────────────────────────────────────────── -->
<div class="mw-page-header d-flex justify-content-between align-items-center mb-4">
  <div>
    <h1 class="h3 mb-1">
      <?= htmlspecialchars($visit['visit_number'] ?? "Visit #{$visitId}") ?>
      <span class="badge badge-<?= $statusColor ?> ml-2" style="font-size:13px;">
        <?= ucfirst(str_replace('_',' ',$visit['status'])) ?>
      </span>
      <?php if ($isLocked): ?>
      <span class="badge badge-dark ml-1" title="PoW Locked"><i data-feather="lock" style="width:12px;height:12px;"></i> Locked</span>
      <?php endif; ?>
    </h1>
    <p class="text-muted mb-0">
      <?= htmlspecialchars($serviceTypeLabel) ?> ·
      <?= htmlspecialchars($visit['plan_title'] ?? '') ?> ·
      <a href="view.php?id=<?= $visit['plan_id'] ?>"><?= htmlspecialchars($visit['plan_number'] ?? '') ?></a>
    </p>
  </div>
  <div class="d-flex gap-2">
    <?php if ($hasPdf): ?>
    <a href="<?= htmlspecialchars($visit['pdf_path']) ?>" target="_blank" class="btn btn-success btn-sm">
      <i data-feather="download" class="mr-1"></i> Download PoW PDF
    </a>
    <?php endif; ?>
    <?php if ($isAdmin): ?>
    <button class="btn btn-primary btn-sm" id="btn-generate-pdf" data-visit="<?= $visitId ?>">
      <i data-feather="file-text" class="mr-1"></i> <?= $hasPdf ? 'Regenerate PDF' : 'Generate PDF' ?>
    </button>
    <?php if ($isLocked): ?>
    <button class="btn btn-outline-warning btn-sm" id="btn-unlock" data-visit="<?= $visitId ?>">
      <i data-feather="unlock" class="mr-1"></i> Unlock Visit
    </button>
    <?php else: ?>
    <button class="btn btn-outline-secondary btn-sm" id="btn-lock" data-visit="<?= $visitId ?>">
      <i data-feather="lock" class="mr-1"></i> Lock Visit
    </button>
    <?php endif; ?>
    <button class="btn btn-outline-info btn-sm" id="btn-email-pow" data-visit="<?= $visitId ?>">
      <i data-feather="mail" class="mr-1"></i> Email PoW
    </button>
    <?php endif; ?>
  </div>
</div>

<div class="row">

  <!-- ── LEFT COLUMN: Timeline + GPS + Photos ── -->
  <div class="col-lg-8">

    <!-- ── Timeline Card ──────────────────────────────────────────────── -->
    <div class="card mw-card mb-4">
      <div class="card-header"><h5 class="card-title mb-0"><i data-feather="clock" class="mr-2"></i>Visit Timeline</h5></div>
      <div class="card-body py-3">
        <div class="row text-center">
          <div class="col-3">
            <div class="text-muted small text-uppercase">Scheduled</div>
            <div class="font-weight-600"><?= htmlspecialchars($visit['scheduled_date'] ?? '—') ?></div>
          </div>
          <div class="col-3">
            <div class="text-muted small text-uppercase">Started</div>
            <div class="font-weight-600">
              <?= $visit['started_at'] ? date('g:i A', strtotime($visit['started_at'])) : '—' ?>
            </div>
          </div>
          <div class="col-3">
            <div class="text-muted small text-uppercase">Finished</div>
            <div class="font-weight-600">
              <?= $visit['completed_at'] ? date('g:i A', strtotime($visit['completed_at'])) : '—' ?>
            </div>
          </div>
          <div class="col-3">
            <div class="text-muted small text-uppercase">Duration</div>
            <div class="font-weight-600">
              <?php
              if ($visit['started_at'] && $visit['completed_at']) {
                  $mins = (int)round((strtotime($visit['completed_at']) - strtotime($visit['started_at'])) / 60);
                  echo ($mins >= 60 ? floor($mins/60).'h ' : '') . ($mins % 60) . 'm';
              } else { echo '—'; }
              ?>
            </div>
          </div>
        </div>
      </div>
    </div>

    <!-- ── GPS / Route Card ───────────────────────────────────────────── -->
    <div class="card mw-card mb-4">
      <div class="card-header d-flex justify-content-between align-items-center">
        <h5 class="card-title mb-0"><i data-feather="map-pin" class="mr-2"></i>GPS Route</h5>
        <span class="badge badge-<?= (int)$gpsSummary['pt_count'] > 0 ? 'success' : 'secondary' ?>">
          <?= (int)$gpsSummary['pt_count'] ?> points
        </span>
      </div>
      <div class="card-body">
        <?php if ($hasMap): ?>
        <img src="<?= htmlspecialchars($visit['map_snapshot_path']) ?>" alt="GPS Route Map"
             class="img-fluid rounded mb-3" style="max-height:320px;width:100%;object-fit:cover;">
        <?php elseif (!empty($gpsPoints)): ?>
        <div class="alert alert-info">Route map will be generated when PDF is produced.</div>
        <?php else: ?>
        <div class="text-muted text-center py-4">
          <i data-feather="map-off" style="width:32px;height:32px;opacity:.4;"></i>
          <p class="mt-2 mb-0">No GPS track recorded for this visit.</p>
        </div>
        <?php endif; ?>
        <div class="row text-center mt-2">
          <div class="col-4">
            <div class="text-muted small">Distance</div>
            <div class="font-weight-600">
              <?= $visit['distance_m'] ? number_format($visit['distance_m'] / 1000, 2) . ' km' : '—' ?>
            </div>
          </div>
          <div class="col-4">
            <div class="text-muted small">GPS Points</div>
            <div class="font-weight-600"><?= (int)($visit['gps_points_count'] ?? $gpsSummary['pt_count']) ?></div>
          </div>
          <div class="col-4">
            <div class="text-muted small">Best Accuracy</div>
            <div class="font-weight-600">
              <?= $gpsSummary['best_accuracy'] ? round((float)$gpsSummary['best_accuracy'], 1) . ' m' : '—' ?>
            </div>
          </div>
        </div>
      </div>
    </div>

    <!-- ── Photos Card ────────────────────────────────────────────────── -->
    <div class="card mw-card mb-4">
      <div class="card-header d-flex justify-content-between align-items-center">
        <h5 class="card-title mb-0"><i data-feather="image" class="mr-2"></i>Site Photos (<?= count($photos) ?>)</h5>
        <?php if (!$isLocked || $isAdmin): ?>
        <label class="btn btn-sm btn-outline-primary mb-0">
          <i data-feather="upload" class="mr-1"></i>Upload Photo
          <input type="file" id="photo-upload-input" accept="image/*" multiple style="display:none">
        </label>
        <?php endif; ?>
      </div>
      <div class="card-body">
        <?php if (empty($photos)): ?>
        <p class="text-muted text-center py-3 mb-0">No photos uploaded yet.</p>
        <?php else: ?>
        <div class="pow-photo-grid" id="photos-container">
          <?php foreach ($photos as $ph): ?>
          <div class="pow-photo-item">
            <a href="/uploads/photos/<?= htmlspecialchars($ph['filename']) ?>" target="_blank">
              <img src="/uploads/photos/<?= htmlspecialchars($ph['filename']) ?>"
                   alt="<?= htmlspecialchars($ph['photo_type']) ?>"
                   onerror="this.src='/crm/img/photo-error.svg'">
            </a>
            <div class="mt-1">
              <span class="badge badge-<?= $ph['photo_type']==='before'?'info':($ph['photo_type']==='after'?'success':'secondary') ?>">
                <?= htmlspecialchars($ph['photo_type']) ?>
              </span>
              <?php if (!empty($ph['caption'])): ?>
              <div class="small text-muted"><?= htmlspecialchars($ph['caption']) ?></div>
              <?php endif; ?>
            </div>
          </div>
          <?php endforeach; ?>
        </div>
        <?php endif; ?>
        <!-- Upload queue UI -->
        <div id="upload-queue" class="mt-3" style="display:none;"></div>
      </div>
    </div>

  </div><!-- /col-8 -->

  <!-- ── RIGHT COLUMN: Data panels ── -->
  <div class="col-lg-4">

    <!-- ── Client Info ─────────────────────────────────────────────────── -->
    <div class="card mw-card mb-4">
      <div class="card-header"><h5 class="card-title mb-0"><i data-feather="user" class="mr-2"></i>Client</h5></div>
      <div class="card-body py-3">
        <div class="font-weight-600"><?= htmlspecialchars(trim(($visit['contact_first']??'').' '.($visit['contact_last']??'')) ?: 'Unknown') ?></div>
        <div class="text-muted small"><?= htmlspecialchars($visit['contact_email'] ?? '') ?></div>
        <div class="text-muted small"><?= htmlspecialchars($visit['contact_phone'] ?? '') ?></div>
        <hr class="my-2">
        <div class="small">
          <?= htmlspecialchars($visit['property_address'] ?? '') ?><br>
          <?= htmlspecialchars(trim(($visit['property_city']??'').' '.($visit['property_province']??''))) ?>
          <?= htmlspecialchars($visit['property_postal'] ?? '') ?>
        </div>
      </div>
    </div>

    <!-- ── Service Details ────────────────────────────────────────────── -->
    <div class="card mw-card mb-4">
      <div class="card-header"><h5 class="card-title mb-0"><i data-feather="tool" class="mr-2"></i><?= htmlspecialchars($serviceTypeLabel) ?></h5></div>
      <div class="card-body py-3" id="service-data-view">
        <?php if (empty($svcData)): ?>
        <p class="text-muted small mb-0">No service data recorded.</p>
        <?php else:
          foreach ($svcData as $k => $v_val): ?>
        <div class="d-flex justify-content-between mb-1">
          <span class="text-muted small"><?= htmlspecialchars(str_replace('_',' ',ucfirst($k))) ?></span>
          <span class="small font-weight-600"><?= htmlspecialchars((string)$v_val) ?></span>
        </div>
        <?php endforeach; endif; ?>
      </div>
    </div>

    <!-- ── Materials ──────────────────────────────────────────────────── -->
    <div class="card mw-card mb-4">
      <div class="card-header"><h5 class="card-title mb-0"><i data-feather="package" class="mr-2"></i>Materials Used</h5></div>
      <div class="card-body py-3">
        <?php if (empty($materials)): ?>
        <p class="text-muted small mb-0">No materials recorded.</p>
        <?php else: ?>
        <table class="table table-sm mb-0">
          <thead><tr><th>Product</th><th>Qty</th><th>Unit</th></tr></thead>
          <tbody>
            <?php foreach ($materials as $m): ?>
            <tr>
              <td><?= htmlspecialchars($m['name'] ?? '') ?></td>
              <td><?= htmlspecialchars(isset($m['qty']) ? number_format((float)$m['qty'],2) : '') ?></td>
              <td><?= htmlspecialchars($m['unit'] ?? '') ?></td>
            </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
        <?php endif; ?>
      </div>
    </div>

    <!-- ── Checklist ──────────────────────────────────────────────────── -->
    <div class="card mw-card mb-4">
      <div class="card-header"><h5 class="card-title mb-0"><i data-feather="check-square" class="mr-2"></i>Checklist</h5></div>
      <div class="card-body py-2">
        <?php if (empty($checklist) && empty($checklistTemplate)): ?>
        <p class="text-muted small mb-0">No checklist defined for this plan.</p>
        <?php else:
          $items = !empty($checklist) ? $checklist
            : array_map(fn($t) => ['item'=>$t,'checked'=>false,'note'=>''], (array)$checklistTemplate);
          $done  = count(array_filter($items, fn($i) => !empty($i['checked'])));
          $total = count($items);
          $pct   = $total > 0 ? round($done/$total*100) : 0;
        ?>
        <div class="d-flex justify-content-between mb-2">
          <small class="text-muted"><?= $done ?>/<?= $total ?> completed</small>
          <small class="text-muted"><?= $pct ?>%</small>
        </div>
        <div class="progress mb-3" style="height:6px;">
          <div class="progress-bar bg-success" style="width:<?= $pct ?>%"></div>
        </div>
        <?php foreach ($items as $item): ?>
        <div class="d-flex align-items-start mb-1">
          <span class="mr-2" style="color:<?= !empty($item['checked'])?'#2D8659':'#ccc' ?>;font-size:16px;line-height:1.2;">
            <?= !empty($item['checked'])? '✓' : '○' ?>
          </span>
          <div>
            <div class="small"><?= htmlspecialchars($item['item'] ?? '') ?></div>
            <?php if (!empty($item['note'])): ?>
            <div class="text-muted" style="font-size:11px;"><?= htmlspecialchars($item['note']) ?></div>
            <?php endif; ?>
          </div>
        </div>
        <?php endforeach; endif; ?>
      </div>
    </div>

    <!-- ── Notes ──────────────────────────────────────────────────────── -->
    <div class="card mw-card mb-4">
      <div class="card-header"><h5 class="card-title mb-0"><i data-feather="message-square" class="mr-2"></i>Notes</h5></div>
      <div class="card-body">
        <div id="notes-list">
          <?php if (empty($notes)): ?>
          <p class="text-muted small mb-2">No notes added.</p>
          <?php else: foreach ($notes as $n): ?>
          <div class="pow-note-item mb-2 p-2 rounded" style="background:#f5faf7;border-left:3px solid var(--mw-lime);">
            <div class="small"><?= nl2br(htmlspecialchars($n['content'])) ?></div>
            <div class="text-muted" style="font-size:10px;margin-top:4px;">
              <?= htmlspecialchars($n['author'] ?? 'Crew') ?> ·
              <?= htmlspecialchars(date('M j, g:i A', strtotime($n['created_at']))) ?>
              <?php if ($n['is_visible_to_customer']): ?>
              <span class="badge badge-info ml-1" style="font-size:9px;">Client visible</span>
              <?php endif; ?>
            </div>
          </div>
          <?php endforeach; endif; ?>
        </div>
        <?php if (!$isLocked || $isAdmin): ?>
        <div class="mt-2">
          <textarea id="new-note-text" class="form-control form-control-sm" rows="2" placeholder="Add a note…"></textarea>
          <div class="d-flex justify-content-between mt-1">
            <label class="small text-muted">
              <input type="checkbox" id="note-visible"> Visible to client
            </label>
            <button class="btn btn-sm btn-outline-primary" id="btn-add-note">Add Note</button>
          </div>
        </div>
        <?php endif; ?>
      </div>
    </div>

  </div><!-- /col-4 -->
</div>

<!-- ── Audit Log ────────────────────────────────────────────────────────── -->
<?php if ($isAdmin && !empty($auditLog)): ?>
<div class="card mw-card mb-4">
  <div class="card-header">
    <h5 class="card-title mb-0"><i data-feather="shield" class="mr-2"></i>Audit Log</h5>
  </div>
  <div class="card-body p-0">
    <table class="table table-sm mb-0">
      <thead><tr><th>Action</th><th>Actor</th><th>Details</th><th>Time</th></tr></thead>
      <tbody>
        <?php foreach ($auditLog as $entry): ?>
        <tr>
          <td><span class="badge badge-secondary"><?= htmlspecialchars($entry['action']) ?></span></td>
          <td><?= htmlspecialchars($entry['actor'] ?? 'System') ?></td>
          <td class="text-muted small">
            <?php if ($entry['payload_json']): ?>
            <?= htmlspecialchars(substr($entry['payload_json'], 0, 100)) ?>
            <?php endif; ?>
          </td>
          <td class="text-muted small"><?= htmlspecialchars(date('M j, g:i A', strtotime($entry['created_at']))) ?></td>
        </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  </div>
</div>
<?php endif; ?>

<!-- ── Hidden state ─────────────────────────────────────────────────────── -->
<input type="hidden" id="pow-visit-id" value="<?= $visitId ?>">
<input type="hidden" id="pow-csrf" value="<?= htmlspecialchars($csrfToken) ?>">
<input type="hidden" id="pow-locked" value="<?= $isLocked ? '1' : '0' ?>">
<input type="hidden" id="pow-is-admin" value="<?= $isAdmin ? '1' : '0' ?>">

<!-- ── Unlock Modal ─────────────────────────────────────────────────────── -->
<div class="modal fade" id="unlockModal" tabindex="-1">
  <div class="modal-dialog modal-sm">
    <div class="modal-content">
      <div class="modal-header"><h5 class="modal-title">Unlock Visit</h5><button type="button" class="close" data-dismiss="modal">&times;</button></div>
      <div class="modal-body">
        <p class="small text-muted">Provide a reason for unlocking. This is logged in the audit trail.</p>
        <input type="text" id="unlock-reason" class="form-control" placeholder="Reason for unlock…">
      </div>
      <div class="modal-footer">
        <button class="btn btn-secondary btn-sm" data-dismiss="modal">Cancel</button>
        <button class="btn btn-warning btn-sm" id="btn-confirm-unlock">Unlock</button>
      </div>
    </div>
  </div>
</div>

<!-- ── Email PoW Modal ───────────────────────────────────────────────────── -->
<div class="modal fade" id="emailPowModal" tabindex="-1">
  <div class="modal-dialog">
    <div class="modal-content">
      <div class="modal-header"><h5 class="modal-title">Email Proof of Work</h5><button type="button" class="close" data-dismiss="modal">&times;</button></div>
      <div class="modal-body">
        <div class="form-group">
          <label class="small font-weight-600">Recipient Email</label>
          <input type="email" id="email-pow-recipient" class="form-control"
                 value="<?= htmlspecialchars($visit['contact_email'] ?? '') ?>">
        </div>
        <div class="form-group mb-0">
          <label class="small font-weight-600">Message (optional)</label>
          <textarea id="email-pow-message" class="form-control" rows="3"
            placeholder="Hi <?= htmlspecialchars($visit['contact_first'] ?? 'there') ?>, please find your Proof of Work report attached."></textarea>
        </div>
      </div>
      <div class="modal-footer">
        <button class="btn btn-secondary btn-sm" data-dismiss="modal">Cancel</button>
        <button class="btn btn-primary btn-sm" id="btn-confirm-email">Send Email</button>
      </div>
    </div>
  </div>
</div>

<!-- ── Photo type modal ──────────────────────────────────────────────────── -->
<div class="modal fade" id="photoTypeModal" tabindex="-1">
  <div class="modal-dialog modal-sm">
    <div class="modal-content">
      <div class="modal-header"><h5 class="modal-title">Photo Type</h5><button type="button" class="close" data-dismiss="modal">&times;</button></div>
      <div class="modal-body">
        <div class="form-group">
          <label class="small font-weight-600">Type</label>
          <select id="photo-type-select" class="form-control">
            <option value="before">Before</option>
            <option value="during">During</option>
            <option value="after" selected>After</option>
            <option value="issue">Issue</option>
            <option value="other">Other</option>
          </select>
        </div>
        <div class="form-group mb-0">
          <label class="small font-weight-600">Caption (optional)</label>
          <input type="text" id="photo-caption-input" class="form-control" placeholder="Brief description…">
        </div>
      </div>
      <div class="modal-footer">
        <button class="btn btn-secondary btn-sm" data-dismiss="modal">Cancel</button>
        <button class="btn btn-primary btn-sm" id="btn-confirm-photo-type">Upload</button>
      </div>
    </div>
  </div>
</div>

<script>
(function() {
  'use strict';

  var VISIT_ID = parseInt(document.getElementById('pow-visit-id').value, 10);
  var CSRF     = document.getElementById('pow-csrf').value;
  var LOCKED   = document.getElementById('pow-locked').value === '1';
  var IS_ADMIN = document.getElementById('pow-is-admin').value === '1';
  var API_BASE = '/crm/api/pow-actions.php';

  function showAlert(msg, type) {
    var d = document.createElement('div');
    d.className = 'alert alert-' + type + ' alert-dismissible fade show';
    d.innerHTML = msg + '<button type="button" class="close" data-dismiss="alert">&times;</button>';
    document.querySelector('.mw-page-header').insertAdjacentElement('afterend', d);
    setTimeout(function() { $(d).alert('close'); }, 5000);
  }

  function powPost(action, extra) {
    var body = Object.assign({ action: action, visit_id: VISIT_ID, csrf_token: CSRF }, extra || {});
    return fetch(API_BASE, {
      method: 'POST',
      headers: { 'Content-Type': 'application/json' },
      credentials: 'same-origin',
      body: JSON.stringify(body)
    }).then(function(r) { return r.json(); });
  }

  // ── Generate PDF ─────────────────────────────────────────────────────────
  var btnGenPdf = document.getElementById('btn-generate-pdf');
  if (btnGenPdf) {
    btnGenPdf.addEventListener('click', function() {
      var force = this.textContent.trim().indexOf('Regenerate') !== -1;
      this.disabled = true;
      this.innerHTML = '<span class="spinner-border spinner-border-sm mr-1"></span>Generating…';
      powPost('generate_pdf', { force: force }).then(function(res) {
        if (res.success && res.path) {
          showAlert('PDF generated. <a href="' + res.path + '" target="_blank">Download</a>', 'success');
          setTimeout(function() { location.reload(); }, 2000);
        } else {
          showAlert(res.error || 'PDF generation failed.', 'danger');
          btnGenPdf.disabled = false;
          btnGenPdf.innerHTML = '<i data-feather="file-text" class="mr-1"></i>Regenerate PDF';
          feather.replace();
        }
      });
    });
  }

  // ── Lock ─────────────────────────────────────────────────────────────────
  var btnLock = document.getElementById('btn-lock');
  if (btnLock) {
    btnLock.addEventListener('click', function() {
      if (!confirm('Lock this visit? Crew will not be able to make further edits without admin unlock.')) return;
      powPost('lock_visit').then(function(res) {
        if (res.success) { location.reload(); }
        else showAlert(res.error || 'Lock failed', 'danger');
      });
    });
  }

  // ── Unlock ───────────────────────────────────────────────────────────────
  var btnUnlock = document.getElementById('btn-unlock');
  if (btnUnlock) {
    btnUnlock.addEventListener('click', function() {
      document.getElementById('unlock-reason').value = '';
      $('#unlockModal').modal('show');
    });
  }
  var btnConfirmUnlock = document.getElementById('btn-confirm-unlock');
  if (btnConfirmUnlock) {
    btnConfirmUnlock.addEventListener('click', function() {
      var reason = document.getElementById('unlock-reason').value.trim();
      $('#unlockModal').modal('hide');
      powPost('unlock_visit', { reason: reason }).then(function(res) {
        if (res.success) { location.reload(); }
        else showAlert(res.error || 'Unlock failed', 'danger');
      });
    });
  }

  // ── Add Note ─────────────────────────────────────────────────────────────
  var btnNote = document.getElementById('btn-add-note');
  if (btnNote) {
    btnNote.addEventListener('click', function() {
      var content = document.getElementById('new-note-text').value.trim();
      var visible = document.getElementById('note-visible').checked ? 1 : 0;
      if (!content) { showAlert('Please enter a note.', 'warning'); return; }
      powPost('save_notes', { content: content, visible_to_customer: visible }).then(function(res) {
        if (res.success) {
          var list = document.getElementById('notes-list');
          var d    = document.createElement('div');
          d.className = 'pow-note-item mb-2 p-2 rounded';
          d.style.cssText = 'background:#f5faf7;border-left:3px solid var(--mw-lime);';
          d.innerHTML = '<div class="small">' + content.replace(/\n/g,'<br>') + '</div>'
                       + '<div class="text-muted" style="font-size:10px;margin-top:4px;">You · Just now'
                       + (visible ? '<span class="badge badge-info ml-1" style="font-size:9px;">Client visible</span>' : '') + '</div>';
          list.appendChild(d);
          document.getElementById('new-note-text').value = '';
        } else showAlert(res.error || 'Failed to save note.', 'danger');
      });
    });
  }

  // ── Photo Upload ──────────────────────────────────────────────────────────
  var pendingFiles = [];
  var photoInput  = document.getElementById('photo-upload-input');
  if (photoInput) {
    photoInput.addEventListener('change', function() {
      pendingFiles = Array.from(this.files);
      if (pendingFiles.length > 0) {
        document.getElementById('photo-type-select').value = 'after';
        document.getElementById('photo-caption-input').value = '';
        $('#photoTypeModal').modal('show');
      }
    });
  }

  var btnConfirmPhoto = document.getElementById('btn-confirm-photo-type');
  if (btnConfirmPhoto) {
    btnConfirmPhoto.addEventListener('click', function() {
      var photoType = document.getElementById('photo-type-select').value;
      var caption   = document.getElementById('photo-caption-input').value;
      $('#photoTypeModal').modal('hide');
      uploadPhotos(pendingFiles, photoType, caption);
    });
  }

  function uploadPhotos(files, photoType, caption) {
    var queue = document.getElementById('upload-queue');
    queue.style.display = 'block';
    queue.innerHTML = '';

    files.forEach(function(file, idx) {
      var bar = document.createElement('div');
      bar.className = 'mb-2';
      bar.innerHTML = '<div class="small text-muted">' + file.name + '</div>'
                    + '<div class="progress" style="height:6px;"><div class="progress-bar" id="up-bar-' + idx + '" style="width:0%"></div></div>';
      queue.appendChild(bar);

      var fd = new FormData();
      fd.append('photo',       file);
      fd.append('visit_id',    VISIT_ID);
      fd.append('action',      'upload_photo');
      fd.append('csrf_token',  CSRF);
      fd.append('photo_type',  photoType);
      fd.append('caption',     caption);

      var xhr = new XMLHttpRequest();
      xhr.open('POST', API_BASE, true);
      xhr.withCredentials = true;
      xhr.upload.onprogress = function(e) {
        if (e.lengthComputable) {
          document.getElementById('up-bar-' + idx).style.width = Math.round(e.loaded/e.total*100) + '%';
        }
      };
      xhr.onload = function() {
        var res = JSON.parse(xhr.responseText || '{}');
        if (res.success) {
          document.getElementById('up-bar-' + idx).classList.add('bg-success');
          // Append to photo grid
          var grid = document.getElementById('photos-container');
          if (!grid) {
            var cb = document.querySelector('.card-body');
            grid = document.createElement('div');
            grid.id = 'photos-container';
            grid.className = 'pow-photo-grid';
            cb.prepend(grid);
          }
          var item = document.createElement('div');
          item.className = 'pow-photo-item';
          var url = '/uploads/photos/' + res.filename;
          item.innerHTML = '<a href="' + url + '" target="_blank"><img src="' + url + '" style="width:100%;height:120px;object-fit:cover;border-radius:6px;"></a>'
                         + '<div class="mt-1"><span class="badge badge-secondary">' + photoType + '</span></div>';
          grid.appendChild(item);
        } else {
          document.getElementById('up-bar-' + idx).classList.add('bg-danger');
        }
        if (idx === files.length - 1) {
          setTimeout(function() { queue.style.display = 'none'; }, 2000);
        }
      };
      xhr.send(fd);
    });
  }

  // ── Email PoW ─────────────────────────────────────────────────────────────
  var btnEmail = document.getElementById('btn-email-pow');
  if (btnEmail) {
    btnEmail.addEventListener('click', function() {
      $('#emailPowModal').modal('show');
    });
  }
  var btnConfirmEmail = document.getElementById('btn-confirm-email');
  if (btnConfirmEmail) {
    btnConfirmEmail.addEventListener('click', function() {
      var recipient = document.getElementById('email-pow-recipient').value.trim();
      var message   = document.getElementById('email-pow-message').value.trim();
      if (!recipient) { showAlert('Please enter a recipient email.', 'warning'); return; }
      this.disabled = true;
      this.textContent = 'Sending…';
      var self = this;
      fetch('/crm/api/pow-email.php', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        credentials: 'same-origin',
        body: JSON.stringify({ visit_id: VISIT_ID, csrf_token: CSRF, recipient: recipient, message: message })
      }).then(function(r) { return r.json(); }).then(function(res) {
        $('#emailPowModal').modal('hide');
        if (res.success) {
          showAlert('Email sent to ' + recipient, 'success');
        } else {
          showAlert(res.error || 'Email failed.', 'danger');
        }
        self.disabled = false;
        self.textContent = 'Send Email';
      });
    });
  }

})();
</script>

<?php include dirname(__DIR__) . '/includes/appstack_footer.php'; ?>

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

$db      = getDB();
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

$isMyCrew = (int)($visit['assigned_crew_id'] ?? 0) === (int)$user['id'];
if (!$isAdmin && !$isMyCrew) {
    header('Location: index.php');
    exit;
}

$visit['service_type'] = $visit['service_type'] ?? $visit['plan_service_type'] ?? 'general';

// Load GPS summary
$stmtGps = $db->prepare("
    SELECT COUNT(*) AS pt_count,
           MIN(ts) AS first_ts, MAX(ts) AS last_ts,
           MIN(accuracy_m) AS best_accuracy
    FROM visit_gps_points WHERE visit_id = ?
");
$stmtGps->execute([$visitId]);
$gpsSummary = $stmtGps->fetch(PDO::FETCH_ASSOC);

// Load GPS points for mini-preview
$stmtPts = $db->prepare("
    SELECT lat, lng, accuracy_m, ts FROM visit_gps_points
    WHERE visit_id = ? ORDER BY ts ASC LIMIT 200
");
$stmtPts->execute([$visitId]);
$gpsPoints = $stmtPts->fetchAll(PDO::FETCH_ASSOC);

// Load photos (with variant paths, exclude soft-deleted)
$stmtPhotos = $db->prepare("
    SELECT vp.id, vp.photo_type, vp.filename, vp.caption, vp.sort_order,
           vp.thumb_path, vp.grid_path, vp.view_path, vp.tags,
           vp.uploaded_at, u.full_name AS uploader
    FROM visit_photos vp
    LEFT JOIN users u ON vp.uploaded_by = u.id
    WHERE vp.visit_id = ? AND vp.deleted_at IS NULL
    ORDER BY
        FIELD(vp.photo_type,'before','after','additional','during','issue','other'),
        vp.sort_order ASC, vp.uploaded_at ASC
");
$stmtPhotos->execute([$visitId]);
$photos = $stmtPhotos->fetchAll(PDO::FETCH_ASSOC);

// Group photos
$photoGroups = ['before' => [], 'after' => [], 'additional' => [], 'other' => []];
foreach ($photos as $p) {
    $bucket = in_array($p['photo_type'], ['before','after','additional']) ? $p['photo_type'] : 'other';
    // Compute display URLs with fallback to original
    $origUrl = '/uploads/photos/' . $p['filename'];
    $p['_orig_url']  = $origUrl;
    $p['_thumb_url'] = $p['thumb_path'] ?? $origUrl;
    $p['_grid_url']  = $p['grid_path']  ?? $origUrl;
    $p['_view_url']  = $p['view_path']  ?? $origUrl;
    $photoGroups[$bucket][] = $p;
}
$photoCount = count($photos);

// Load share token status
$stmtToken = $db->prepare("
    SELECT token_preview, created_at, expires_at, revoked_at, access_count
    FROM visit_share_tokens WHERE visit_id = ?
");
$stmtToken->execute([$visitId]);
$shareToken = $stmtToken->fetch(PDO::FETCH_ASSOC);
$hasActiveToken = $shareToken
    && $shareToken['revoked_at'] === null
    && ($shareToken['expires_at'] === null || strtotime($shareToken['expires_at']) > time());
$siteUrl = defined('SITE_URL') ? rtrim(SITE_URL, '/') : 'https://mowology.ca';

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
$checklist   = !empty($visit['checklist_json'])     ? json_decode($visit['checklist_json'], true)     : [];
$materials   = !empty($visit['materials_json'])     ? json_decode($visit['materials_json'], true)     : [];
$svcData     = !empty($visit['service_data_json'])  ? json_decode($visit['service_data_json'], true)  : [];
$confBox     = !empty($visit['confidence_box_json'])? json_decode($visit['confidence_box_json'], true): [];
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
        <div class="text-muted text-center py-4" id="gps-empty-state">
          <i data-feather="map" style="width:32px;height:32px;opacity:.4;"></i>
          <p class="mt-2 mb-0">No GPS track recorded for this visit.</p>
          <?php if ($isAdmin): ?>
          <button class="btn btn-sm btn-outline-secondary mt-3" id="btn-backfill-gps">
            <i data-feather="download-cloud" style="width:14px;height:14px;"></i>
            Pull from crew history
          </button>
          <?php endif; ?>
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
    <div class="card mw-card mb-4" id="photos-card">
      <div class="card-header d-flex justify-content-between align-items-center">
        <h5 class="card-title mb-0">
          <i data-feather="image" class="mr-2"></i>Site Photos
          <span class="badge badge-<?= $photoCount > 0 ? 'success' : 'secondary' ?> ml-1"
                id="photo-total-count"><?= $photoCount ?></span>
        </h5>
        <?php if (!$isLocked || $isAdmin): ?>
        <div class="d-flex gap-2">
          <label class="btn btn-sm btn-outline-info mb-0 mw-upload-typed-btn" data-type="before">
            <i data-feather="camera" style="width:14px;height:14px;"></i> Before
            <input type="file" accept="image/*" multiple class="mw-detail-upload-input" data-type="before" style="display:none">
          </label>
          <label class="btn btn-sm btn-outline-success mb-0 mw-upload-typed-btn" data-type="after">
            <i data-feather="camera" style="width:14px;height:14px;"></i> After
            <input type="file" accept="image/*" multiple class="mw-detail-upload-input" data-type="after" style="display:none">
          </label>
          <label class="btn btn-sm btn-outline-warning mb-0 mw-upload-typed-btn" data-type="additional">
            <i data-feather="plus" style="width:14px;height:14px;"></i> Additional
            <input type="file" accept="image/*" multiple class="mw-detail-upload-input" data-type="additional" style="display:none">
          </label>
        </div>
        <?php endif; ?>
      </div>
      <div class="card-body">

        <?php if (empty($photos)): ?>
        <p class="text-muted text-center py-3 mb-0" id="no-photos-msg">No photos uploaded yet.</p>
        <?php endif; ?>

        <!-- ── Before / After side-by-side ── -->
        <?php if (!empty($photoGroups['before']) || !empty($photoGroups['after'])): ?>
        <div class="mw-ba-row" id="ba-row">

          <?php foreach (['before' => 'info', 'after' => 'success'] as $baType => $baColor): ?>
          <?php if (!empty($photoGroups[$baType])): ?>
          <div class="mw-ba-col">
            <div class="mw-ba-header mw-ba-header--<?= $baType ?>">
              <?= ucfirst($baType) ?>
              <span class="badge badge-<?= $baColor ?> ml-1"><?= count($photoGroups[$baType]) ?></span>
            </div>
            <div class="mw-photo-grid" id="grid-detail-<?= $baType ?>">
              <?php foreach ($photoGroups[$baType] as $ph): ?>
              <?php $phTags = $ph['tags'] ? (json_decode($ph['tags'], true) ?: []) : []; ?>
              <div class="mw-photo-tile"
                   data-photo-id="<?= $ph['id'] ?>"
                   data-view-url="<?= htmlspecialchars($ph['_view_url']) ?>"
                   data-type="<?= htmlspecialchars($ph['photo_type']) ?>"
                   data-caption="<?= htmlspecialchars($ph['caption'] ?? '') ?>"
                   data-tags="<?= htmlspecialchars($ph['tags'] ?? '[]') ?>">
                <img src="<?= htmlspecialchars($ph['_thumb_url']) ?>"
                     loading="lazy"
                     alt="<?= htmlspecialchars($ph['photo_type']) ?>"
                     onerror="this.src='/crm/img/img-error.svg'">
                <div class="mw-photo-tile-overlay">
                  <?php if (!empty($phTags)): ?>
                  <span class="mw-timeline-tag-count" title="<?= htmlspecialchars(implode(', ', $phTags)) ?>">
                    <i data-feather="tag" style="width:10px;height:10px;"></i> <?= count($phTags) ?>
                  </span>
                  <?php endif; ?>
                  <?php if ($isAdmin): ?>
                  <button class="mw-photo-delete-btn" data-photo-id="<?= $ph['id'] ?>"
                          title="Delete photo" aria-label="Delete">✕</button>
                  <?php endif; ?>
                </div>
              </div>
              <?php endforeach; ?>
            </div>
          </div>
          <?php endif; ?>
          <?php endforeach; ?>

        </div>
        <?php endif; ?>

        <!-- ── Additional photos grid ── -->
        <?php if (!empty($photoGroups['additional'])): ?>
        <div class="mw-additional-section" id="section-additional">
          <div class="mw-ba-header mw-ba-header--additional">
            Additional
            <span class="badge badge-warning ml-1"><?= count($photoGroups['additional']) ?></span>
          </div>
          <div class="mw-photo-grid" id="grid-detail-additional">
            <?php foreach ($photoGroups['additional'] as $ph): ?>
            <?php $phTags = $ph['tags'] ? (json_decode($ph['tags'], true) ?: []) : []; ?>
            <div class="mw-photo-tile"
                 data-photo-id="<?= $ph['id'] ?>"
                 data-view-url="<?= htmlspecialchars($ph['_view_url']) ?>"
                 data-type="<?= htmlspecialchars($ph['photo_type']) ?>"
                 data-caption="<?= htmlspecialchars($ph['caption'] ?? '') ?>"
                 data-tags="<?= htmlspecialchars($ph['tags'] ?? '[]') ?>">
              <img src="<?= htmlspecialchars($ph['_thumb_url']) ?>"
                   loading="lazy"
                   alt="additional"
                   onerror="this.src='/crm/img/img-error.svg'">
              <div class="mw-photo-tile-overlay">
                <?php if (!empty($phTags)): ?>
                <span class="mw-timeline-tag-count" title="<?= htmlspecialchars(implode(', ', $phTags)) ?>">
                  <i data-feather="tag" style="width:10px;height:10px;"></i> <?= count($phTags) ?>
                </span>
                <?php endif; ?>
                <?php if ($isAdmin): ?>
                <button class="mw-photo-delete-btn" data-photo-id="<?= $ph['id'] ?>"
                        title="Delete photo" aria-label="Delete">✕</button>
                <?php endif; ?>
              </div>
            </div>
            <?php endforeach; ?>
          </div>
        </div>
        <?php endif; ?>

        <!-- ── Other photos (legacy types) ── -->
        <?php if (!empty($photoGroups['other'])): ?>
        <div class="mt-3" id="section-other">
          <div class="mw-ba-header mw-ba-header--other">Other</div>
          <div class="mw-photo-grid" id="grid-detail-other">
            <?php foreach ($photoGroups['other'] as $ph): ?>
            <?php $phTags = $ph['tags'] ? (json_decode($ph['tags'], true) ?: []) : []; ?>
            <div class="mw-photo-tile"
                 data-photo-id="<?= $ph['id'] ?>"
                 data-view-url="<?= htmlspecialchars($ph['_view_url']) ?>"
                 data-type="<?= htmlspecialchars($ph['photo_type']) ?>"
                 data-caption="<?= htmlspecialchars($ph['caption'] ?? '') ?>"
                 data-tags="<?= htmlspecialchars($ph['tags'] ?? '[]') ?>">
              <img src="<?= htmlspecialchars($ph['_thumb_url']) ?>"
                   loading="lazy"
                   alt="<?= htmlspecialchars($ph['photo_type']) ?>"
                   onerror="this.src='/crm/img/img-error.svg'">
              <div class="mw-photo-tile-overlay">
                <?php if (!empty($phTags)): ?>
                <span class="mw-timeline-tag-count" title="<?= htmlspecialchars(implode(', ', $phTags)) ?>">
                  <i data-feather="tag" style="width:10px;height:10px;"></i> <?= count($phTags) ?>
                </span>
                <?php endif; ?>
                <span class="badge badge-secondary" style="font-size:9px;"><?= htmlspecialchars($ph['photo_type']) ?></span>
                <?php if ($isAdmin): ?>
                <button class="mw-photo-delete-btn" data-photo-id="<?= $ph['id'] ?>"
                        title="Delete photo" aria-label="Delete">✕</button>
                <?php endif; ?>
              </div>
            </div>
            <?php endforeach; ?>
          </div>
        </div>
        <?php endif; ?>

        <!-- Upload queue progress -->
        <div id="upload-queue" class="mt-3" style="display:none;"></div>
      </div>
    </div>

    <!-- ── Share Gallery Card (admin) ─────────────────────────────────── -->
    <?php if ($isAdmin): ?>
    <div class="card mw-card mb-4" id="share-gallery-card">
      <div class="card-header">
        <h5 class="card-title mb-0"><i data-feather="share-2" class="mr-2"></i>Client Gallery Link</h5>
      </div>
      <div class="card-body">
        <?php if ($hasActiveToken): ?>
        <div class="d-flex align-items-center mb-3" id="token-active-row">
          <div class="mw-token-status mw-token-status--active mr-3">
            <i data-feather="check-circle" style="width:16px;height:16px;"></i> Active
          </div>
          <div class="flex-grow-1">
            <div class="mw-token-preview-label">Token: <code><?= htmlspecialchars(($shareToken['token_preview'] ?? '') . '…') ?></code></div>
            <?php if ($shareToken['expires_at']): ?>
            <div class="small text-muted">Expires: <?= htmlspecialchars(date('M j, Y', strtotime($shareToken['expires_at']))) ?></div>
            <?php else: ?>
            <div class="small text-muted">No expiry</div>
            <?php endif; ?>
            <div class="small text-muted">Accessed <?= (int)($shareToken['access_count'] ?? 0) ?> time<?= (int)($shareToken['access_count'] ?? 0) !== 1 ? 's' : '' ?></div>
          </div>
        </div>
        <p class="small text-muted mb-2">Share this link with your client:</p>
        <div class="input-group input-group-sm mb-3" id="share-url-group" style="display:none;">
          <input type="text" class="form-control" id="share-url-input" readonly>
          <div class="input-group-append">
            <button class="btn btn-outline-secondary" id="btn-copy-link" type="button">
              <i data-feather="copy" style="width:14px;height:14px;"></i> Copy
            </button>
          </div>
        </div>
        <?php else: ?>
        <p class="text-muted small mb-3" id="no-token-msg">
          No active share link. Generate one to give your client access to their photo gallery.
        </p>
        <?php endif; ?>

        <?php if (!empty($visit['contact_email'])): ?>
        <div class="form-check mb-3">
          <input class="form-check-input" type="checkbox" id="chk-notify-client" value="1">
          <label class="form-check-label small text-muted" for="chk-notify-client">
            Email gallery link to client (<code><?= htmlspecialchars($visit['contact_email']) ?></code>)
          </label>
        </div>
        <?php endif; ?>
        <div class="d-flex gap-2 flex-wrap">
          <button class="btn btn-sm btn-success" id="btn-generate-token">
            <i data-feather="link" class="mr-1"></i>
            <?= $hasActiveToken ? 'Regenerate Link' : 'Generate Share Link' ?>
          </button>
          <?php if ($hasActiveToken): ?>
          <button class="btn btn-sm btn-outline-danger" id="btn-revoke-token">
            <i data-feather="x-circle" class="mr-1"></i> Revoke Link
          </button>
          <?php endif; ?>
        </div>
      </div>
    </div>
    <?php endif; ?>

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
        <?php if (!empty($visit['contact_first']) || !empty($visit['contact_last'])): ?>
        <button type="button" class="btn btn-sm btn-outline-success mt-3 w-100" id="btn-save-contact">
          <i data-feather="user-plus" class="mr-1"></i> Save to Contacts
        </button>
        <?php endif; ?>
      </div>
    </div>
    <?php if (!empty($visit['contact_first']) || !empty($visit['contact_last'])): ?>
    <script>
    (function() {
        var btn = document.getElementById('btn-save-contact');
        if (!btn) return;
        // Contact data JSON-encoded for safe JS embedding — no manual escaping needed.
        // On Android (Capacitor WebView), downloading a .vcf file triggers the OS
        // intent chooser, which opens the Contacts app with fields pre-filled.
        // On iOS web / desktop, the .vcf downloads and the OS opens it in Contacts.
        var c = <?= json_encode([
            'first'    => $visit['contact_first']    ?? '',
            'last'     => $visit['contact_last']     ?? '',
            'email'    => $visit['contact_email']    ?? '',
            'phone'    => $visit['contact_phone']    ?? '',
            'street'   => $visit['property_address'] ?? '',
            'city'     => $visit['property_city']    ?? '',
            'region'   => $visit['property_province']?? '',
            'postal'   => $visit['property_postal']  ?? '',
        ]) ?>;
        btn.addEventListener('click', function() {
            var lines = [
                'BEGIN:VCARD', 'VERSION:3.0',
                'N:' + c.last + ';' + c.first + ';;;',
                'FN:' + (c.first + ' ' + c.last).trim()
            ];
            if (c.email) lines.push('EMAIL:' + c.email);
            if (c.phone) lines.push('TEL;TYPE=VOICE:' + c.phone);
            lines.push('ADR;TYPE=HOME:;;' + c.street + ';' + c.city + ';' + c.region + ';' + c.postal + ';Canada');
            lines.push('NOTE:Mowology client', 'END:VCARD');
            var blob = new Blob([lines.join('\r\n')], { type: 'text/vcard' });
            var url  = URL.createObjectURL(blob);
            var a    = document.createElement('a');
            a.href   = url;
            a.download = (c.first + '_' + c.last).replace(/\s+/g, '_') + '.vcf';
            document.body.appendChild(a);
            a.click();
            document.body.removeChild(a);
            setTimeout(function() { URL.revokeObjectURL(url); }, 1000);
        });
    })();
    </script>
    <?php endif; ?>


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

<!-- ── Lightbox ─────────────────────────────────────────────────────────── -->
<div id="mw-lightbox" class="mw-lightbox" style="display:none;" role="dialog" aria-modal="true">
  <div class="mw-lightbox-backdrop" id="lb-backdrop"></div>
  <div class="mw-lightbox-content">
    <button class="mw-lightbox-close" id="lb-close" aria-label="Close">✕</button>
    <div class="mw-lightbox-type-label" id="lb-type"></div>
    <img class="mw-lightbox-img" id="lb-img" src="" alt="">
    <div class="mw-lightbox-caption" id="lb-caption"></div>

    <!-- Tags section -->
    <div class="mw-lightbox-tags-section" id="lb-tags-section">
      <div id="lb-tag-chips" class="mw-lightbox-tag-chips"></div>
      <div class="mw-tag-editor" id="lb-tag-editor" style="display:none">
        <input type="text" id="lb-tag-input" class="form-control form-control-sm mw-tag-input"
               placeholder="Type a tag and press Enter…" list="lb-tag-suggestions" autocomplete="off">
        <datalist id="lb-tag-suggestions">
          <option value="drainage issue"><option value="pest damage">
          <option value="upsell opportunity"><option value="property access issue">
          <option value="equipment damage"><option value="hazard">
          <option value="before treatment"><option value="after treatment">
          <option value="seasonal change"><option value="client request">
        </datalist>
        <button class="btn btn-sm btn-primary" id="lb-add-tag-btn">Add</button>
        <button class="btn btn-sm btn-outline-secondary" id="lb-save-tags-btn">Save</button>
      </div>
    </div>

    <div class="mw-lightbox-actions">
      <button class="btn btn-sm btn-outline-light mw-lb-action-btn" id="lb-tags-btn">
        <i data-feather="tag" style="width:13px;height:13px;"></i> Tags
      </button>
      <?php if ($isAdmin): ?>
      <button class="btn btn-sm btn-outline-danger mw-lb-action-btn" id="lb-delete-btn" data-photo-id="">
        <i data-feather="trash-2" style="width:14px;height:14px;"></i> Delete
      </button>
      <?php endif; ?>
    </div>
  </div>
</div>

<!-- ── Hidden state ─────────────────────────────────────────────────────── -->
<input type="hidden" id="pow-visit-id" value="<?= $visitId ?>">
<input type="hidden" id="pow-csrf" value="<?= htmlspecialchars($csrfToken) ?>">
<input type="hidden" id="pow-locked" value="<?= $isLocked ? '1' : '0' ?>">
<input type="hidden" id="pow-is-admin" value="<?= $isAdmin ? '1' : '0' ?>">
<input type="hidden" id="pow-site-url" value="<?= htmlspecialchars($siteUrl) ?>">
<input type="hidden" id="pow-contact-email" value="<?= htmlspecialchars($visit['contact_email'] ?? '') ?>">

<!-- ── Modals ────────────────────────────────────────────────────────────── -->
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

<script>
(function() {
  'use strict';

  var VISIT_ID = parseInt(document.getElementById('pow-visit-id').value, 10);
  var CSRF     = document.getElementById('pow-csrf').value;
  var LOCKED   = document.getElementById('pow-locked').value === '1';
  var IS_ADMIN = document.getElementById('pow-is-admin').value === '1';
  var SITE_URL = document.getElementById('pow-site-url').value;
  var API_BASE = '/crm/api/pow-actions.php';
  var API_PHOTO = '/crm/api/job-photo.php';
  var API_TOKEN = '/crm/api/visit-share-token.php';

  var totalCount = parseInt(document.getElementById('photo-total-count').textContent, 10) || 0;

  function showAlert(msg, type) {
    var d = document.createElement('div');
    d.className = 'alert alert-' + type + ' alert-dismissible fade show';
    d.innerHTML = msg + '<button type="button" class="close" data-dismiss="alert">&times;</button>';
    document.querySelector('.mw-page-header').insertAdjacentElement('afterend', d);
    setTimeout(function() { if (typeof $ !== 'undefined') $(d).alert('close'); else d.remove(); }, 5000);
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

  function apiPost(url, data) {
    return fetch(url, {
      method: 'POST',
      headers: { 'Content-Type': 'application/json' },
      credentials: 'same-origin',
      body: JSON.stringify(Object.assign({ visit_id: VISIT_ID, csrf_token: CSRF }, data))
    }).then(function(r) { return r.json(); });
  }

  // ── Lightbox ──────────────────────────────────────────────────────────────
  var lb        = document.getElementById('mw-lightbox');
  var lbImg     = document.getElementById('lb-img');
  var lbType    = document.getElementById('lb-type');
  var lbCaption = document.getElementById('lb-caption');
  var lbDelete  = document.getElementById('lb-delete-btn');
  var lbTagsBtn = document.getElementById('lb-tags-btn');

  // Tag state for currently open photo
  var lbCurrentPhotoId = 0;
  var lbCurrentTags    = [];
  var lbTagsDirty      = false;

  function openLightbox(tile) {
    var viewUrl = tile.dataset.viewUrl;
    var type    = tile.dataset.type || '';
    var caption = tile.dataset.caption || '';
    var photoId = tile.dataset.photoId || '';
    var tagsRaw = tile.dataset.tags || '[]';
    if (!viewUrl) return;

    lbImg.src = viewUrl;
    lbType.textContent = type.charAt(0).toUpperCase() + type.slice(1);
    lbCaption.textContent = caption;
    if (lbDelete) lbDelete.dataset.photoId = photoId;

    // Tags
    lbCurrentPhotoId = parseInt(photoId, 10) || 0;
    try { lbCurrentTags = JSON.parse(tagsRaw) || []; } catch(e) { lbCurrentTags = []; }
    lbTagsDirty = false;
    lbRenderTagChips();
    document.getElementById('lb-tag-editor').style.display = 'none';
    if (lbTagsBtn) lbTagsBtn.classList.remove('active');

    lb.style.display = 'flex';
    document.body.style.overflow = 'hidden';
    if (typeof feather !== 'undefined') feather.replace();
  }

  function closeLightbox() {
    if (lbTagsDirty) lbSaveTags();
    lb.style.display = 'none';
    lbImg.src = '';
    document.body.style.overflow = '';
  }

  // Tags
  function lbRenderTagChips() {
    var chips = document.getElementById('lb-tag-chips');
    if (!chips) return;
    if (!lbCurrentTags.length) {
      chips.innerHTML = '<span class="text-white-50 small">No tags</span>';
    } else {
      chips.innerHTML = lbCurrentTags.map(function(t, i) {
        return '<span class="mw-tag-chip mw-tag-chip--editable">' + lbEsc(t)
             + '<button type="button" class="mw-tag-remove" onclick="lbRemoveTag(' + i + ')">&times;</button></span>';
      }).join(' ');
    }
  }

  function lbRemoveTag(i) { lbCurrentTags.splice(i, 1); lbTagsDirty = true; lbRenderTagChips(); }

  function lbAddTag() {
    var inp = document.getElementById('lb-tag-input');
    var val = (inp.value || '').trim().toLowerCase().substring(0, 50);
    if (!val || lbCurrentTags.indexOf(val) !== -1) { inp.value = ''; return; }
    if (lbCurrentTags.length >= 10) { alert('Maximum 10 tags'); return; }
    lbCurrentTags.push(val); lbTagsDirty = true; inp.value = ''; lbRenderTagChips();
  }

  function lbSaveTags() {
    lbTagsDirty = false;
    fetch(API_PHOTO, {
      method: 'POST',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify({ action: 'update_tags', photo_id: lbCurrentPhotoId, tags: lbCurrentTags, csrf_token: CSRF })
    }).then(function() {
      // Update the tile's data-tags attribute so it reflects new state
      var tile = document.querySelector('.mw-photo-tile[data-photo-id="' + lbCurrentPhotoId + '"]');
      if (tile) {
        tile.dataset.tags = JSON.stringify(lbCurrentTags);
        var badge = tile.querySelector('.mw-timeline-tag-count');
        if (lbCurrentTags.length) {
          if (!badge) {
            badge = document.createElement('span');
            badge.className = 'mw-timeline-tag-count';
            tile.querySelector('.mw-photo-tile-overlay').prepend(badge);
          }
          badge.innerHTML = '<i data-feather="tag" style="width:10px;height:10px;"></i> ' + lbCurrentTags.length;
          badge.title = lbCurrentTags.join(', ');
          if (typeof feather !== 'undefined') feather.replace();
        } else if (badge) {
          badge.remove();
        }
      }
    }).catch(function() {});
  }

  if (lbTagsBtn) {
    lbTagsBtn.addEventListener('click', function() {
      var editor = document.getElementById('lb-tag-editor');
      var showing = editor.style.display !== 'none';
      editor.style.display = showing ? 'none' : 'flex';
      lbTagsBtn.classList.toggle('active', !showing);
    });
  }

  var lbAddTagBtn = document.getElementById('lb-add-tag-btn');
  if (lbAddTagBtn) lbAddTagBtn.addEventListener('click', lbAddTag);

  var lbSaveTagsBtn = document.getElementById('lb-save-tags-btn');
  if (lbSaveTagsBtn) lbSaveTagsBtn.addEventListener('click', function() { lbSaveTags(); lbTagsDirty = false; });

  var lbTagInput = document.getElementById('lb-tag-input');
  if (lbTagInput) lbTagInput.addEventListener('keydown', function(e) { if (e.key === 'Enter') { e.preventDefault(); lbAddTag(); } });

  function lbEsc(s) { return String(s).replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;'); }

  if (lb) {
    document.getElementById('lb-backdrop').addEventListener('click', closeLightbox);
    document.getElementById('lb-close').addEventListener('click', closeLightbox);
    document.addEventListener('keydown', function(e) { if (e.key === 'Escape' && lb.style.display === 'flex') closeLightbox(); });
  }

  // Delete from lightbox
  if (lbDelete) {
    lbDelete.addEventListener('click', function() {
      var photoId = parseInt(this.dataset.photoId, 10);
      if (!photoId) return;
      if (!confirm('Delete this photo? This cannot be undone.')) return;
      deletePhoto(photoId, function() { closeLightbox(); });
    });
  }

  // ── Photo tile click → lightbox ───────────────────────────────────────────
  document.addEventListener('click', function(e) {
    var tile = e.target.closest('.mw-photo-tile');
    if (tile && !e.target.classList.contains('mw-photo-delete-btn')) {
      openLightbox(tile);
    }
  });

  // ── Inline delete buttons ─────────────────────────────────────────────────
  document.addEventListener('click', function(e) {
    if (!e.target.classList.contains('mw-photo-delete-btn')) return;
    e.stopPropagation();
    var photoId = parseInt(e.target.dataset.photoId, 10);
    if (!photoId) return;
    if (!confirm('Delete this photo?')) return;
    deletePhoto(photoId, function() {
      var tile = e.target.closest('.mw-photo-tile');
      if (tile) {
        tile.style.transition = 'opacity 0.2s';
        tile.style.opacity = '0';
        setTimeout(function() { tile.remove(); }, 200);
      }
      totalCount--;
      var badge = document.getElementById('photo-total-count');
      if (badge) badge.textContent = totalCount;
    });
  });

  function deletePhoto(photoId, onSuccess) {
    apiPost(API_PHOTO, { action: 'delete', photo_id: photoId })
      .then(function(res) {
        if (res.success) {
          if (onSuccess) onSuccess();
        } else {
          showAlert(res.error || 'Delete failed', 'danger');
        }
      });
  }

  // ── Upload from detail page ───────────────────────────────────────────────
  document.querySelectorAll('.mw-detail-upload-input').forEach(function(input) {
    input.addEventListener('change', function() {
      var photoType = this.dataset.type;
      var files     = Array.from(this.files);
      if (!files.length) return;

      var queue = document.getElementById('upload-queue');
      queue.style.display = 'block';

      files.forEach(function(file, idx) {
        var bar = document.createElement('div');
        bar.className = 'mb-2';
        bar.id = 'up-bar-wrap-' + idx;
        bar.innerHTML = '<div class="small text-muted text-truncate">' + file.name + '</div>'
                      + '<div class="progress" style="height:4px;">'
                      + '<div class="progress-bar" id="up-bar-' + idx + '" style="width:0%"></div>'
                      + '</div>';
        queue.appendChild(bar);

        var fd = new FormData();
        fd.append('photo',       file);
        fd.append('visit_id',    VISIT_ID);
        fd.append('action',      'upload_photo');
        fd.append('csrf_token',  CSRF);
        fd.append('photo_type',  photoType);

        var xhr = new XMLHttpRequest();
        xhr.open('POST', API_BASE, true);
        xhr.withCredentials = true;
        xhr.upload.onprogress = function(e) {
          if (e.lengthComputable) {
            document.getElementById('up-bar-' + idx).style.width =
              Math.round(e.loaded / e.total * 100) + '%';
          }
        };
        xhr.onload = function() {
          var res = {};
          try { res = JSON.parse(xhr.responseText); } catch(err) {}

          var pb = document.getElementById('up-bar-' + idx);
          if (res.success) {
            if (pb) pb.classList.add('bg-success');
            // Add tile to appropriate grid
            addTileToGrid(res, photoType);
            totalCount++;
            var badge = document.getElementById('photo-total-count');
            if (badge) badge.textContent = totalCount;
            // Remove "no photos" message if present
            var nop = document.getElementById('no-photos-msg');
            if (nop) nop.remove();
          } else {
            if (pb) pb.classList.add('bg-danger');
            showAlert(res.error || 'Upload failed', 'danger');
          }
          if (idx === files.length - 1) {
            setTimeout(function() { queue.style.display = 'none'; queue.innerHTML = ''; }, 3000);
          }
        };
        xhr.send(fd);
      });

      this.value = '';
    });
  });

  function addTileToGrid(res, photoType) {
    var bucket = ['before','after','additional'].indexOf(photoType) >= 0 ? photoType : 'other';
    var gridId = 'grid-detail-' + bucket;
    var grid   = document.getElementById(gridId);

    // If section doesn't exist yet, we need to create it
    if (!grid) {
      // Reload page to render the new section properly
      setTimeout(function() { location.reload(); }, 1500);
      return;
    }

    var thumbUrl = res.thumb_url || res.orig_url || '';
    var viewUrl  = res.view_url  || res.orig_url || '';

    var tile = document.createElement('div');
    tile.className = 'mw-photo-tile';
    tile.dataset.photoId  = res.photo_id || '';
    tile.dataset.viewUrl  = viewUrl;
    tile.dataset.type     = photoType;
    tile.dataset.caption  = '';
    tile.innerHTML =
      '<img src="' + escHtml(thumbUrl) + '" loading="lazy" alt="' + escHtml(photoType) + '"' +
           ' onerror="this.src=\'/crm/img/img-error.svg\'">' +
      '<div class="mw-photo-tile-overlay">' +
        (IS_ADMIN ? '<button class="mw-photo-delete-btn" data-photo-id="' + (res.photo_id || '') + '" title="Delete">✕</button>' : '') +
      '</div>';
    grid.appendChild(tile);
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

  // ── Lock / Unlock ─────────────────────────────────────────────────────────
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

  var btnUnlock = document.getElementById('btn-unlock');
  if (btnUnlock) {
    btnUnlock.addEventListener('click', function() {
      document.getElementById('unlock-reason').value = '';
      if (typeof $ !== 'undefined') $('#unlockModal').modal('show');
    });
  }
  var btnConfirmUnlock = document.getElementById('btn-confirm-unlock');
  if (btnConfirmUnlock) {
    btnConfirmUnlock.addEventListener('click', function() {
      var reason = document.getElementById('unlock-reason').value.trim();
      if (typeof $ !== 'undefined') $('#unlockModal').modal('hide');
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

  // ── Share Token ───────────────────────────────────────────────────────────
  var btnGenToken = document.getElementById('btn-generate-token');
  if (btnGenToken) {
    btnGenToken.addEventListener('click', function() {
      var self = this;
      self.disabled = true;
      self.innerHTML = '<span class="spinner-border spinner-border-sm mr-1"></span>Generating…';

      var notifyChk = document.getElementById('chk-notify-client');
      apiPost(API_TOKEN, { action: 'generate', notify_client: !!(notifyChk && notifyChk.checked) }).then(function(res) {
        if (res.success && res.token) {
          var url = SITE_URL + '/client/proof.php?token=' + res.token + '&visit=' + VISIT_ID;
          // Show the URL in the input and copy group
          var grp = document.getElementById('share-url-group');
          var inp = document.getElementById('share-url-input');
          if (grp && inp) {
            inp.value = url;
            grp.style.display = '';
          }
          if (res.notified) {
            showAlert('Share link generated and client notified by email!', 'success');
          } else {
            showAlert('Share link generated! Copy the link to send to your client.', 'success');
          }
          setTimeout(function() { location.reload(); }, 3000);
        } else {
          showAlert(res.error || 'Failed to generate token', 'danger');
        }
        self.disabled = false;
        self.innerHTML = '<i data-feather="link" class="mr-1"></i>Regenerate Link';
        if (typeof feather !== 'undefined') feather.replace();
      });
    });
  }

  var btnRevokeToken = document.getElementById('btn-revoke-token');
  if (btnRevokeToken) {
    btnRevokeToken.addEventListener('click', function() {
      if (!confirm('Revoke the share link? The client will no longer be able to view their gallery with the current link.')) return;
      apiPost(API_TOKEN, { action: 'revoke' }).then(function(res) {
        if (res.success) { location.reload(); }
        else showAlert(res.error || 'Revoke failed', 'danger');
      });
    });
  }

  var btnCopyLink = document.getElementById('btn-copy-link');
  if (btnCopyLink) {
    btnCopyLink.addEventListener('click', function() {
      var inp = document.getElementById('share-url-input');
      if (inp && inp.value) {
        navigator.clipboard.writeText(inp.value).then(function() {
          showAlert('Link copied to clipboard.', 'success');
        }).catch(function() {
          inp.select();
          document.execCommand('copy');
          showAlert('Link copied.', 'success');
        });
      }
    });
  }

  // ── Email PoW ─────────────────────────────────────────────────────────────
  var btnEmail = document.getElementById('btn-email-pow');
  if (btnEmail && typeof $ !== 'undefined') {
    btnEmail.addEventListener('click', function() { $('#emailPowModal').modal('show'); });
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
        if (typeof $ !== 'undefined') $('#emailPowModal').modal('hide');
        if (res.success) showAlert('Email sent to ' + recipient, 'success');
        else showAlert(res.error || 'Email failed.', 'danger');
        self.disabled = false;
        self.textContent = 'Send Email';
      });
    });
  }

  // ── GPS Backfill ──────────────────────────────────────────────────────────
  var btnBackfill = document.getElementById('btn-backfill-gps');
  if (btnBackfill) {
    btnBackfill.addEventListener('click', function() {
      var self = this;
      self.disabled = true;
      self.textContent = 'Pulling…';
      fetch('/crm/api/job-timer.php', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        credentials: 'same-origin',
        body: JSON.stringify({ action: 'backfill_gps', visit_id: VISIT_ID, csrf_token: CSRF })
      }).then(function(r) { return r.json(); }).then(function(res) {
        if (res.success && res.inserted > 0) {
          location.reload();
        } else if (res.success && res.inserted === 0) {
          showAlert('No nearby crew history pings found for this visit date.', 'info');
          self.disabled = false;
          self.innerHTML = '<i data-feather="download-cloud" style="width:14px;height:14px;"></i> Pull from crew history';
          if (typeof feather !== 'undefined') feather.replace();
        } else {
          showAlert(res.error || 'Backfill failed.', 'danger');
          self.disabled = false;
          self.innerHTML = '<i data-feather="download-cloud" style="width:14px;height:14px;"></i> Pull from crew history';
          if (typeof feather !== 'undefined') feather.replace();
        }
      }).catch(function() {
        showAlert('Network error. Please try again.', 'danger');
        self.disabled = false;
      });
    });
  }

  function escHtml(str) {
    var d = document.createElement('div');
    d.appendChild(document.createTextNode(str || ''));
    return d.innerHTML;
  }

})();
</script>

<?php include dirname(__DIR__) . '/includes/appstack_footer.php'; ?>

<?php
/**
 * Customer Portal — Proof of Work View
 * Token-based (no login required).
 * Shows completed visit reports and PDF download link.
 *
 * URL: /customer/pow.php?token=ABC123&visit_id=42
 * Token verified against quotes.access_token or a dedicated pow_access_token on the plan.
 */

header('X-Content-Type-Options: nosniff');
header('X-Frame-Options: SAMEORIGIN');

require_once dirname(__DIR__) . '/app_config/config.php';

$db    = getDB();
$error = '';

$token   = trim($_GET['token'] ?? '');
$visitId = isset($_GET['visit_id']) ? (int)$_GET['visit_id'] : 0;

// ── Validate token ─────────────────────────────────────────────────────────
if (!$token || !$visitId) {
    $error = 'Invalid link. Please check your email for the correct Proof of Work link.';
}

$visit = null;
$plan  = null;
$quote = null;

if (!$error) {
    $stmt = $db->prepare("
        SELECT
            v.*,
            p.plan_number, p.title AS plan_title, p.service_type AS plan_service_type,
            p.id AS plan_db_id,
            p.is_prepaid_bundle, p.bundle_applications_used, p.source_bundle_id,
            pr.address AS property_address, pr.city AS property_city,
            pr.province AS property_province, pr.postal_code AS property_postal,
            c.first_name AS contact_first, c.last_name AS contact_last,
            c.email AS contact_email,
            q.access_token AS quote_token,
            q.token_expires_at
        FROM job_visits v
        JOIN job_plans p ON v.plan_id = p.id
        LEFT JOIN properties pr ON p.property_id = pr.id
        LEFT JOIN contacts c ON pr.site_contact_id = c.id
        LEFT JOIN quotes q ON p.quote_id = q.id
        WHERE v.id = ?
    ");
    $stmt->execute([$visitId]);
    $visit = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$visit) {
        $error = 'Visit record not found.';
    } elseif ($visit['proof_complete'] != 1 && empty($visit['pdf_path'])) {
        $error = 'This visit report is not yet available. Please check back after your scheduled service date.';
    } else {
        // Token check: must match quote access token and not be expired
        $tokenValid = false;
        if ($visit['quote_token'] && hash_equals($visit['quote_token'], $token)) {
            if (!$visit['token_expires_at'] || strtotime($visit['token_expires_at']) > time()) {
                $tokenValid = true;
            }
        }

        if (!$tokenValid) {
            $error = 'This link has expired or is invalid. Please contact us for access.';
        }
    }
}

// ── Load visit extras ──────────────────────────────────────────────────────
$photos    = [];
$notes     = [];
$checklist = [];
$confBox   = [];

if (!$error && $visit) {
    $visit['service_type'] = $visit['service_type'] ?? $visit['plan_service_type'] ?? 'general';

    // Photos (public)
    $stmt = $db->prepare("
        SELECT id, photo_type, filename, caption FROM visit_photos
        WHERE visit_id = ? ORDER BY photo_type, uploaded_at
    ");
    $stmt->execute([$visitId]);
    $photos = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // Notes visible to customer
    $stmt = $db->prepare("
        SELECT content, note_type, created_at FROM visit_notes
        WHERE visit_id = ? AND is_visible_to_customer = 1 ORDER BY created_at
    ");
    $stmt->execute([$visitId]);
    $notes = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $checklist = !empty($visit['checklist_json'])      ? json_decode($visit['checklist_json'], true)      : [];
    $confBox   = !empty($visit['confidence_box_json']) ? json_decode($visit['confidence_box_json'], true) : [];
    $materials = !empty($visit['materials_json'])      ? json_decode($visit['materials_json'], true)      : [];

    // Fertilizer card extras: product + plan visits for progress indicator
    $fertProduct   = null;
    $fertAllVisits = [];
    if (!empty($visit['is_prepaid_bundle'])) {
        $pliStmt = $db->prepare("
            SELECT pr.name AS product_name, pr.application_notes,
                   pr.icon_base_path, pr.is_sold AS product_is_sold,
                   pr.application_rate
            FROM plan_line_items pli
            JOIN products pr ON pli.product_id = pr.id
            WHERE pli.plan_id = ? AND pli.product_id IS NOT NULL
            ORDER BY pli.sort_order ASC LIMIT 1
        ");
        $pliStmt->execute([$visit['plan_db_id']]);
        $fertProduct = $pliStmt->fetch(PDO::FETCH_ASSOC) ?: null;

        $avStmt = $db->prepare("
            SELECT id, scheduled_date, status, sequence_index
            FROM job_visits
            WHERE plan_id = ?
            ORDER BY sequence_index ASC, scheduled_date ASC
        ");
        $avStmt->execute([$visit['plan_db_id']]);
        $fertAllVisits = $avStmt->fetchAll(PDO::FETCH_ASSOC);
    }
}

$clientName = $visit ? trim(($visit['contact_first'] ?? '') . ' ' . ($visit['contact_last'] ?? '')) : 'Client';
$visitDate  = $visit && $visit['started_at'] ? date('F j, Y', strtotime($visit['started_at'])) : '';
$address    = $visit ? implode(', ', array_filter([
    $visit['property_address'] ?? '',
    $visit['property_city'] ?? '',
    $visit['property_province'] ?? '',
    $visit['property_postal'] ?? '',
])) : '';

$hasPdf  = $visit && !empty($visit['pdf_path']);
$pdfUrl  = $hasPdf ? $visit['pdf_path'] : '';

$duration = '';
if ($visit && $visit['started_at'] && $visit['completed_at']) {
    $mins     = (int)round((strtotime($visit['completed_at']) - strtotime($visit['started_at'])) / 60);
    $duration = ($mins >= 60 ? floor($mins/60).'h ' : '') . ($mins % 60) . 'm';
}

$siteUrl = defined('SITE_URL') ? SITE_URL : 'https://mowology.ca';
$year    = date('Y');

$serviceLabel = [
    'fertilizer'     => 'Fertilizer / Lawn Treatment',
    'lawn_treatment' => 'Fertilizer / Lawn Treatment',
    'salt'           => 'Salt / De-Icing Service',
    'de_ice'         => 'Salt / De-Icing Service',
    'snow'           => 'Snow Clearance',
    'snow_clearance' => 'Snow Clearance',
    'mowing'         => 'Lawn Mowing',
    'general'        => 'Landscaping Service',
][$visit['service_type'] ?? 'general'] ?? 'Landscaping Service';
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>Proof of Work — Mowology Landscaping</title>
  <meta name="robots" content="noindex,nofollow">
  <link rel="stylesheet" href="/customer/portal.css">
</head>
<body>

<header class="portal-header">
  <div class="portal-logo-text">Mowo<span>logy</span></div>
  <div class="portal-header-divider"></div>
  <span class="portal-header-label">Service Report</span>
  <?php if ($clientName): ?>
    <span class="portal-client-name"><?php echo htmlspecialchars($clientName); ?></span>
  <?php endif; ?>
</header>

<div class="portal-container">

<?php if ($error): ?>
  <div class="portal-error">
    <svg xmlns="http://www.w3.org/2000/svg" width="48" height="48" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round" style="color:#cdddd6;margin:0 auto 16px;display:block"><circle cx="12" cy="12" r="10"/><line x1="12" y1="8" x2="12" y2="12"/><line x1="12" y1="16" x2="12.01" y2="16"/></svg>
    <h2>Report Unavailable</h2>
    <p><?php echo htmlspecialchars($error); ?></p>
    <p style="margin-top:12px;"><a href="<?php echo htmlspecialchars($siteUrl); ?>">← Return to Mowology.ca</a></p>
  </div>
<?php else: ?>

  <!-- Visit Hero -->
  <div class="portal-hero">
    <div class="portal-hero-greeting"><?php echo htmlspecialchars($serviceLabel); ?></div>
    <div class="portal-hero-sub" style="display:flex;align-items:center;gap:10px;flex-wrap:wrap;">
      <span class="portal-status status-accepted">Completed</span>
      <?php if ($visitDate): ?>
        <span><?php echo htmlspecialchars($visitDate); ?></span>
      <?php endif; ?>
      <?php if ($address): ?>
        <span style="color:var(--p-text-muted);">·</span>
        <span><?php echo htmlspecialchars($address); ?></span>
      <?php endif; ?>
    </div>

    <!-- Stat Trio -->
    <div class="portal-stat-trio">
      <div class="portal-stat-box">
        <div class="portal-stat-val"><?php echo htmlspecialchars($duration ?: '—'); ?></div>
        <div class="portal-stat-lbl">Duration</div>
      </div>
      <div class="portal-stat-box">
        <div class="portal-stat-val"><?php echo $visit['distance_m'] ? number_format($visit['distance_m']/1000, 2).' km' : '—'; ?></div>
        <div class="portal-stat-lbl">Area Covered</div>
      </div>
      <div class="portal-stat-box">
        <div class="portal-stat-val"><?php echo htmlspecialchars((string)($visit['actual_crew_count'] ?? 1)); ?></div>
        <div class="portal-stat-lbl">Crew Members</div>
      </div>
    </div>
  </div>

  <!-- GPS Route Map -->
  <?php if (!empty($visit['map_snapshot_path'])): ?>
  <div class="portal-info-card">
    <div class="portal-info-card-header">GPS Route</div>
    <div class="portal-info-card-body">
      <img src="<?php echo htmlspecialchars($visit['map_snapshot_path']); ?>"
           alt="GPS Route Map" class="portal-route-map">
      <p style="font-size:0.72rem;color:var(--p-text-muted);margin-top:8px;text-align:center;">
        Tracked route for this service visit (<?php echo (int)$visit['gps_points_count']; ?> GPS points)
      </p>
    </div>
  </div>
  <?php endif; ?>

  <!-- Checklist -->
  <?php if (!empty($checklist)): ?>
  <div class="portal-info-card">
    <div class="portal-info-card-header">What We Completed</div>
    <div class="portal-info-card-body">
      <?php foreach ($checklist as $item): ?>
      <div class="portal-check-item">
        <span class="portal-check-icon<?php echo !empty($item['checked']) ? '' : ' pending'; ?>">
          <?php echo !empty($item['checked']) ? '✓' : '○'; ?>
        </span>
        <div>
          <div style="font-size:0.875rem;"><?php echo htmlspecialchars($item['item'] ?? ''); ?></div>
          <?php if (!empty($item['note'])): ?>
            <div style="font-size:0.75rem;color:var(--p-text-muted);margin-top:2px;"><?php echo htmlspecialchars($item['note']); ?></div>
          <?php endif; ?>
        </div>
      </div>
      <?php endforeach; ?>
    </div>
  </div>
  <?php endif; ?>

  <!-- Photos -->
  <?php if (!empty($photos)): ?>
  <div class="portal-info-card">
    <div class="portal-info-card-header">Site Photos</div>
    <div class="portal-info-card-body">
      <div style="display:grid;grid-template-columns:repeat(auto-fill,minmax(140px,1fr));gap:10px;">
        <?php foreach ($photos as $ph): ?>
        <div>
          <a href="/uploads/photos/<?php echo htmlspecialchars($ph['filename']); ?>" target="_blank">
            <img src="/uploads/photos/<?php echo htmlspecialchars($ph['filename']); ?>"
                 alt="<?php echo htmlspecialchars($ph['photo_type']); ?>"
                 style="width:100%;height:100px;object-fit:cover;border-radius:var(--p-radius-sm);border:1px solid var(--p-border);display:block;"
                 onerror="this.parentElement.parentElement.style.display='none'">
          </a>
          <div style="font-size:0.65rem;font-weight:700;text-transform:uppercase;color:var(--p-green);margin-top:4px;letter-spacing:0.04em;"><?php echo htmlspecialchars($ph['photo_type']); ?></div>
          <?php if (!empty($ph['caption'])): ?>
            <div style="font-size:0.7rem;color:var(--p-text-muted);"><?php echo htmlspecialchars($ph['caption']); ?></div>
          <?php endif; ?>
        </div>
        <?php endforeach; ?>
      </div>
    </div>
  </div>
  <?php endif; ?>

  <!-- Crew Notes -->
  <?php if (!empty($notes)): ?>
  <div class="portal-info-card">
    <div class="portal-info-card-header">Crew Notes</div>
    <div class="portal-info-card-body">
      <?php foreach ($notes as $n): ?>
        <div class="portal-note-item"><?php echo nl2br(htmlspecialchars($n['content'])); ?></div>
      <?php endforeach; ?>
    </div>
  </div>
  <?php endif; ?>

  <!-- Confidence Box -->
  <?php if (!empty($confBox)): ?>
  <div class="portal-info-card">
    <div class="portal-info-card-header">Your Service Summary</div>
    <div class="portal-info-card-body">
      <div class="portal-confidence-box">
        <h4>What We Did Today for You</h4>
        <div class="portal-conf-cols">
          <?php if (!empty($confBox['done'])): ?>
          <div class="portal-conf-col">
            <h5>Completed</h5>
            <ul>
              <?php foreach ($confBox['done'] as $bullet): ?>
                <li><?php echo htmlspecialchars($bullet); ?></li>
              <?php endforeach; ?>
            </ul>
          </div>
          <?php endif; ?>
          <?php if (!empty($confBox['next'])): ?>
          <div class="portal-conf-col">
            <h5>What's Next</h5>
            <ul>
              <?php foreach ($confBox['next'] as $bullet): ?>
                <li><?php echo htmlspecialchars($bullet); ?></li>
              <?php endforeach; ?>
            </ul>
          </div>
          <?php endif; ?>
        </div>
        <?php if (!empty($confBox['upsell'])): ?>
          <div class="portal-upsell-block">
            <strong>Recommendation:</strong> <?php echo htmlspecialchars($confBox['upsell']); ?>
          </div>
        <?php endif; ?>
      </div>
    </div>
  </div>
  <?php endif; ?>

  <!-- Fertilizer Bundle Card -->
  <?php if (!empty($visit['is_prepaid_bundle'])): ?>
  <?php
    $siteUrlClean  = defined('SITE_URL') ? rtrim(SITE_URL, '/') : 'https://mowology.ca';
    $totalApps     = count($fertAllVisits);
    $completedApps = (int)($visit['bundle_applications_used'] ?? 0);
    $nextAppDate   = null;
    foreach ($fertAllVisits as $av) {
        if ($av['status'] === 'scheduled') { $nextAppDate = $av['scheduled_date']; break; }
    }
    $arrTime  = !empty($visit['started_at'])   ? date('g:ia', strtotime($visit['started_at']))   : '';
    $depTime  = !empty($visit['completed_at']) ? date('g:ia', strtotime($visit['completed_at'])) : '';
    $durMins  = (int)($visit['actual_duration_minutes'] ?? 0);
    $durStr   = $durMins >= 60 ? floor($durMins/60) . 'h ' . ($durMins%60) . 'm' : ($durMins > 0 ? $durMins . 'min' : '');
    $iconUrl  = '';
    if ($fertProduct && !empty($fertProduct['icon_base_path'])) {
        $iconFile = !empty($fertProduct['product_is_sold']) ? 'icon-full.png' : 'icon-grey.png';
        $iconUrl  = $siteUrlClean . '/' . ltrim(rtrim($fertProduct['icon_base_path'], '/') . '/' . $iconFile, '/');
    }
  ?>
  <div class="portal-info-card" style="border-left:3px solid var(--p-green);">
    <div class="portal-info-card-body">
      <div style="display:flex;align-items:center;gap:16px;margin-bottom:14px;">
        <?php if ($iconUrl): ?>
          <img src="<?php echo htmlspecialchars($iconUrl); ?>" alt="" style="width:52px;height:52px;object-fit:contain;" onerror="this.style.display='none'">
        <?php endif; ?>
        <div>
          <div style="font-size:0.95rem;font-weight:700;color:var(--p-forest);"><?php echo htmlspecialchars($visit['plan_title'] ?: 'Fertilizer Program'); ?></div>
          <?php if (!empty($visit['sequence_index'])): ?>
            <div style="font-size:0.8rem;color:var(--p-green);font-weight:600;margin-top:2px;">Application <?php echo (int)$visit['sequence_index']; ?> of <?php echo $totalApps; ?></div>
          <?php endif; ?>
        </div>
      </div>

      <?php if (!empty($fertProduct['application_notes'])): ?>
        <p style="font-size:0.82rem;color:var(--p-text-mid);margin-bottom:14px;line-height:1.5;"><?php echo htmlspecialchars($fertProduct['application_notes']); ?></p>
      <?php endif; ?>

      <?php if (!empty($materials)): ?>
      <div style="background:var(--p-bg-subtle);border-radius:var(--p-radius-sm);padding:12px 14px;margin-bottom:14px;">
        <div style="font-size:0.65rem;text-transform:uppercase;letter-spacing:0.06em;color:var(--p-text-light);font-weight:700;margin-bottom:8px;">What Was Applied</div>
        <?php foreach ($materials as $mat): ?>
          <div style="display:flex;justify-content:space-between;align-items:baseline;padding:4px 0;font-size:0.82rem;">
            <span style="color:var(--p-text-body);"><?php echo htmlspecialchars($mat['product_name'] ?? ''); ?></span>
            <strong style="color:var(--p-forest);"><?php echo htmlspecialchars($mat['qty'] . ' ' . ($mat['unit'] ?? 'bags')); ?></strong>
          </div>
          <?php if (!empty($mat['area_sqft'])): ?>
            <div style="font-size:0.7rem;color:var(--p-text-muted);padding-bottom:4px;"><?php echo number_format($mat['area_sqft']); ?> sq ft covered</div>
          <?php endif; ?>
        <?php endforeach; ?>
      </div>
      <?php endif; ?>

      <?php if ($arrTime || $depTime): ?>
      <div style="font-size:0.82rem;color:var(--p-text-mid);margin-bottom:14px;">
        <span style="font-weight:700;color:var(--p-green);">✓ GPS Verified</span><br>
        <?php if ($arrTime): ?>Arrived <strong><?php echo $arrTime; ?></strong><?php endif; ?>
        <?php if ($arrTime && $depTime): ?> &middot; <?php endif; ?>
        <?php if ($depTime): ?>Departed <strong><?php echo $depTime; ?></strong><?php endif; ?>
        <?php if ($durStr): ?> <span style="color:var(--p-text-muted);">(<?php echo $durStr; ?>)</span><?php endif; ?>
        <?php if (!empty($visit['property_address'])): ?>
          <br><span style="color:var(--p-text-muted);"><?php echo htmlspecialchars($visit['property_address'] . (isset($visit['property_city']) ? ', ' . $visit['property_city'] : '')); ?></span>
        <?php endif; ?>
      </div>
      <?php endif; ?>

      <?php if ($totalApps > 1): ?>
      <div style="background:var(--p-bg-subtle);border-radius:var(--p-radius-sm);padding:12px 14px;">
        <div style="font-size:0.65rem;text-transform:uppercase;letter-spacing:0.06em;color:var(--p-text-light);font-weight:700;margin-bottom:6px;">Your Program</div>
        <div class="portal-bundle-dots">
          <?php for ($i = 0; $i < $totalApps; $i++) echo $i < $completedApps ? '●' : '○'; ?>
        </div>
        <div style="font-size:0.82rem;color:var(--p-text-mid);margin-top:6px;">
          <?php echo $completedApps; ?> of <?php echo $totalApps; ?> applications complete
          <?php if ($nextAppDate): ?> &middot; Next: <?php echo date('F Y', strtotime($nextAppDate)); ?><?php endif; ?>
        </div>
      </div>
      <?php endif; ?>
    </div>
  </div>
  <?php endif; ?>

  <!-- PDF Download -->
  <div class="portal-info-card">
    <div class="portal-info-card-header">Full Report</div>
    <div class="portal-info-card-body">
      <?php if ($hasPdf): ?>
        <a href="<?php echo htmlspecialchars($pdfUrl); ?>" target="_blank" class="portal-btn wide" download>
          <svg xmlns="http://www.w3.org/2000/svg" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M21 15v4a2 2 0 01-2 2H5a2 2 0 01-2-2v-4M7 10l5 5 5-5M12 15V3"/></svg>
          Download Proof of Work PDF
        </a>
        <p style="font-size:0.72rem;color:var(--p-text-muted);margin-top:10px;text-align:center;">
          Includes GPS route, photos, materials, checklist, and your service summary.
        </p>
      <?php else: ?>
        <p style="font-size:0.875rem;color:var(--p-text-mid);">
          Your full PDF report will be available shortly. Please check back or contact us.
        </p>
      <?php endif; ?>
    </div>
  </div>

<?php endif; ?>

  <div class="portal-footer">
    <a href="<?php echo htmlspecialchars($siteUrl); ?>">Mowology Landscaping</a> &middot;
    Questions? <a href="tel:7788469273">(778) 846-9273</a> &middot;
    <a href="mailto:office@mowology.ca">office@mowology.ca</a><br>
    &copy; <?php echo $year; ?> Mowology Landscaping. All rights reserved.
  </div>

</div>
</body>
</html>

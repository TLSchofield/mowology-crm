<?php
/**
 * Customer Portal — Service Visit Report
 *
 * Accessible via:
 *   /customer/visit-report.php?token=PORTAL_TOKEN&visit_id=42   (client access)
 *   /customer/visit-report.php?contact_id=553&visit_id=42       (admin preview, requires CRM session)
 *
 * Displays a polished, print-ready GPS-verified service visit report
 * with photos, products applied, aftercare instructions, and crew notes.
 */

header('X-Content-Type-Options: nosniff');
header('X-Frame-Options: SAMEORIGIN');

require_once dirname(__DIR__) . '/app_config/config.php';

$db = getDB();

$contact   = null;
$adminMode = false;
$error     = '';
$visitId   = isset($_GET['visit_id']) ? (int)$_GET['visit_id'] : 0;

if (!$visitId) {
    $error = 'No visit specified.';
}

// ── Mode 1: Admin preview via CRM session ──────────────────────────────────
if (!$error && isset($_GET['contact_id'])) {
    require_once dirname(__DIR__) . '/loginAuth/auth.php';
    requireLogin();
    $adminMode = true;
    $contactId = (int)$_GET['contact_id'];
    if (!$contactId) {
        $error = 'Invalid contact.';
    } else {
        $stmt = $db->prepare("SELECT id, first_name, last_name, email, phone, portal_token FROM contacts WHERE id = ? LIMIT 1");
        $stmt->execute([$contactId]);
        $contact = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$contact) $error = 'Contact not found.';
    }

// ── Mode 2: Client access via portal token ─────────────────────────────────
} elseif (!$error && isset($_GET['token']) && $_GET['token'] !== '') {
    $token = trim($_GET['token']);
    $stmt = $db->prepare("SELECT id, first_name, last_name, email, phone, portal_token FROM contacts WHERE portal_token = ? LIMIT 1");
    $stmt->execute([$token]);
    $contact = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$contact) $error = 'This portal link is invalid or has expired.';
} elseif (!$error) {
    $error = 'No portal link provided.';
}

// ── Load visit + verify ownership ─────────────────────────────────────────
$visit    = null;
$property = null;
$plan     = null;

if (!$error && $contact) {
    try {
        $stmt = $db->prepare("
            SELECT
                jv.id,
                jv.visit_number,
                jv.scheduled_date,
                jv.completed_at,
                jv.actual_duration_minutes,
                jv.actual_amount,
                jv.actual_crew_count,
                jv.status,
                jv.plan_id,
                jv.sequence_index,
                jp.title          AS plan_title,
                jp.service_type,
                jp.is_prepaid_bundle,
                p.id              AS property_id,
                p.address         AS property_address,
                p.city            AS property_city,
                p.province        AS property_province,
                p.postal_code     AS property_postal,
                p.site_contact_id
            FROM job_visits jv
            JOIN job_plans jp  ON jp.id = jv.plan_id
            JOIN properties p  ON p.id  = jp.property_id
            WHERE jv.id = ?
              AND jv.status = 'completed'
              AND p.site_contact_id = ?
            LIMIT 1
        ");
        $stmt->execute([$visitId, $contact['id']]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$row) {
            $error = 'Visit not found or you do not have access.';
        } else {
            $visit = $row;
        }
    } catch (Exception $e) {
        $error = 'Unable to load visit data.';
    }
}

// ── Load photos ────────────────────────────────────────────────────────────
$photos = [];
if (!$error && $visit) {
    try {
        $stmt = $db->prepare("
            SELECT ma.id,
                   ma.file_path,
                   ml.category    AS photo_type,
                   ma.caption,
                   mv.file_path   AS thumb_path,
                   ma.created_at  AS uploaded_at,
                   ma.captured_at
            FROM media_links ml
            JOIN media_assets ma ON ma.id = ml.media_id
                      AND ma.status != 'deleted'
            LEFT JOIN media_variants mv ON mv.media_id = ma.id
                      AND mv.variant_type = 'thumb_square'
                      AND mv.format = 'jpeg'
            WHERE ml.context_type = 'job_visit'
              AND ml.context_id = ?
            ORDER BY ma.created_at ASC
        ");
        $stmt->execute([$visitId]);
        $photos = $stmt->fetchAll(PDO::FETCH_ASSOC);
    } catch (Exception $e) {
        $photos = [];
    }
}

// ── Load GPS pings ─────────────────────────────────────────────────────────
$pings = [];
if (!$error && $visit) {
    try {
        $stmt = $db->prepare("
            SELECT latitude, longitude, timestamp
            FROM crew_location_history
            WHERE visit_id = ?
            ORDER BY timestamp ASC
        ");
        $stmt->execute([$visitId]);
        $pings = $stmt->fetchAll(PDO::FETCH_ASSOC);
    } catch (Exception $e) {
        $pings = [];
    }
}

// ── Load product/service line items ────────────────────────────────────────
$productLines = [];
if (!$error && $visit) {
    try {
        $stmt = $db->prepare("
            SELECT pli.quantity,
                   pli.unit,
                   pr.name              AS product_name,
                   pr.description       AS product_description,
                   pr.application_notes AS application_notes,
                   pr.application_rate  AS application_rate
            FROM plan_line_items pli
            JOIN products pr ON pr.id = pli.product_id
            WHERE pli.plan_id = ?
              AND pli.product_id IS NOT NULL
            ORDER BY pli.sort_order ASC
        ");
        $stmt->execute([$visit['plan_id']]);
        $productLines = $stmt->fetchAll(PDO::FETCH_ASSOC);
    } catch (Exception $e) {
        $productLines = [];
    }
}

// ── Load customer-visible notes ─────────────────────────────────────────────
$visibleNotes = [];
if (!$error && $visit) {
    try {
        $stmt = $db->prepare("
            SELECT note, created_at
            FROM visit_notes
            WHERE visit_id = ?
              AND is_visible_to_customer = 1
            ORDER BY created_at ASC
        ");
        $stmt->execute([$visitId]);
        $visibleNotes = $stmt->fetchAll(PDO::FETCH_ASSOC);
    } catch (Exception $e) {
        $visibleNotes = [];
    }
}

// ── Load bundle progress ───────────────────────────────────────────────────
$bundleVisits = [];
if (!$error && $visit && !empty($visit['is_prepaid_bundle'])) {
    try {
        $stmt = $db->prepare("
            SELECT id, sequence_index, status, scheduled_date, completed_at
            FROM job_visits
            WHERE plan_id = ?
            ORDER BY sequence_index ASC
        ");
        $stmt->execute([$visit['plan_id']]);
        $bundleVisits = $stmt->fetchAll(PDO::FETCH_ASSOC);
    } catch (Exception $e) {
        $bundleVisits = [];
    }
}

// ── Build GPS static map URL ────────────────────────────────────────────────
$gmapUrl    = '';
$arrivalTime  = '';
$departTime   = '';
$pingCount    = count($pings);

if ($pingCount > 0) {
    $arrivalTime = date('g:i a', strtotime($pings[0]['timestamp']));
    $departTime  = date('g:i a', strtotime($pings[$pingCount - 1]['timestamp']));

    // Sample down to ≤ 60 points (evenly spaced, always include last)
    $sampled = $pings;
    if ($pingCount > 60) {
        $step    = (int)ceil($pingCount / 60);
        $sampled = [];
        for ($si = 0; $si < $pingCount; $si += $step) {
            $sampled[] = $pings[$si];
        }
        if (end($sampled) !== end($pings)) {
            $sampled[] = end($pings);
        }
    }

    $coords     = array_map(fn($p) => $p['latitude'] . ',' . $p['longitude'], $sampled);
    $startCoord = $pings[0]['latitude'] . ',' . $pings[0]['longitude'];
    $endCoord   = $pings[$pingCount - 1]['latitude'] . ',' . $pings[$pingCount - 1]['longitude'];

    $avgLat = array_sum(array_column($pings, 'latitude'))  / $pingCount;
    $avgLng = array_sum(array_column($pings, 'longitude')) / $pingCount;

    // Bounding box from ping extents + padding
    $lats   = array_column($pings, 'latitude');
    $lngs   = array_column($pings, 'longitude');
    $minLat = (float)min($lats); $maxLat = (float)max($lats);
    $minLng = (float)min($lngs); $maxLng = (float)max($lngs);
    $padLat = max(0.002, ($maxLat - $minLat) * 0.6);
    $padLng = max(0.003, ($maxLng - $minLng) * 0.6);
    $bbox   = ($minLng - $padLng) . ',' . ($minLat - $padLat) . ','
            . ($maxLng + $padLng) . ',' . ($maxLat + $padLat);

    // ArcGIS World Street Map — no API key, already in CSP
    $gmapUrl = 'https://server.arcgisonline.com/ArcGIS/rest/services/World_Street_Map/MapServer/export'
        . '?bbox=' . $bbox
        . '&bboxSR=4326&size=680,300&imageSR=4326&format=png&transparent=false&f=image';

}
// (No pings + no property coords → $gmapUrl stays empty, map section is skipped)

// ── Computed display values ────────────────────────────────────────────────
$contactName = $contact
    ? trim(htmlspecialchars($contact['first_name'] . ' ' . ($contact['last_name'] ?? '')))
    : 'Client';

$serviceLabel = '';
$visitDate    = '';
$durationStr  = '';
$visitNumStr  = '';
$crewCount    = 0;
$propertyAddr = '';

if ($visit) {
    $serviceLabel = htmlspecialchars($visit['plan_title'] ?: ucwords(str_replace('_', ' ', $visit['service_type'] ?? '')));
    $visitDate    = date('F j, Y', strtotime($visit['scheduled_date']));
    $visitNumStr  = htmlspecialchars($visit['visit_number'] ?? ('#' . $visit['id']));
    $crewCount    = (int)($visit['actual_crew_count'] ?? 1);

    $durMins = (int)($visit['actual_duration_minutes'] ?? 0);
    if ($durMins > 0) {
        $durHrs = intdiv($durMins, 60);
        $durRem = $durMins % 60;
        $durationStr = $durHrs > 0
            ? ($durHrs . 'h' . ($durRem > 0 ? ' ' . $durRem . 'm' : ''))
            : ($durRem . 'm');
    }

    $addrParts = array_filter([
        $visit['property_address'],
        $visit['property_city'],
        $visit['property_province'],
    ]);
    $propertyAddr = htmlspecialchars(implode(', ', $addrParts));
}

// Bundle progress helpers
$bundleTotal     = count($bundleVisits);
$bundleCompleted = 0;
$bundleNextDate  = '';
if (!empty($bundleVisits)) {
    foreach ($bundleVisits as $bv) {
        if ($bv['status'] === 'completed') $bundleCompleted++;
    }
    foreach ($bundleVisits as $bv) {
        if ($bv['status'] === 'scheduled' && !empty($bv['scheduled_date'])) {
            $bundleNextDate = date('F j, Y', strtotime($bv['scheduled_date']));
            break;
        }
    }
}

// Check if any product has aftercare notes
$hasAftercare = false;
foreach ($productLines as $pl) {
    if (!empty($pl['application_notes'])) {
        $hasAftercare = true;
        break;
    }
}

$reportDate = date('F j, Y');
$pageTitle  = 'Service Visit Report' . ($visit ? ' — ' . $visitDate : '');
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title><?php echo htmlspecialchars($pageTitle); ?></title>
  <link rel="stylesheet" href="/customer/portal.css">
  <link rel="stylesheet" href="/customer/visit-report.css">
  <?php if ($pingCount > 0): ?>
  <link rel="stylesheet" href="https://unpkg.com/leaflet@1.9.4/dist/leaflet.css" crossorigin="">
  <?php endif; ?>
</head>
<body>

<!-- Portal Header (sticky, hidden on print) -->
<header class="portal-header">
  <div class="portal-logo-text">Mowo<span>logy</span></div>
  <div class="portal-header-divider"></div>
  <span class="portal-header-label">Service Report</span>
  <?php if ($contact): ?>
    <span class="portal-client-name"><?php echo $contactName; ?></span>
  <?php endif; ?>
</header>

<?php if ($adminMode): ?>
<div class="portal-admin-banner">
  <div style="display:flex;align-items:center;gap:8px;">
    <span class="portal-admin-tag">Admin Preview</span>
    <span>Viewing as <strong><?php echo $contactName; ?></strong></span>
  </div>
  <a href="/crm/clients_appstack.php?action=view_contact&id=<?php echo (int)($contact['id'] ?? 0); ?>">&#8592; Back to CRM</a>
</div>
<?php endif; ?>

<?php if ($error): ?>
  <div class="portal-error">
    <svg xmlns="http://www.w3.org/2000/svg" width="52" height="52" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round" style="color:#cdddd6;margin:0 auto 16px;display:block"><circle cx="12" cy="12" r="10"/><line x1="12" y1="8" x2="12" y2="12"/><line x1="12" y1="16" x2="12.01" y2="16"/></svg>
    <h2>Report Unavailable</h2>
    <p><?php echo htmlspecialchars($error); ?></p>
  </div>

<?php else: ?>

<!-- Print-only report header (hidden in browser, shown on print) -->
<div class="pvr-print-header">
  <strong>Mowology</strong> &mdash; Service Visit Report &mdash; <?php echo htmlspecialchars($visitDate); ?>
</div>

<div class="pvr-page-wrap">
<div class="pvr-doc">

  <!-- 1. Report Header Block ───────────────────────────────────────────── -->
  <div class="pvr-doc-header">
    <div class="pvr-doc-header-logo">Mowology</div>
    <div class="pvr-doc-header-label">Service Visit Report</div>
    <div class="pvr-doc-header-service"><?php echo $serviceLabel; ?></div>
  </div>

  <div class="pvr-doc-body">

    <!-- Meta row: visit number, address, date -->
    <div class="pvr-meta-block">
      <div class="pvr-meta-row">
        <div class="pvr-meta-item">
          <div class="pvr-meta-label">Visit</div>
          <div class="pvr-meta-value"><?php echo $visitNumStr; ?></div>
        </div>
        <div class="pvr-meta-item">
          <div class="pvr-meta-label">Property</div>
          <div class="pvr-meta-value"><?php echo $propertyAddr; ?></div>
        </div>
        <div class="pvr-meta-item">
          <div class="pvr-meta-label">Date</div>
          <div class="pvr-meta-value">
            <span class="pvr-date-badge">Completed &middot; <?php echo htmlspecialchars($visitDate); ?></span>
          </div>
        </div>
      </div>
      <div class="pvr-stat-row">
        <?php if ($durationStr): ?>
        <div class="pvr-stat-pill">
          <div class="pvr-stat-value"><?php echo htmlspecialchars($durationStr); ?></div>
          <div class="pvr-stat-label">Duration</div>
        </div>
        <?php endif; ?>
        <?php if ($crewCount > 0): ?>
        <div class="pvr-stat-pill">
          <div class="pvr-stat-value"><?php echo $crewCount; ?></div>
          <div class="pvr-stat-label"><?php echo $crewCount === 1 ? 'Crew member' : 'Crew members'; ?></div>
        </div>
        <?php endif; ?>
        <?php if ($pingCount > 0): ?>
        <div class="pvr-stat-pill">
          <div class="pvr-stat-value"><?php echo $pingCount; ?></div>
          <div class="pvr-stat-label">GPS pings</div>
        </div>
        <?php endif; ?>
        <?php if (!empty($visit['actual_amount']) && $visit['actual_amount'] > 0): ?>
        <div class="pvr-stat-pill">
          <div class="pvr-stat-value">$<?php echo number_format((float)$visit['actual_amount'], 2); ?></div>
          <div class="pvr-stat-label">Service value</div>
        </div>
        <?php endif; ?>
      </div>
    </div>

    <!-- 2. GPS Verification Card ─────────────────────────────────────── -->
    <div class="pvr-section pvr-gps-card">
      <div class="pvr-section-title pvr-gps-title">
        <svg xmlns="http://www.w3.org/2000/svg" width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><circle cx="12" cy="12" r="3"/><path d="M12 1v4M12 19v4M4.22 4.22l2.83 2.83M16.95 16.95l2.83 2.83M1 12h4M19 12h4M4.22 19.78l2.83-2.83M16.95 7.05l2.83-2.83"/></svg>
        GPS Verified Service Route
      </div>
      <?php if ($pingCount > 0): ?>
        <?php
          $pingLatLngs = json_encode(array_map(
              fn($p) => [(float)$p['latitude'], (float)$p['longitude']],
              $pings
          ));
        ?>
        <div id="pvr-map"
             class="pvr-gps-img"
             data-pings="<?php echo htmlspecialchars($pingLatLngs, ENT_QUOTES); ?>"
             data-arrival="<?php echo htmlspecialchars($arrivalTime ?? ''); ?>">
        </div>
      <?php elseif ($gmapUrl): ?>
        <img src="<?php echo htmlspecialchars($gmapUrl); ?>"
             alt="Service location map"
             class="pvr-gps-img"
             loading="lazy">
      <?php else: ?>
        <div class="pvr-gps-unavailable">GPS data not available for this visit.</div>
      <?php endif; ?>

      <?php if ($pingCount > 0): ?>
        <!-- GPS verification stamp + data rows -->
        <div class="pvr-gps-data-block">
          <div class="pvr-gps-stamp">
            <svg xmlns="http://www.w3.org/2000/svg" width="28" height="28" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M4.93 4.93A10 10 0 1 0 19.07 19.07"/><path d="M12 8a4 4 0 0 1 4 4"/><path d="M12 2a10 10 0 0 1 10 10"/><circle cx="12" cy="12" r="2" fill="currentColor" stroke="none"/></svg>
            <div class="pvr-gps-stamp-text">GPS<br>VERIFIED</div>
          </div>
          <div class="pvr-gps-rows">
            <div class="pvr-gps-row">
              <span class="pvr-gps-row-key">Location pings on-site</span>
              <span class="pvr-gps-row-val pvr-gps-ping-count"><?php echo $pingCount; ?></span>
            </div>
            <?php if ($arrivalTime): ?>
            <div class="pvr-gps-row">
              <span class="pvr-gps-row-key">Crew arrived</span>
              <span class="pvr-gps-row-val"><?php echo htmlspecialchars($arrivalTime); ?></span>
            </div>
            <?php endif; ?>
            <?php if ($departTime && $departTime !== $arrivalTime): ?>
            <div class="pvr-gps-row">
              <span class="pvr-gps-row-key">Crew departed</span>
              <span class="pvr-gps-row-val"><?php echo htmlspecialchars($departTime); ?></span>
            </div>
            <?php endif; ?>
            <?php if (!empty($pings[0]['latitude']) && !empty($pings[0]['longitude'])): ?>
            <div class="pvr-gps-row">
              <span class="pvr-gps-row-key">Start coordinates</span>
              <span class="pvr-gps-row-val pvr-gps-coords"><?php
                $lat = (float)$pings[0]['latitude'];
                $lng = (float)$pings[0]['longitude'];
                echo number_format(abs($lat), 5) . '&deg;&nbsp;' . ($lat >= 0 ? 'N' : 'S') . '&ensp;'
                   . number_format(abs($lng), 5) . '&deg;&nbsp;' . ($lng <= 0 ? 'W' : 'E');
              ?></span>
            </div>
            <?php endif; ?>
            <?php if (!empty($visit['visit_number'])): ?>
            <div class="pvr-gps-row">
              <span class="pvr-gps-row-key">Visit ID</span>
              <span class="pvr-gps-row-val pvr-gps-visit-id"><?php echo htmlspecialchars($visit['visit_number']); ?></span>
            </div>
            <?php endif; ?>
          </div>
        </div>
        <!-- GPS ping coordinate log -->
        <div class="pvr-ping-log">
          <div class="pvr-ping-log-head">
            <span class="pvr-ping-log-col-num">#</span>
            <span class="pvr-ping-log-col-time">Time</span>
            <span class="pvr-ping-log-col-lat">Latitude</span>
            <span class="pvr-ping-log-col-lng">Longitude</span>
          </div>
          <?php foreach ($pings as $pi => $pg): ?>
          <div class="pvr-ping-log-row<?php echo $pi === 0 ? ' pvr-ping-first' : ($pi === $pingCount - 1 ? ' pvr-ping-last' : ''); ?>">
            <span class="pvr-ping-log-col-num"><?php echo $pi + 1; ?></span>
            <span class="pvr-ping-log-col-time"><?php echo date('g:i:s a', strtotime($pg['timestamp'])); ?></span>
            <span class="pvr-ping-log-col-lat"><?php echo number_format(abs((float)$pg['latitude']), 6); ?>&deg;&nbsp;<?php echo (float)$pg['latitude'] >= 0 ? 'N' : 'S'; ?></span>
            <span class="pvr-ping-log-col-lng"><?php echo number_format(abs((float)$pg['longitude']), 6); ?>&deg;&nbsp;<?php echo (float)$pg['longitude'] <= 0 ? 'W' : 'E'; ?></span>
          </div>
          <?php endforeach; ?>
        </div>
      <?php endif; ?>
    </div>

    <!-- 3. Site Photos Card ─────────────────────────────────────────────── -->
    <?php if (!empty($photos)): ?>
    <div class="pvr-section">
      <div class="pvr-section-title">
        <svg xmlns="http://www.w3.org/2000/svg" width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><rect x="3" y="3" width="18" height="18" rx="2"/><circle cx="8.5" cy="8.5" r="1.5"/><polyline points="21 15 16 10 5 21"/></svg>
        Site Photos
      </div>
      <div class="pvr-photo-grid">
        <?php foreach ($photos as $ph): ?>
          <?php
            $thumbSrc  = !empty($ph['thumb_path']) ? $ph['thumb_path'] : $ph['file_path'];
            $fullSrc   = $ph['file_path'];
            $caption   = $ph['caption'] ?: ucfirst(str_replace('_', ' ', $ph['photo_type'] ?? 'photo'));
            // Use EXIF captured_at if available, fall back to upload time
            $photoTs   = !empty($ph['captured_at']) ? $ph['captured_at'] : ($ph['uploaded_at'] ?? '');
            $photoDate = $photoTs ? date('M j, Y · g:i a', strtotime($photoTs)) : '';
          ?>
          <a href="<?php echo htmlspecialchars($fullSrc); ?>" target="_blank" class="pvr-photo-item">
            <img src="<?php echo htmlspecialchars($thumbSrc); ?>"
                 alt="<?php echo htmlspecialchars($caption); ?>"
                 loading="lazy">
            <div class="pvr-photo-caption">
              <?php echo htmlspecialchars($caption); ?>
              <?php if ($photoDate): ?>
                <span class="pvr-photo-ts"><?php echo htmlspecialchars($photoDate); ?></span>
              <?php endif; ?>
            </div>
          </a>
        <?php endforeach; ?>
      </div>
    </div>
    <?php endif; ?>

    <!-- 4. Products Applied + Aftercare Card ─────────────────────────────── -->
    <?php if (!empty($productLines)): ?>
    <div class="pvr-section">
      <div class="pvr-section-title">
        <svg xmlns="http://www.w3.org/2000/svg" width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M12 22s8-4 8-10V5l-8-3-8 3v7c0 6 8 10 8 10z"/></svg>
        Products Applied
      </div>
      <div class="pvr-product-list">
        <?php foreach ($productLines as $pl): ?>
          <div class="pvr-product-row">
            <div class="pvr-product-name"><?php echo htmlspecialchars($pl['product_name']); ?></div>
            <?php if (!empty($pl['quantity']) || !empty($pl['unit'])): ?>
              <div class="pvr-product-qty">
                <?php
                  $qtyParts = [];
                  if (!empty($pl['quantity'])) $qtyParts[] = htmlspecialchars($pl['quantity']);
                  if (!empty($pl['unit']))     $qtyParts[] = htmlspecialchars($pl['unit']);
                  echo implode(' ', $qtyParts);
                ?>
              </div>
            <?php endif; ?>
          </div>
          <?php if (!empty($pl['application_notes'])): ?>
            <div class="pvr-aftercare">
              <div class="pvr-aftercare-heading">
                <svg xmlns="http://www.w3.org/2000/svg" width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M11 20A7 7 0 0 1 9.8 6.1C15.5 5 17 4.48 19 2c1 2 2 4.18 2 8 0 5.5-4.78 10-10 10z"/><path d="M2 21c0-3 1.85-5.36 5.08-6C9.5 14.52 12 13 13 12"/></svg>
                Aftercare Instructions &mdash; <?php echo htmlspecialchars($pl['product_name']); ?>
              </div>
              <div class="pvr-aftercare-text"><?php echo nl2br(htmlspecialchars($pl['application_notes'])); ?></div>
            </div>
          <?php endif; ?>
        <?php endforeach; ?>
      </div>
    </div>
    <?php endif; ?>

    <!-- 5. Crew Notes Card ───────────────────────────────────────────────── -->
    <?php if (!empty($visibleNotes)): ?>
    <div class="pvr-section">
      <div class="pvr-section-title">
        <svg xmlns="http://www.w3.org/2000/svg" width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><polyline points="14 2 14 8 20 8"/><line x1="16" y1="13" x2="8" y2="13"/><line x1="16" y1="17" x2="8" y2="17"/></svg>
        Crew Notes
      </div>
      <?php foreach ($visibleNotes as $note): ?>
        <div class="pvr-note-item">
          <?php echo nl2br(htmlspecialchars($note['note'])); ?>
          <?php if (!empty($note['created_at'])): ?>
            <div class="pvr-note-date"><?php echo date('M j, Y g:i a', strtotime($note['created_at'])); ?></div>
          <?php endif; ?>
        </div>
      <?php endforeach; ?>
    </div>
    <?php endif; ?>

    <!-- 6. Program Progress Card (bundles only) ──────────────────────────── -->
    <?php if (!empty($bundleVisits) && $bundleTotal > 0): ?>
    <div class="pvr-section">
      <div class="pvr-section-title">
        <svg xmlns="http://www.w3.org/2000/svg" width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><polyline points="22 12 18 12 15 21 9 3 6 12 2 12"/></svg>
        Program Progress
      </div>
      <div class="pvr-progress-wrap">
        <div class="pvr-progress-dots">
          <?php foreach ($bundleVisits as $bv): ?>
            <?php
              $dotClass = 'pvr-dot-empty';
              if ($bv['status'] === 'completed') $dotClass = 'pvr-dot-done';
              elseif ($bv['id'] == $visit['id'])  $dotClass = 'pvr-dot-current';
            ?>
            <span class="pvr-dot <?php echo $dotClass; ?>" title="Visit <?php echo (int)($bv['sequence_index'] ?? 0) + 1; ?>"></span>
          <?php endforeach; ?>
        </div>
        <div class="pvr-progress-label">
          <?php echo $bundleCompleted; ?> of <?php echo $bundleTotal; ?> application<?php echo $bundleTotal !== 1 ? 's' : ''; ?> complete
        </div>
        <?php if ($bundleNextDate): ?>
          <div class="pvr-progress-next">Next scheduled: <?php echo htmlspecialchars($bundleNextDate); ?></div>
        <?php elseif ($bundleCompleted >= $bundleTotal): ?>
          <div class="pvr-progress-next pvr-progress-complete">All applications complete.</div>
        <?php endif; ?>
      </div>
    </div>
    <?php endif; ?>

    <!-- 7. Footer ────────────────────────────────────────────────────────── -->
    <div class="pvr-footer">
      <div class="pvr-footer-brand">Mowology</div>
      <div class="pvr-footer-contact">
        <a href="tel:7788469273">(778) 846-9273</a>
        &ensp;&middot;&ensp;
        <a href="mailto:tim@mowology.ca">tim@mowology.ca</a>
        &ensp;&middot;&ensp;
        <a href="https://mowology.ca" target="_blank">mowology.ca</a>
      </div>
      <div class="pvr-footer-generated">
        Report generated <?php echo htmlspecialchars($reportDate); ?> &mdash; This is a GPS-verified service record.
      </div>
    </div>

  </div><!-- /.pvr-doc-body -->
</div><!-- /.pvr-doc -->
</div><!-- /.pvr-page-wrap -->

<!-- 8. Print button (web only) -->
<button class="pvr-print-btn" onclick="window.print()" aria-label="Print or save as PDF">
  &#128438; Save / Print
</button>

<?php endif; ?>

<?php if ($pingCount > 0): ?>
<script src="https://unpkg.com/leaflet@1.9.4/dist/leaflet.js" crossorigin=""></script>
<script>
(function() {
  var el = document.getElementById('pvr-map');
  if (!el) return;
  var pings = JSON.parse(el.dataset.pings || '[]');
  if (!pings.length) return;

  var map = L.map(el, { zoomControl: true, scrollWheelZoom: false, attributionControl: false });

  L.tileLayer('https://server.arcgisonline.com/ArcGIS/rest/services/World_Street_Map/MapServer/tile/{z}/{y}/{x}', {
    maxZoom: 19,
  }).addTo(map);

  var latlngs = pings.map(function(p) { return [p[0], p[1]]; });

  if (latlngs.length > 1) {
    L.polyline(latlngs, { color: '#2D8659', weight: 3, opacity: 0.8 }).addTo(map);
  }

  pings.forEach(function(p, i) {
    L.circleMarker([p[0], p[1]], {
      radius:      i === 0 ? 8 : 4,
      fillColor:   i === 0 ? '#2D8659' : '#7FD858',
      color:       '#fff',
      weight:      2,
      fillOpacity: 1,
    }).addTo(map);
  });

  // Start label
  var arrival = el.dataset.arrival || '';
  if (arrival) {
    var icon = L.divIcon({
      className: 'portal-map-label-icon',
      html: '<div class="portal-map-start-bubble">Started ' + arrival + '</div>',
      iconAnchor: [-8, 6],
    });
    L.marker(latlngs[0], { icon: icon, interactive: false }).addTo(map);
  }

  // Always zoom 19 (house-number level), centre on centroid of all pings
  var sumLat = 0, sumLng = 0;
  latlngs.forEach(function(p) { sumLat += p[0]; sumLng += p[1]; });
  map.setView([sumLat / latlngs.length, sumLng / latlngs.length], 19);

  requestAnimationFrame(function() {
    setTimeout(function() { map.invalidateSize(true); }, 80);
  });
})();
</script>
<?php endif; ?>

</body>
</html>

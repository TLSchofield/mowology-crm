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
header('Cache-Control: no-store, private');
header('Pragma: no-cache');

require_once dirname(__DIR__) . '/app_config/config.php';
require_once APP_ROOT . '/Core/Csp.php';
MwCsp::send(); // nonce CSP for the customer portal (Fable #22, 2026-10-12)

$db = getDB();

$contact   = null;
$adminMode = false;
$canEditPins = false;
$error     = '';
$visitId   = isset($_GET['visit_id']) ? (int)$_GET['visit_id'] : 0;

if (!$visitId) {
    $error = 'No visit specified.';
}

// ── Mode 1: Admin preview via CRM session ──────────────────────────────────
if (!$error && isset($_GET['contact_id'])) {
    require_once dirname(__DIR__) . '/loginAuth/auth.php';
    requireLogin();
    requirePermission('billing.view');
    $adminMode = true;
    // Pin editing (drag / place / save) needs jobs.edit — the same key the save API checks.
    $canEditPins = function_exists('userHasPermission') && userHasPermission('jobs.edit');
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
    require_once APP_ROOT . '/Modules/Contacts/Services/PortalTokenService.php'; // 365-day portal links (audit #14)
    $stmt = $db->prepare("SELECT id, first_name, last_name, email, phone, portal_token FROM contacts WHERE portal_token = ?" . PortalTokenService::liveSql($db) . " LIMIT 1");
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
                p.latitude        AS property_lat,
                p.longitude       AS property_lng,
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

// ── What may this client see? (Settings → Client Portal) ──────────────────────
// Contract clients don't get the visit report or the visit length unless the office has
// switched them on. Staff previews always see everything.
$clientSees = ['report' => true, 'length' => true];
if (!$error && $visit && !$adminMode) {
    require_once APP_ROOT . '/Modules/Jobs/Services/ClientVisibilityService.php';
    $clientSees = (new ClientVisibilityService($db))->forVisit((int)$visit['id']);
    if (!$clientSees['report']) {
        $visit = null;
        $error = 'This service report isn\'t available online. Please contact us if you need details of this visit.';
    }
}

// ── Load photos ────────────────────────────────────────────────────────────
$photos = [];
if (!$error && $visit) {
    try {
        // Photos + where each was taken (gps_lat/gps_lng from the crew upload,
        // or a manager's manual correction). `pin` numbers the placed ones so
        // the gallery badge and the map marker agree.
        require_once APP_ROOT . '/Modules/Photos/Services/PhotoPositionService.php';
        $photos = (new PhotoPositionService($db))->listForVisit($visitId);
    } catch (Exception $e) {
        $photos = [];
    }
}

// ── Photo pins for the map ─────────────────────────────────────────────────
// Placed photos become numbered markers; unplaced ones are listed for a
// manager to drop onto the map in admin preview.
$photoPins     = [];
$unplacedPhotos = [];
// Media paths are server-generated ('/_media/…'), but they travel into href/src
// for the client, so only a root-relative path or https URL is accepted.
$pvrSafeUrl = static function ($u): string {
    $u = (string)$u;
    return (preg_match('#^(/(?!/)|https://)#i', $u) && !preg_match('/[\s"\'<>]/', $u)) ? $u : '';
};
foreach ($photos as $ph) {
    $ph['file_path']  = $pvrSafeUrl($ph['file_path'] ?? '');
    $ph['thumb_path'] = $pvrSafeUrl($ph['thumb_path'] ?? '');
    if ($ph['file_path'] === '') continue;
    $pinThumb = !empty($ph['thumb_path']) ? $ph['thumb_path'] : $ph['file_path'];
    $pinCap   = $ph['caption'] ?: ucfirst(str_replace('_', ' ', $ph['photo_type'] ?? 'photo'));
    $pinTs    = !empty($ph['captured_at']) ? $ph['captured_at'] : ($ph['uploaded_at'] ?? '');
    $entry = [
        'id'      => (int)$ph['id'],
        'n'       => $ph['pin'],
        'thumb'   => $pinThumb,
        'full'    => $ph['file_path'],
        'caption' => $pinCap,
        'time'    => $pinTs ? date('g:i a', strtotime($pinTs)) : '',
        'source'  => $ph['gps_source'] ?? 'none',
    ];
    if (!empty($ph['pin'])) {
        $entry['lat'] = (float)$ph['gps_lat'];
        $entry['lng'] = (float)$ph['gps_lng'];
        $photoPins[] = $entry;
    } else {
        $unplacedPhotos[] = $entry;
    }
}

// ── Load GPS pings ─────────────────────────────────────────────────────────
// Only fixes ON the property (border or radius), never mock locations: a running timer stamps
// the fuel run and the lunch stop with the visit too (RouteProofService).
$pings = [];
if (!$error && $visit) {
    try {
        require_once APP_ROOT . '/Modules/Tracking/Services/RouteProofService.php';
        $pings = (new RouteProofService($db))->forVisits([$visitId])[$visitId] ?? [];
    } catch (Throwable $e) {
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
            SELECT content AS note, created_at
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

// ── Plant care this visit (PlantWorkService, migration 1436) and why a pruning plan cut what it cut ──
$plantLines = [];
$pruneWhy   = [];
if (!$error && $visit) {
    try {
        require_once APP_ROOT . '/Modules/Plants/Services/PlantWorkService.php';
        $plantLines = (new PlantWorkService($db))->forVisit((int)$visitId);
        $planIds = [];
        foreach ($plantLines as $pl) {
            if (preg_match('/^plan:(\d+):/', $pl['key'], $m)) $planIds[(int)$m[1]] = true;
        }
        $prunePlans = new PruningPlanService($db);
        foreach (array_keys($planIds) as $planId) {
            $pp = $prunePlans->get($planId);
            if ($pp && in_array($pp['status'], ['approved', 'scheduled', 'done'], true) && !empty($pp['plan']['client_summary'])) {
                $pruneWhy[] = ['plant' => $pp['common_name'] ?: $pp['scientific_name'], 'summary' => (string)$pp['plan']['client_summary']];
            }
        }
    } catch (Throwable $e) {
        $plantLines = [];
        $pruneWhy   = [];
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

    // Visit length is never shown on this report (Tim, 2026-11-02: no duration, no crew
    // arrived/left times, no per-ping clock times — the route and the photos are the proof).
    // The per-client 'length' setting still gates other places; here it is always off.
    $clientSees['length'] = false;
    if (!$clientSees['length']) {
        $durationStr = '';
        $arrivalTime = '';
        $departTime  = '';
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
  <link rel="stylesheet" href="/customer/visit-report.css?v=20261104p">
  <?php $hasMap = ($pingCount > 0) || !empty($photoPins) || ($canEditPins && !empty($unplacedPhotos) && !empty($visit['property_lat']) && !empty($visit['property_lng'])); ?>
  <?php if ($hasMap): ?>
  <link rel="stylesheet" href="/assets/vendor/leaflet/leaflet.min.css">
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
        <?php echo $pingCount > 0 ? 'GPS Verified Service Route' : 'Where We Worked'; ?>
        <?php if (!empty($photoPins)): ?>
          <span class="pvr-gps-title-note"><?php echo count($photoPins); ?> photo <?php echo count($photoPins) === 1 ? 'location' : 'locations'; ?> marked</span>
        <?php endif; ?>
      </div>
      <?php if ($hasMap): ?>
        <?php
          $pingLatLngs = json_encode(array_map(
              fn($p) => [(float)$p['latitude'], (float)$p['longitude']],
              $pings
          ));
        ?>
        <div id="pvr-map"
             class="pvr-gps-img<?php echo !empty($photoPins) ? ' pvr-gps-img-tall' : ''; ?>"
             data-pings="<?php echo htmlspecialchars($pingLatLngs, ENT_QUOTES); ?>"
             data-photo-pins="<?php echo htmlspecialchars(json_encode($photoPins), ENT_QUOTES); ?>"
             data-arrival="<?php echo htmlspecialchars($arrivalTime ?? ''); ?>"
             <?php if (!empty($visit['property_lat']) && !empty($visit['property_lng'])): ?>
             data-property-lat="<?php echo htmlspecialchars((string)$visit['property_lat']); ?>"
             data-property-lng="<?php echo htmlspecialchars((string)$visit['property_lng']); ?>"
             <?php endif; ?>
             <?php if ($canEditPins): ?>
             data-admin="1"
             data-visit-id="<?php echo (int)$visitId; ?>"
             data-save-url="/crm/api/photo-position.php"
             data-csrf="<?php echo htmlspecialchars(generateCSRFToken()); ?>"
             data-unplaced="<?php echo htmlspecialchars(json_encode($unplacedPhotos), ENT_QUOTES); ?>"
             <?php endif; ?>>
        </div>
        <div class="pvr-map-bar">
          <div class="pvr-map-layers" role="group" aria-label="Map style">
            <button type="button" class="pvr-map-layer-btn" data-layer="satellite">Satellite</button>
            <button type="button" class="pvr-map-layer-btn" data-layer="street">Street</button>
          </div>
          <?php if (!empty($photoPins)): ?>
          <div class="pvr-map-hint">Numbered pins match the photos below. Tap a pin to see the photo.</div>
          <?php endif; ?>
        </div>
        <?php if ($canEditPins): ?>
        <!-- Admin preview only: nudge pins to the right tree, place photos that arrived without a fix -->
        <div class="pvr-pin-admin" id="pvr-pin-admin">
          <div class="pvr-pin-admin-row">
            <span class="pvr-pin-admin-label">Pin editor (not shown to the client)</span>
            <span class="pvr-pin-admin-status" id="pvr-pin-status">Drag a pin to correct it.</span>
            <button type="button" class="pvr-pin-save" id="pvr-pin-save" disabled>Save pin positions</button>
          </div>
          <?php if (!empty($unplacedPhotos)): ?>
          <div class="pvr-pin-admin-row pvr-pin-unplaced" id="pvr-pin-unplaced">
            <span class="pvr-pin-admin-label">Not on the map yet — tap a photo, then tap the map:</span>
            <?php foreach ($unplacedPhotos as $up): ?>
              <button type="button" class="pvr-pin-chip" data-media-id="<?php echo (int)$up['id']; ?>">
                <img src="<?php echo htmlspecialchars($up['thumb']); ?>" alt="">
                <span><?php echo htmlspecialchars($up['caption']); ?></span>
              </button>
            <?php endforeach; ?>
          </div>
          <?php endif; ?>
        </div>
        <?php endif; ?>
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
        <!-- GPS ping coordinate log: collapsed by default, no clock times -->
        <details class="pvr-ping-details">
          <summary class="pvr-ping-summary">GPS location log &middot; <?php echo $pingCount; ?> pings</summary>
        <div class="pvr-ping-log">
          <div class="pvr-ping-log-head">
            <span class="pvr-ping-log-col-num">#</span>
            <span class="pvr-ping-log-col-lat">Latitude</span>
            <span class="pvr-ping-log-col-lng">Longitude</span>
          </div>
          <?php foreach ($pings as $pi => $pg): ?>
          <div class="pvr-ping-log-row<?php echo $pi === 0 ? ' pvr-ping-first' : ($pi === $pingCount - 1 ? ' pvr-ping-last' : ''); ?>">
            <span class="pvr-ping-log-col-num"><?php echo $pi + 1; ?></span>
            <span class="pvr-ping-log-col-lat"><?php echo number_format(abs((float)$pg['latitude']), 6); ?>&deg;&nbsp;<?php echo (float)$pg['latitude'] >= 0 ? 'N' : 'S'; ?></span>
            <span class="pvr-ping-log-col-lng"><?php echo number_format(abs((float)$pg['longitude']), 6); ?>&deg;&nbsp;<?php echo (float)$pg['longitude'] <= 0 ? 'W' : 'E'; ?></span>
          </div>
          <?php endforeach; ?>
        </div>
        </details>
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
            $fullSrc   = $pvrSafeUrl($ph['file_path'] ?? '');
            if ($fullSrc === '') continue;
            $thumbSrc  = $pvrSafeUrl($ph['thumb_path'] ?? '') ?: $fullSrc;
            $caption   = $ph['caption'] ?: ucfirst(str_replace('_', ' ', $ph['photo_type'] ?? 'photo'));
            // Use EXIF captured_at if available, fall back to upload time
            $photoTs   = !empty($ph['captured_at']) ? $ph['captured_at'] : ($ph['uploaded_at'] ?? '');
            $photoDate = $photoTs ? date('M j, Y · g:i a', strtotime($photoTs)) : '';
          ?>
          <a href="<?php echo htmlspecialchars($fullSrc); ?>" target="_blank"
             class="pvr-photo-item<?php echo !empty($ph['pin']) ? ' pvr-photo-pinned' : ''; ?>"
             data-media-id="<?php echo (int)$ph['id']; ?>"
             <?php if (!empty($ph['pin'])): ?>data-pin="<?php echo (int)$ph['pin']; ?>" title="Show on map"<?php endif; ?>>
            <?php if (!empty($ph['pin'])): ?>
              <span class="pvr-photo-num" aria-label="Map pin <?php echo (int)$ph['pin']; ?>"><?php echo (int)$ph['pin']; ?></span>
            <?php endif; ?>
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

    <!-- 4b. Plant Care Card (checklist lines tied to the property's plants) ─ -->
    <?php if (!empty($plantLines)): ?>
    <div class="pvr-section">
      <div class="pvr-section-title">
        <svg xmlns="http://www.w3.org/2000/svg" width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M11 20A7 7 0 0 1 9.8 6.1C15.5 5 17 4.48 19 2c1 2 2 4.18 2 8 0 5.5-4.78 10-10 10z"/><path d="M2 21c0-3 1.85-5.36 5.08-6C9.5 14.52 12 13 13 12"/></svg>
        Plant Care This Visit
      </div>
      <?php foreach ($plantLines as $pl): ?>
        <div class="pvr-note-item pvr-plant-line<?php echo $pl['done'] ? '' : ' pvr-plant-open'; ?>">
          <span class="pvr-plant-tick" aria-hidden="true"><?php echo $pl['done'] ? '&#10003;' : '&#9675;'; ?></span>
          <?php echo htmlspecialchars($pl['task']); ?><?php echo $pl['done'] ? '' : ' <span class="pvr-note-date">(not done this visit)</span>'; ?>
          <?php if ($pl['note'] !== ''): ?>
            <div class="pvr-note-date"><?php echo htmlspecialchars($pl['note']); ?></div>
          <?php endif; ?>
        </div>
      <?php endforeach; ?>
      <?php foreach ($pruneWhy as $pw): ?>
        <div class="pvr-aftercare">
          <div class="pvr-aftercare-heading">Why we pruned the <?php echo htmlspecialchars(mb_strtolower($pw['plant'])); ?> this way</div>
          <div class="pvr-aftercare-text"><?php echo nl2br(htmlspecialchars($pw['summary'])); ?></div>
        </div>
      <?php endforeach; ?>
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
<button class="pvr-print-btn" data-mw-print aria-label="Print or save as PDF">
  &#128438; Save / Print
</button>

<?php endif; ?>

<?php if (!empty($hasMap)): ?>
<script src="/assets/vendor/leaflet/leaflet.min.js"></script>
<script<?= MwCsp::attr() ?>>
(function() {
  var el = document.getElementById('pvr-map');
  if (!el) return;
  var pings   = JSON.parse(el.dataset.pings || '[]');
  var pins    = JSON.parse(el.dataset.photoPins || '[]');
  var isAdmin = el.dataset.admin === '1';
  if (!pings.length && !pins.length && !isAdmin) return;

  var map = L.map(el, { zoomControl: true, scrollWheelZoom: false, attributionControl: false });

  // Two base layers from the same keyless host (already in the portal CSP).
  // Satellite is the default whenever photos are pinned: the point of the map
  // is to show which tree, bed or wall the photo is of, and a street map
  // shows none of those.
  var ARC = 'https://server.arcgisonline.com/ArcGIS/rest/services/';
  var layers = {
    street:    L.tileLayer(ARC + 'World_Street_Map/MapServer/tile/{z}/{y}/{x}', { maxZoom: 19 }),
    satellite: L.tileLayer(ARC + 'World_Imagery/MapServer/tile/{z}/{y}/{x}',    { maxZoom: 19 })
  };
  var activeLayer = pins.length ? 'satellite' : 'street';
  layers[activeLayer].addTo(map);

  function setLayer(name) {
    if (!layers[name] || name === activeLayer) return;
    map.removeLayer(layers[activeLayer]);
    layers[name].addTo(map);
    activeLayer = name;
    syncLayerButtons();
  }
  function syncLayerButtons() {
    document.querySelectorAll('.pvr-map-layer-btn').forEach(function(b) {
      b.classList.toggle('is-active', b.dataset.layer === activeLayer);
    });
  }
  document.querySelectorAll('.pvr-map-layer-btn').forEach(function(b) {
    b.addEventListener('click', function() { setLayer(b.dataset.layer); });
  });
  syncLayerButtons();

  // ── Crew route ───────────────────────────────────────────────────────────
  // A long visit can log thousands of pings (2135 on 2026-11-02). Drawn at full
  // weight they bury the photo pins, so: at most ~250 dots (evenly sampled, start
  // and end always kept), and when photo pins exist the dots and line step back.
  var latlngs = pings.map(function(p) { return [p[0], p[1]]; });
  var quiet   = pins.length > 0;

  if (latlngs.length > 1) {
    L.polyline(latlngs, { color: '#2D8659', weight: quiet ? 2 : 3, opacity: quiet ? 0.35 : 0.8 }).addTo(map);
  }

  var MAX_DOTS = 250;
  var step = Math.max(1, Math.ceil(pings.length / MAX_DOTS));
  pings.forEach(function(p, i) {
    var isEnd = i === 0 || i === pings.length - 1;
    if (!isEnd && i % step !== 0) return;
    L.circleMarker([p[0], p[1]], {
      radius:      i === 0 ? 8 : (quiet ? 2.5 : 4),
      fillColor:   i === 0 ? '#2D8659' : '#7FD858',
      color:       '#fff',
      weight:      i === 0 ? 2 : (quiet ? 1 : 2),
      fillOpacity: quiet && i !== 0 ? 0.55 : 1,
      interactive: false,
    }).addTo(map);
  });

  // Start label
  var arrival = el.dataset.arrival || '';
  if (arrival && latlngs.length) {
    var icon = L.divIcon({
      className: 'portal-map-label-icon',
      html: '<div class="portal-map-start-bubble">Started ' + arrival + '</div>',
      iconAnchor: [-8, 6],
    });
    L.marker(latlngs[0], { icon: icon, interactive: false }).addTo(map);
  }

  // ── Photo pins ───────────────────────────────────────────────────────────
  var markers = {};          // media id → L.marker
  var dirty   = {};          // media id → {lat, lng} awaiting save

  function esc(s) {
    return String(s == null ? '' : s).replace(/[&<>"']/g, function(c) {
      return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c];
    });
  }
  function pinIcon(n) {
    return L.divIcon({
      className: 'pvr-pin-wrap',
      html: '<div class="pvr-pin"><span>' + n + '</span></div>',
      iconSize:   [30, 38],
      iconAnchor: [15, 36],
      popupAnchor: [0, -34],
    });
  }
  function popupHtml(p) {
    return '<a class="pvr-pin-popup" href="' + esc(p.full) + '" target="_blank">'
         + '<img src="' + esc(p.thumb) + '" alt="">'
         + '<div class="pvr-pin-popup-cap"><strong>' + esc(p.n) + '.</strong> ' + esc(p.caption)
         + (p.time ? ' <span>' + esc(p.time) + '</span>' : '') + '</div></a>';
  }
  function addPin(p) {
    var m = L.marker([p.lat, p.lng], { icon: pinIcon(p.n), draggable: isAdmin, title: p.caption, zIndexOffset: 1000 }).addTo(map);
    m.bindPopup(popupHtml(p), { maxWidth: 220, closeButton: false });
    if (isAdmin) {
      m.on('dragend', function() {
        var ll = m.getLatLng();
        dirty[p.id] = { lat: ll.lat, lng: ll.lng };
        markDirty();
      });
    }
    markers[p.id] = m;
    return m;
  }
  pins.forEach(addPin);

  // Gallery → map: tap a numbered photo to see its pin
  document.querySelectorAll('.pvr-photo-item[data-pin]').forEach(function(a) {
    a.addEventListener('click', function(ev) {
      var m = markers[a.dataset.mediaId];
      if (!m) return;
      ev.preventDefault();
      el.scrollIntoView({ behavior: 'smooth', block: 'center' });
      map.setView(m.getLatLng(), Math.max(map.getZoom(), 18));
      m.openPopup();
    });
  });

  // ── Framing ──────────────────────────────────────────────────────────────
  var propertyLL = (el.dataset.propertyLat && el.dataset.propertyLng)
    ? [parseFloat(el.dataset.propertyLat), parseFloat(el.dataset.propertyLng)] : null;

  function frame() {
    if (pins.length) {
      // Photos are the subject: fit them (plus the property point so the
      // building stays in frame), never past house-number zoom.
      var b = L.latLngBounds(pins.map(function(p) { return [p.lat, p.lng]; }));
      if (propertyLL) b.extend(propertyLL);
      map.fitBounds(b, { padding: [36, 36], maxZoom: 19 });
      return;
    }
    // No pins: original behaviour. Centre on the geocoded property when we
    // have it — GPS pings drift and cluster unevenly (extra stationary pings
    // near the parking/start spot) and shove the real property off-centre.
    var center;
    if (propertyLL) {
      center = propertyLL;
    } else if (latlngs.length) {
      var lats = latlngs.map(function(p) { return p[0]; });
      var lngs = latlngs.map(function(p) { return p[1]; });
      center = [
        (Math.min.apply(null, lats) + Math.max.apply(null, lats)) / 2,
        (Math.min.apply(null, lngs) + Math.max.apply(null, lngs)) / 2
      ];
    } else {
      return;
    }
    map.setView(center, 19);
  }
  frame();

  // The container's real size isn't settled yet (still mid-reflow), so the
  // first framing is computed against a stale size. Re-frame after
  // invalidateSize() picks up the real one.
  requestAnimationFrame(function() {
    setTimeout(function() {
      map.invalidateSize(true);
      frame();
    }, 80);
  });

  // ── Admin preview: drag to fix, tap to place, save ───────────────────────
  if (!isAdmin) return;

  var saveBtn   = document.getElementById('pvr-pin-save');
  var statusEl  = document.getElementById('pvr-pin-status');
  var unplaced  = JSON.parse(el.dataset.unplaced || '[]');
  var placing   = null;   // media id waiting for a map tap
  var nextN     = pins.length;

  function setStatus(msg) { if (statusEl) statusEl.textContent = msg; }
  function markDirty() {
    var n = Object.keys(dirty).length;
    if (saveBtn) saveBtn.disabled = n === 0;
    setStatus(n ? (n + (n === 1 ? ' pin moved' : ' pins moved') + ' — not saved yet') : 'Drag a pin to correct it.');
  }

  document.querySelectorAll('.pvr-pin-chip').forEach(function(chip) {
    chip.addEventListener('click', function() {
      var id = chip.dataset.mediaId;
      var wasActive = chip.classList.contains('is-active');
      document.querySelectorAll('.pvr-pin-chip').forEach(function(c) { c.classList.remove('is-active'); });
      if (wasActive) { placing = null; el.classList.remove('pvr-map-placing'); markDirty(); return; }
      placing = id;
      chip.classList.add('is-active');
      el.classList.add('pvr-map-placing');
      setStatus('Now tap the map where this photo was taken.');
      el.scrollIntoView({ behavior: 'smooth', block: 'center' });
    });
  });

  map.on('click', function(ev) {
    if (!placing) return;
    var p = null;
    for (var i = 0; i < unplaced.length; i++) if (String(unplaced[i].id) === String(placing)) p = unplaced[i];
    if (!p) return;
    p.lat = ev.latlng.lat; p.lng = ev.latlng.lng; p.n = ++nextN;
    pins.push(p);
    addPin(p).openPopup();
    dirty[p.id] = { lat: p.lat, lng: p.lng };
    var chip = document.querySelector('.pvr-pin-chip[data-media-id="' + p.id + '"]');
    if (chip) chip.remove();
    var tile = document.querySelector('.pvr-photo-item[data-media-id="' + p.id + '"]');
    if (tile && !tile.querySelector('.pvr-photo-num')) {
      var badge = document.createElement('span');
      badge.className = 'pvr-photo-num'; badge.textContent = p.n;
      tile.insertBefore(badge, tile.firstChild);
      tile.dataset.pin = p.n; tile.classList.add('pvr-photo-pinned');
    }
    placing = null;
    el.classList.remove('pvr-map-placing');
    var box = document.getElementById('pvr-pin-unplaced');
    if (box && !box.querySelector('.pvr-pin-chip')) box.remove();
    markDirty();
  });

  if (saveBtn) saveBtn.addEventListener('click', function() {
    var positions = Object.keys(dirty).map(function(id) {
      return { media_id: parseInt(id, 10), lat: dirty[id].lat, lng: dirty[id].lng };
    });
    if (!positions.length) return;
    saveBtn.disabled = true;
    setStatus('Saving…');
    fetch(el.dataset.saveUrl, {
      method: 'POST',
      headers: { 'Content-Type': 'application/json' },
      credentials: 'same-origin',
      body: JSON.stringify({
        visit_id: parseInt(el.dataset.visitId, 10),
        positions: positions,
        csrf_token: el.dataset.csrf
      })
    }).then(function(r) { return r.json().catch(function() { return null; }).then(function(j) { return { ok: r.ok, j: j }; }); })
      .then(function(res) {
        if (!res.ok || !res.j || !res.j.success) throw new Error((res.j && res.j.error) || 'Save failed');
        var rejected = (res.j.rejected || []).map(String);
        Object.keys(dirty).forEach(function(id) { if (rejected.indexOf(String(id)) === -1) delete dirty[id]; });
        var msg = 'Saved ' + res.j.saved + (res.j.saved === 1 ? ' pin' : ' pins') + '. The client now sees these positions.';
        if (rejected.length) msg += ' ' + rejected.length + (rejected.length === 1 ? ' pin was' : ' pins were') + ' refused and still need saving.';
        setStatus(msg);
        saveBtn.disabled = Object.keys(dirty).length === 0;
      })
      .catch(function(err) {
        setStatus('Could not save (' + err.message + '). Try again.');
        saveBtn.disabled = false;
      });
  });
})();
</script>
<?php endif; ?>

<script src="/assets/js/mw-csp-actions.js"></script>
</body>
</html>

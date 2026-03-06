<?php
/**
 * Conflict Resolution Page — Investigate & fix route reconciliation anomalies
 *
 * Shows GPS ping map, photo evidence, property config, and resolution actions
 * for visits where truck GPS conflicts with clock-in records.
 *
 * GET ?visit_id=X&date=YYYY-MM-DD&truck_id=Y
 * Auth: admin/manager only
 */
require_once dirname(__DIR__) . '/../loginAuth/auth.php';
require_once dirname(__DIR__) . '/includes/functions.php';
require_once dirname(__DIR__) . '/includes/timeclock-functions.php';
require_once dirname(__DIR__) . '/includes/plan-functions.php';

requireLogin();
$user = getCurrentUser();

if (!in_array($user['role'], ['admin', 'manager'])) {
    header('Location: /crm/jobs/schedule.php');
    exit;
}

$db = getDB();
$tz = new DateTimeZone('America/Vancouver');
$csrfToken = generateCSRFToken();

// --- Parse params ---
$visitId = isset($_GET['visit_id']) ? (int)$_GET['visit_id'] : 0;
$truckId = isset($_GET['truck_id']) ? (int)$_GET['truck_id'] : 0;
$date    = $_GET['date'] ?? (new DateTime('now', $tz))->format('Y-m-d');

if (!$visitId) {
    header('Location: schedule.php');
    exit;
}

if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) {
    $date = (new DateTime('now', $tz))->format('Y-m-d');
}

// --- Load visit + plan + property ---
$visitStmt = $db->prepare("
    SELECT
        jv.id AS visit_id,
        jv.visit_number,
        jv.scheduled_date,
        jv.scheduled_time_start,
        jv.scheduled_time_end,
        jv.status,
        jv.started_at,
        jv.completed_at,
        jv.assigned_crew_id,
        jv.proof_complete,
        jp.id AS plan_id,
        jp.plan_number,
        jp.title AS plan_title,
        jp.service_type,
        jp.property_id,
        p.address AS property_address,
        p.city AS property_city,
        p.latitude AS prop_lat,
        p.longitude AS prop_lng,
        u.full_name AS crew_name
    FROM job_visits jv
    JOIN job_plans jp ON jv.plan_id = jp.id
    LEFT JOIN properties p ON jp.property_id = p.id
    LEFT JOIN users u ON jv.assigned_crew_id = u.id
    WHERE jv.id = ?
");
$visitStmt->execute([$visitId]);
$visit = $visitStmt->fetch(PDO::FETCH_ASSOC);

if (!$visit) {
    header('Location: schedule.php');
    exit;
}

// --- Load truck info ---
$truck = null;
if ($truckId) {
    $truckStmt = $db->prepare("SELECT id, full_name FROM users WHERE id = ? AND device_type = 'truck'");
    $truckStmt->execute([$truckId]);
    $truck = $truckStmt->fetch(PDO::FETCH_ASSOC);
}

// --- Load truck GPS pings for the date (within ~500m of property for context) ---
$propLat = (float)$visit['prop_lat'];
$propLng = (float)$visit['prop_lng'];
$truckPings = [];

if ($truckId && $propLat && $propLng) {
    $dayStart = (new DateTime($date . ' 00:00:00', $tz))->getTimestamp();
    $dayEnd   = (new DateTime($date . ' 23:59:59', $tz))->getTimestamp();

    $pingStmt = $db->prepare("
        SELECT
            clh.latitude AS lat,
            clh.longitude AS lng,
            UNIX_TIMESTAMP(clh.timestamp) AS epoch
        FROM crew_location_history clh
        WHERE clh.crew_id = ?
          AND UNIX_TIMESTAMP(clh.timestamp) >= ?
          AND UNIX_TIMESTAMP(clh.timestamp) <= ?
        ORDER BY clh.timestamp ASC
    ");
    $pingStmt->execute([$truckId, $dayStart, $dayEnd]);
    $allPings = $pingStmt->fetchAll(PDO::FETCH_ASSOC);

    // Filter to pings within ~800m for map display, but keep all for trail
    foreach ($allPings as $p) {
        $dist = haversineDistance($propLat, $propLng, (float)$p['lat'], (float)$p['lng']);
        $truckPings[] = [
            'lat'    => (float)$p['lat'],
            'lng'    => (float)$p['lng'],
            'epoch'  => (int)$p['epoch'],
            'time'   => (new DateTime('@' . $p['epoch']))->setTimezone($tz)->format('g:i a'),
            'dist_m' => (int)round($dist),
            'nearby' => $dist <= 150,
        ];
    }
}

// --- Load crew GPS pings for the date ---
$crewPings = [];
$crewPingUserId = $visit['assigned_crew_id'] ? (int)$visit['assigned_crew_id'] : 0;
if ($crewPingUserId && $propLat && $propLng) {
    if (!isset($dayStart)) {
        $dayStart = (new DateTime($date . ' 00:00:00', $tz))->getTimestamp();
        $dayEnd   = (new DateTime($date . ' 23:59:59', $tz))->getTimestamp();
    }
    $crewPingStmt = $db->prepare("
        SELECT clh.latitude AS lat, clh.longitude AS lng, UNIX_TIMESTAMP(clh.timestamp) AS epoch
        FROM crew_location_history clh
        WHERE clh.crew_id = ?
          AND UNIX_TIMESTAMP(clh.timestamp) >= ?
          AND UNIX_TIMESTAMP(clh.timestamp) <= ?
        ORDER BY clh.timestamp ASC
    ");
    $crewPingStmt->execute([$crewPingUserId, $dayStart, $dayEnd]);
    foreach ($crewPingStmt->fetchAll(PDO::FETCH_ASSOC) as $cp) {
        $dist = haversineDistance($propLat, $propLng, (float)$cp['lat'], (float)$cp['lng']);
        $crewPings[] = [
            'lat'    => (float)$cp['lat'],
            'lng'    => (float)$cp['lng'],
            'epoch'  => (int)$cp['epoch'],
            'time'   => (new DateTime('@' . $cp['epoch']))->setTimezone($tz)->format('g:i a'),
            'dist_m' => (int)round($dist),
            'nearby' => $dist <= 150,
        ];
    }
}
$crewNearbyCount = count(array_filter($crewPings, fn($p) => $p['nearby']));

// --- Compute dwell stats from nearby pings ---
$nearbyPings = array_filter($truckPings, fn($p) => $p['nearby']);
$dwellMinutes = 0;
$firstSeen = null;
$lastSeen = null;
if (count($nearbyPings) >= 2) {
    $epochs = array_column($nearbyPings, 'epoch');
    $firstSeen = min($epochs);
    $lastSeen  = max($epochs);
    $dwellMinutes = (int)round(($lastSeen - $firstSeen) / 60);
}

// --- Load geofence arrival border (if exists) ---
$arrivalBorder = null;
$propertyId = (int)$visit['property_id'];
if ($propertyId) {
    $geoModelPath = dirname(dirname(__DIR__)) . '/app/Modules/Geofence/Models/GeofenceModel.php';
    if (is_file($geoModelPath)) {
        require_once $geoModelPath;
        if (function_exists('geofenceGetArrivalBorder')) {
            $arrivalBorder = geofenceGetArrivalBorder($propertyId);
        }
    }
}

// --- Load auto clock-in configuration ---
$trackingConfig = [
    'auto_clock_in' => false,
    'source' => ['auto_clock_in' => 'default'],
];
if (function_exists('resolveTrackingRequirementsForPlan') && $visit['plan_id']) {
    $trackingConfig = resolveTrackingRequirementsForPlan((int)$visit['plan_id']);
}

// --- Load existing time entries for this visit ---
$timeStmt = $db->prepare("
    SELECT id, user_id, start_time, end_time, duration_minutes, auto_started, status, notes
    FROM job_time_entries
    WHERE visit_id = ?
    ORDER BY start_time ASC
");
$timeStmt->execute([$visitId]);
$timeEntries = $timeStmt->fetchAll(PDO::FETCH_ASSOC);

// --- Load visit photos ---
$photoStmt = $db->prepare("
    SELECT vp.id, vp.photo_type, vp.filename, vp.caption,
           vp.thumb_path, vp.grid_path, vp.view_path,
           vp.uploaded_at, u.full_name AS uploader
    FROM visit_photos vp
    LEFT JOIN users u ON vp.uploaded_by = u.id
    WHERE vp.visit_id = ? AND vp.deleted_at IS NULL
    ORDER BY vp.sort_order ASC, vp.uploaded_at DESC
");
$photoStmt->execute([$visitId]);
$photos = $photoStmt->fetchAll(PDO::FETCH_ASSOC);

// --- Load crew members assigned to stop on this date ---
$crewStmt = $db->prepare("
    SELECT DISTINCT u.id, u.full_name
    FROM calendar_stops cs
    JOIN calendar_stop_crew csc ON csc.stop_id = cs.id
    JOIN users u ON u.id = csc.user_id
    WHERE cs.stop_date = ?
      AND cs.property_id = ?
      AND u.device_type IS NULL OR u.device_type != 'truck'
");
$crewStmt->execute([$date, $propertyId]);
$assignedCrew = $crewStmt->fetchAll(PDO::FETCH_ASSOC);
// Fallback: if no crew from stops, use the visit's assigned crew
if (empty($assignedCrew) && $visit['assigned_crew_id']) {
    $assignedCrew = [['id' => (int)$visit['assigned_crew_id'], 'full_name' => $visit['crew_name']]];
}

// --- Check for existing resolution ---
$resStmt = $db->prepare("
    SELECT id, resolution_type, notes, created_at
    FROM conflict_resolutions
    WHERE visit_id = ? AND visit_date = ?
    ORDER BY created_at DESC LIMIT 1
");
$resStmt->execute([$visitId, $date]);
$existingResolution = $resStmt->fetch(PDO::FETCH_ASSOC);

// --- Determine conflict type ---
$conflictType = 'unknown';
if ($visit['status'] === 'scheduled' && count($nearbyPings) >= 2 && $dwellMinutes >= 3) {
    $conflictType = 'truck_at_site_no_clockin';
} elseif ($visit['status'] === 'completed' && count($nearbyPings) < 2) {
    $conflictType = 'clockin_no_truck';
}

// --- Page setup ---
$pageTitle = 'Conflict Resolution — ' . htmlspecialchars($visit['visit_number']);
$activePage = 'schedule';
$extraHead = '<link rel="stylesheet" href="/crm/js/leaflet/leaflet.min.css">'
           . '<script src="/crm/js/leaflet/leaflet.min.js"></script>';
?>
<?php include dirname(__DIR__) . '/includes/appstack_head.php'; ?>

<!-- Page Header -->
<div class="d-flex justify-content-between align-items-center mb-3">
    <div>
        <a href="schedule.php" class="text-muted small d-block mb-1">&larr; Back to Schedule</a>
        <h1 class="h3 mb-0">
            Conflict Resolution
            <?php if ($conflictType === 'truck_at_site_no_clockin'): ?>
                <span class="badge badge-warning ml-2" style="font-size:0.5em; vertical-align:middle;">No Clock-In</span>
            <?php elseif ($conflictType === 'clockin_no_truck'): ?>
                <span class="badge badge-info ml-2" style="font-size:0.5em; vertical-align:middle;">No Truck GPS</span>
            <?php endif; ?>
        </h1>
        <p class="text-muted mb-0">
            <?= htmlspecialchars($visit['visit_number']) ?> &middot;
            <?= htmlspecialchars($visit['property_address'] ?? '') ?>, <?= htmlspecialchars($visit['property_city'] ?? '') ?>
            &middot; <?= date('D M j, Y', strtotime($date)) ?>
        </p>
    </div>
    <?php if ($existingResolution): ?>
        <span class="badge badge-success" style="font-size:0.85em;">
            Resolved: <?= ucfirst(str_replace('_', ' ', $existingResolution['resolution_type'])) ?>
        </span>
    <?php endif; ?>
</div>

<div class="row">
    <!-- Left: Map -->
    <div class="col-lg-8 mb-4">
        <div class="card">
            <div class="card-header">
                <h5 class="card-title mb-0">
                    <i data-feather="map-pin" class="mr-1"></i>
                    Truck GPS Trail
                    <?php if ($truck): ?>
                        <span class="text-muted ml-2" style="font-size:0.8em;"><?= htmlspecialchars($truck['full_name']) ?></span>
                    <?php endif; ?>
                </h5>
            </div>
            <div class="card-body p-0">
                <div id="crMap" class="mw-cr-map" style="height:450px;"></div>
            </div>
            <div class="card-footer small text-muted">
                <span class="mr-3"><span style="display:inline-block;width:10px;height:10px;border-radius:50%;background:var(--mw-green);"></span> Truck on-site</span>
                <span class="mr-3"><span style="display:inline-block;width:10px;height:10px;border-radius:50%;background:#94a3b8;"></span> Truck traveling</span>
                <span class="mr-3"><span style="display:inline-block;width:10px;height:10px;border-radius:50%;background:#8b5cf6;"></span> Crew (<?= count($crewPings) ?> pings, <?= $crewNearbyCount ?> on-site)</span>
                <?php if ($arrivalBorder): ?>
                    <span><span style="display:inline-block;width:10px;height:10px;border:2px solid #3b82f6;"></span> Geofence</span>
                <?php endif; ?>
                <div class="mt-1">Truck: <?= count($truckPings) ?> pings, <?= count(array_filter($truckPings, fn($p) => $p['nearby'])) ?> on-site</div>
            </div>
        </div>
    </div>

    <!-- Right: Info & Resolution -->
    <div class="col-lg-4">

        <!-- Conflict Summary -->
        <div class="card mb-3 mw-cr-card mw-cr-card--<?= $conflictType === 'truck_at_site_no_clockin' ? 'warning' : 'info' ?>">
            <div class="card-body">
                <h6 class="card-title mb-2">Conflict Summary</h6>
                <?php if ($conflictType === 'truck_at_site_no_clockin'): ?>
                    <p class="mb-1"><strong>Truck detected at site but visit not clocked in</strong></p>
                    <?php if ($truck): ?>
                        <div class="small text-muted mb-1">Truck: <?= htmlspecialchars($truck['full_name']) ?></div>
                    <?php endif; ?>
                    <?php if ($firstSeen): ?>
                        <div class="small text-muted mb-1">
                            On-site: <?= (new DateTime('@' . $firstSeen))->setTimezone($tz)->format('g:i a') ?>
                            &ndash; <?= (new DateTime('@' . $lastSeen))->setTimezone($tz)->format('g:i a') ?>
                            (<?= $dwellMinutes ?> min)
                        </div>
                    <?php endif; ?>
                    <div class="small text-muted">Pings on-site: <?= count($nearbyPings) ?></div>
                <?php elseif ($conflictType === 'clockin_no_truck'): ?>
                    <p class="mb-1"><strong>Visit completed but no truck GPS detected at site</strong></p>
                    <div class="small text-muted">Crew may have stayed while truck went elsewhere</div>
                <?php else: ?>
                    <p class="mb-1"><strong>Visit details</strong></p>
                    <div class="small text-muted">Status: <?= ucfirst($visit['status']) ?></div>
                <?php endif; ?>
                <div class="small text-muted mt-1">
                    Crew: <?= htmlspecialchars($visit['crew_name'] ?? 'Unassigned') ?>
                    &middot; Service: <?= htmlspecialchars(ucwords(str_replace('_', ' ', $visit['service_type'] ?? 'N/A'))) ?>
                </div>
                <div class="small mt-1">
                    <span style="color:#8b5cf6;">&#9679;</span>
                    Crew pings: <?= count($crewPings) ?> total, <?= $crewNearbyCount ?> on-site
                    <?php if (count($crewPings) === 0): ?>
                        <span class="text-danger">(no GPS tracking)</span>
                    <?php endif; ?>
                </div>
            </div>
        </div>

        <!-- Photo Evidence -->
        <div class="card mb-3">
            <div class="card-body">
                <h6 class="card-title mb-2">
                    <i data-feather="camera" class="mr-1" style="width:16px;height:16px;"></i>
                    Photo Evidence
                </h6>
                <?php if (!empty($photos)): ?>
                    <div class="mb-2">
                        <span class="badge badge-success"><?= count($photos) ?> photo<?= count($photos) !== 1 ? 's' : '' ?> taken</span>
                        <span class="small text-muted ml-1">Strong on-site evidence</span>
                    </div>
                    <div class="d-flex flex-wrap" style="gap:6px;">
                        <?php foreach ($photos as $photo):
                            $thumbUrl = $photo['thumb_path'] ?: '/uploads/visits/' . $photo['filename'];
                            $viewUrl  = $photo['view_path'] ?: '/uploads/visits/' . $photo['filename'];
                        ?>
                            <a href="<?= htmlspecialchars($viewUrl) ?>" target="_blank" class="mw-cr-thumb-link">
                                <img src="<?= htmlspecialchars($thumbUrl) ?>"
                                     alt="<?= htmlspecialchars($photo['photo_type']) ?>"
                                     class="mw-cr-thumb"
                                     title="<?= htmlspecialchars(ucfirst($photo['photo_type'])) ?> — <?= htmlspecialchars($photo['uploaded_at']) ?>">
                            </a>
                        <?php endforeach; ?>
                    </div>
                <?php else: ?>
                    <span class="badge badge-secondary">No photos</span>
                    <span class="small text-muted ml-1">No photo evidence for this visit</span>
                <?php endif; ?>
            </div>
        </div>

        <!-- Property Config -->
        <div class="card mb-3">
            <div class="card-body">
                <h6 class="card-title mb-2">
                    <i data-feather="settings" class="mr-1" style="width:16px;height:16px;"></i>
                    Property Configuration
                </h6>
                <div class="mb-2 d-flex align-items-center justify-content-between">
                    <div>
                        <span class="small font-weight-bold">Geofence Boundary:</span>
                        <?php if ($arrivalBorder): ?>
                            <span class="badge badge-success">Drawn</span>
                        <?php else: ?>
                            <span class="badge badge-secondary">Not drawn</span>
                        <?php endif; ?>
                    </div>
                    <a href="zone-editor.php?property_id=<?= $propertyId ?>&return_to=<?= urlencode('jobs/conflict-resolution.php?visit_id=' . $visitId . '&date=' . $date . ($truckId ? '&truck_id=' . $truckId : '')) ?>"
                       class="btn btn-sm btn-outline-primary py-0 px-2" style="font-size:0.75rem;">
                        <i data-feather="edit-2" style="width:12px;height:12px;"></i> Edit
                    </a>
                </div>
                <div class="mb-2">
                    <span class="small font-weight-bold">Auto Clock-In:</span>
                    <?php if ($trackingConfig['auto_clock_in']): ?>
                        <span class="badge badge-success">Enabled</span>
                    <?php else: ?>
                        <span class="badge badge-danger">Disabled</span>
                    <?php endif; ?>
                    <span class="small text-muted ml-1">Source: <?= htmlspecialchars($trackingConfig['source']['auto_clock_in'] ?? 'default') ?></span>
                </div>
                <?php if (!$trackingConfig['auto_clock_in']): ?>
                    <button class="btn btn-sm btn-outline-primary mt-1" id="btnEnableAutoClockIn"
                            data-plan-id="<?= (int)$visit['plan_id'] ?>">
                        Enable Auto Clock-In for This Plan
                    </button>
                <?php endif; ?>
            </div>
        </div>

        <!-- Existing Time Entries -->
        <?php if (!empty($timeEntries)): ?>
        <div class="card mb-3">
            <div class="card-body">
                <h6 class="card-title mb-2">
                    <i data-feather="clock" class="mr-1" style="width:16px;height:16px;"></i>
                    Time Entries
                </h6>
                <?php foreach ($timeEntries as $te): ?>
                    <div class="small mb-1">
                        <?= htmlspecialchars($te['start_time']) ?>
                        <?php if ($te['end_time']): ?> &ndash; <?= htmlspecialchars($te['end_time']) ?><?php endif; ?>
                        (<?= $te['duration_minutes'] ?? '—' ?> min)
                        <span class="badge badge-<?= $te['status'] === 'completed' ? 'success' : ($te['status'] === 'active' ? 'primary' : 'secondary') ?>"><?= $te['status'] ?></span>
                        <?php if ($te['auto_started']): ?><span class="badge badge-info">auto</span><?php endif; ?>
                    </div>
                <?php endforeach; ?>
            </div>
        </div>
        <?php endif; ?>

        <!-- Resolution Form -->
        <?php if (!$existingResolution): ?>
        <div class="card mb-3 mw-cr-card mw-cr-card--resolve">
            <div class="card-body">
                <h6 class="card-title mb-3">
                    <i data-feather="check-circle" class="mr-1" style="width:16px;height:16px;"></i>
                    Resolution
                </h6>

                <p class="small mb-2">Was the crew at this property?</p>
                <div class="btn-group btn-group-sm mb-3 d-flex" id="crewPresenceToggle">
                    <button class="btn btn-outline-success flex-fill" data-presence="yes">Yes — Create Time Entry</button>
                    <button class="btn btn-outline-secondary flex-fill" data-presence="no">No — Dismiss</button>
                </div>

                <!-- Yes: Create Time Entry -->
                <div id="resolveYes" style="display:none;">
                    <div class="form-group">
                        <label class="small font-weight-bold">Start Time</label>
                        <input type="time" class="form-control form-control-sm" id="entryStart"
                               value="<?= $firstSeen ? (new DateTime('@' . $firstSeen))->setTimezone($tz)->format('H:i') : '' ?>">
                    </div>
                    <div class="form-group">
                        <label class="small font-weight-bold">End Time</label>
                        <input type="time" class="form-control form-control-sm" id="entryEnd"
                               value="<?= $lastSeen ? (new DateTime('@' . $lastSeen))->setTimezone($tz)->format('H:i') : '' ?>">
                    </div>
                    <div class="form-group">
                        <label class="small font-weight-bold">Crew Member</label>
                        <select class="form-control form-control-sm" id="entryCrewId">
                            <?php foreach ($assignedCrew as $c): ?>
                                <option value="<?= (int)$c['id'] ?>"><?= htmlspecialchars($c['full_name']) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="form-group">
                        <label class="small font-weight-bold">Notes (optional)</label>
                        <textarea class="form-control form-control-sm" id="entryNotes" rows="2"
                                  placeholder="e.g., Auto clock-in failed, confirmed via truck GPS"></textarea>
                    </div>
                    <button class="btn btn-success btn-sm btn-block" id="btnCreateEntry">
                        Create Time Entry &amp; Complete Visit
                    </button>
                </div>

                <!-- No: Dismiss -->
                <div id="resolveNo" style="display:none;">
                    <div class="form-group">
                        <label class="small font-weight-bold">Notes (optional)</label>
                        <textarea class="form-control form-control-sm" id="dismissNotes" rows="2"
                                  placeholder="e.g., Truck stopped briefly, crew did not service property"></textarea>
                    </div>
                    <button class="btn btn-secondary btn-sm btn-block" id="btnDismiss">
                        Dismiss — No Action Needed
                    </button>
                </div>
            </div>
        </div>
        <?php else: ?>
        <div class="card mb-3">
            <div class="card-body">
                <h6 class="card-title mb-2">Resolution Applied</h6>
                <div class="small mb-1">
                    <strong><?= ucfirst(str_replace('_', ' ', $existingResolution['resolution_type'])) ?></strong>
                    on <?= date('M j, Y g:i a', strtotime($existingResolution['created_at'])) ?>
                </div>
                <?php if ($existingResolution['notes']): ?>
                    <div class="small text-muted"><?= htmlspecialchars($existingResolution['notes']) ?></div>
                <?php endif; ?>
            </div>
        </div>
        <?php endif; ?>

    </div>
</div>

<script>
(function() {
    var csrfToken = <?= json_encode($csrfToken) ?>;
    var visitId   = <?= (int)$visitId ?>;
    var planId    = <?= (int)$visit['plan_id'] ?>;
    var visitDate = <?= json_encode($date) ?>;
    var propLat   = <?= $propLat ?: 'null' ?>;
    var propLng   = <?= $propLng ?: 'null' ?>;
    var truckPings = <?= json_encode(array_values($truckPings)) ?>;
    var crewPings  = <?= json_encode(array_values($crewPings)) ?>;
    var arrivalBorder = <?= $arrivalBorder && !empty($arrivalBorder['polygon']) ? json_encode($arrivalBorder['polygon']) : 'null' ?>;

    // --- Map ---
    if (propLat && propLng) {
        var map = L.map('crMap', { scrollWheelZoom: true }).setView([propLat, propLng], 17);

        L.tileLayer('https://server.arcgisonline.com/ArcGIS/rest/services/World_Imagery/MapServer/tile/{z}/{y}/{x}', {
            attribution: 'Esri',
            opacity: 0.85
        }).addTo(map);

        // Property marker
        L.marker([propLat, propLng], {
            icon: L.divIcon({
                className: 'mw-cr-prop-marker',
                html: '<div style="width:14px;height:14px;background:var(--mw-green,#2D8659);border:3px solid #fff;border-radius:50%;box-shadow:0 1px 4px rgba(0,0,0,0.4);"></div>',
                iconSize: [14, 14],
                iconAnchor: [7, 7]
            })
        }).addTo(map).bindPopup('Property: <?= addslashes(htmlspecialchars($visit['property_address'] ?? '')) ?>');

        // 150m proximity circle
        L.circle([propLat, propLng], {
            radius: 150,
            color: 'var(--mw-green, #2D8659)',
            fillColor: '#2D8659',
            fillOpacity: 0.08,
            weight: 2,
            dashArray: '6,4'
        }).addTo(map);

        // Geofence arrival border (if drawn)
        if (arrivalBorder && arrivalBorder.length > 0) {
            var borderLatLngs = arrivalBorder.map(function(pt) { return L.latLng(pt[0], pt[1]); });
            L.polygon(borderLatLngs, {
                color: '#3b82f6',
                fillColor: '#3b82f6',
                fillOpacity: 0.06,
                weight: 2,
                dashArray: '4,4'
            }).addTo(map);
        }

        // Truck GPS pings + trail
        if (truckPings.length > 0) {
            var trailPoints = [];
            var bounds = L.latLngBounds([[propLat, propLng]]);

            for (var i = 0; i < truckPings.length; i++) {
                var p = truckPings[i];
                var ll = L.latLng(p.lat, p.lng);
                trailPoints.push(ll);

                // Only include nearby pings in bounds to keep map focused
                if (p.nearby) bounds.extend(ll);

                L.circleMarker(ll, {
                    radius: p.nearby ? 5 : 3,
                    fillColor: p.nearby ? '#2D8659' : '#94a3b8',
                    fillOpacity: p.nearby ? 0.9 : 0.5,
                    stroke: true,
                    color: '#fff',
                    weight: 1,
                    opacity: 0.8
                }).addTo(map).bindPopup(
                    '<strong>' + p.time + '</strong><br>' +
                    p.dist_m + 'm from property' +
                    (p.nearby ? ' (on-site)' : '')
                );
            }

            // Trail line
            if (trailPoints.length > 1) {
                L.polyline(trailPoints, {
                    color: '#f59e0b',
                    weight: 2,
                    opacity: 0.5,
                    smoothFactor: 1
                }).addTo(map);
            }

            map.fitBounds(bounds.pad(0.3));
        }

        // Crew GPS pings (purple)
        if (crewPings.length > 0) {
            for (var j = 0; j < crewPings.length; j++) {
                var cp = crewPings[j];
                var cll = L.latLng(cp.lat, cp.lng);
                if (cp.nearby) bounds.extend(cll);

                L.circleMarker(cll, {
                    radius: cp.nearby ? 5 : 3,
                    fillColor: '#8b5cf6',
                    fillOpacity: cp.nearby ? 0.9 : 0.5,
                    stroke: true,
                    color: '#fff',
                    weight: 1,
                    opacity: 0.8
                }).addTo(map).bindPopup(
                    '<strong>Crew ' + cp.time + '</strong><br>' +
                    cp.dist_m + 'm from property' +
                    (cp.nearby ? ' (on-site)' : '')
                );
            }
            if (typeof bounds !== 'undefined') map.fitBounds(bounds.pad(0.3));
        }
    } else {
        document.getElementById('crMap').innerHTML = '<div class="text-center text-muted p-5">No property coordinates available</div>';
    }

    // --- Crew presence toggle ---
    var toggleBtns = document.querySelectorAll('#crewPresenceToggle button');
    toggleBtns.forEach(function(btn) {
        btn.addEventListener('click', function() {
            toggleBtns.forEach(function(b) { b.classList.remove('active'); });
            btn.classList.add('active');
            var presence = btn.getAttribute('data-presence');
            document.getElementById('resolveYes').style.display = presence === 'yes' ? '' : 'none';
            document.getElementById('resolveNo').style.display  = presence === 'no'  ? '' : 'none';
        });
    });

    // --- Create Time Entry ---
    var btnCreate = document.getElementById('btnCreateEntry');
    if (btnCreate) {
        btnCreate.addEventListener('click', function() {
            var startTime = document.getElementById('entryStart').value;
            var endTime   = document.getElementById('entryEnd').value;
            var crewId    = document.getElementById('entryCrewId').value;
            var notes     = document.getElementById('entryNotes').value;

            if (!startTime || !endTime) {
                alert('Please enter start and end times.');
                return;
            }

            btnCreate.disabled = true;
            btnCreate.textContent = 'Creating...';

            fetch('/crm/api/conflict-resolve.php', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({
                    csrf_token: csrfToken,
                    action: 'create_time_entry',
                    visit_id: visitId,
                    user_id: parseInt(crewId),
                    date: visitDate,
                    start_time: startTime,
                    end_time: endTime,
                    notes: notes
                })
            })
            .then(function(r) { return r.json(); })
            .then(function(data) {
                if (data.success) {
                    location.reload();
                } else {
                    alert('Error: ' + (data.error || 'Unknown error'));
                    btnCreate.disabled = false;
                    btnCreate.textContent = 'Create Time Entry & Complete Visit';
                }
            })
            .catch(function() {
                alert('Network error. Please try again.');
                btnCreate.disabled = false;
                btnCreate.textContent = 'Create Time Entry & Complete Visit';
            });
        });
    }

    // --- Dismiss ---
    var btnDismiss = document.getElementById('btnDismiss');
    if (btnDismiss) {
        btnDismiss.addEventListener('click', function() {
            var notes = document.getElementById('dismissNotes').value;
            btnDismiss.disabled = true;
            btnDismiss.textContent = 'Dismissing...';

            fetch('/crm/api/conflict-resolve.php', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({
                    csrf_token: csrfToken,
                    action: 'dismiss',
                    visit_id: visitId,
                    date: visitDate,
                    notes: notes
                })
            })
            .then(function(r) { return r.json(); })
            .then(function(data) {
                if (data.success) {
                    location.reload();
                } else {
                    alert('Error: ' + (data.error || 'Unknown error'));
                    btnDismiss.disabled = false;
                    btnDismiss.textContent = 'Dismiss — No Action Needed';
                }
            })
            .catch(function() {
                alert('Network error.');
                btnDismiss.disabled = false;
                btnDismiss.textContent = 'Dismiss — No Action Needed';
            });
        });
    }

    // --- Enable Auto Clock-In ---
    var btnAuto = document.getElementById('btnEnableAutoClockIn');
    if (btnAuto) {
        btnAuto.addEventListener('click', function() {
            btnAuto.disabled = true;
            btnAuto.textContent = 'Enabling...';

            fetch('/crm/api/conflict-resolve.php', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({
                    csrf_token: csrfToken,
                    action: 'enable_auto_clockin',
                    plan_id: planId
                })
            })
            .then(function(r) { return r.json(); })
            .then(function(data) {
                if (data.success) {
                    location.reload();
                } else {
                    alert('Error: ' + (data.error || 'Unknown error'));
                    btnAuto.disabled = false;
                    btnAuto.textContent = 'Enable Auto Clock-In for This Plan';
                }
            })
            .catch(function() {
                alert('Network error.');
                btnAuto.disabled = false;
                btnAuto.textContent = 'Enable Auto Clock-In for This Plan';
            });
        });
    }
})();
</script>

<?php include dirname(__DIR__) . '/includes/appstack_footer.php'; ?>

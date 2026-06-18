<?php
/**
 * Zone Editor — Draw arrival border + named work zones for a property.
 *
 * Usage: /crm/jobs/zone-editor.php?property_id=N
 *        /crm/jobs/zone-editor.php?plan_id=N  (resolves property automatically)
 */
require_once dirname(__DIR__) . '/../loginAuth/auth.php';
require_once dirname(__DIR__) . '/includes/functions.php';
requireLogin();
requirePermission('jobs.edit');
$user = getCurrentUser();
$db   = getDB();

// Resolve property
$propertyId = (int)($_GET['property_id'] ?? 0);
$planId     = (int)($_GET['plan_id'] ?? 0);

if (!$propertyId && $planId) {
    $row = $db->prepare("SELECT property_id FROM job_plans WHERE id = ? LIMIT 1");
    $row->execute([$planId]);
    $r = $row->fetch(PDO::FETCH_ASSOC);
    $propertyId = $r ? (int)$r['property_id'] : 0;
}

if (!$propertyId) {
    http_response_code(400);
    die('property_id or plan_id required');
}

// Load property
$pStmt = $db->prepare("SELECT * FROM properties WHERE id = ? LIMIT 1");
$pStmt->execute([$propertyId]);
$property = $pStmt->fetch(PDO::FETCH_ASSOC);
if (!$property) {
    http_response_code(404);
    die('Property not found');
}

// Load active plans for this property (for work zone assignment)
$plansStmt = $db->prepare("
    SELECT id, title, service_type
    FROM job_plans
    WHERE property_id = ? AND status = 'active'
    ORDER BY title ASC
");
$plansStmt->execute([$propertyId]);
$plans = $plansStmt->fetchAll(PDO::FETCH_ASSOC);

$lat = $property['latitude']  ?? $property['lat'] ?? 49.2827;
$lng = $property['longitude'] ?? $property['lng'] ?? -123.1207;

// Support return_to parameter for back navigation (prevent open redirect)
$returnTo = '';
if (!empty($_GET['return_to'])) {
    $raw = $_GET['return_to'];
    if (preg_match('#^(clients_appstack|contracts/view\.php|quote-workflow|dashboard_appstack|jobs/)#', $raw)) {
        $returnTo = $raw;
    }
}

$pageTitle  = 'Zone Editor — ' . htmlspecialchars($property['address'] ?? 'Property');
$activePage = 'jobs';
$mapsApiKey = defined('GOOGLE_MAPS_API_KEY') ? GOOGLE_MAPS_API_KEY : '';
$mdtVer     = @filemtime(dirname(__DIR__) . '/js/map-draw/map-draw-tool.js') ?: '1';
$extraHead  = '<script src="/crm/js/map-draw/map-draw-tool.js?v=' . $mdtVer . '"></script>'
            . '<script src="https://maps.googleapis.com/maps/api/js?key=' . htmlspecialchars($mapsApiKey, ENT_QUOTES, 'UTF-8') . '&libraries=geometry&callback=initZoneEditor" async defer></script>';
?>
<?php include dirname(__DIR__) . '/includes/appstack_head.php'; ?>

<div class="row mb-3">
    <div class="col-12">
        <div class="d-flex align-items-center justify-content-between flex-wrap gap-2">
            <div>
                <h3 class="mb-1">
                    <i data-feather="layers" class="me-2"></i>
                    Zone Editor
                </h3>
                <p class="text-muted mb-0">
                    <?= htmlspecialchars($property['address'] ?? 'Property #' . $propertyId) ?>
                </p>
            </div>
            <?php if ($returnTo): ?>
            <a href="/crm/<?php echo htmlspecialchars($returnTo); ?>" class="btn btn-outline-secondary btn-sm">
                <i data-feather="arrow-left" class="me-1"></i> Back
            </a>
            <?php else: ?>
            <a href="javascript:history.back()" class="btn btn-outline-secondary btn-sm">
                <i data-feather="arrow-left" class="me-1"></i> Back
            </a>
            <?php endif; ?>
        </div>
    </div>
</div>

<div class="row">

    <!-- Left: Controls -->
    <div class="col-lg-4 col-md-5 mb-3">

        <!-- Arrival Border -->
        <div class="card mw-zone-card mb-3">
            <div class="card-header d-flex align-items-center gap-2">
                <span class="mw-zone-dot" style="background:#FFD700;"></span>
                <strong>Arrival Border</strong>
            </div>
            <div class="card-body">
                <p class="text-muted small mb-3">
                    One large polygon surrounding the entire property (including all addresses).
                    Crew entering this border auto-clocks in for the shift.
                </p>
                <button id="btn-draw-border" class="btn btn-sm mw-zone-btn-border w-100" onclick="startDrawArrivalBorder()" disabled>
                    <i data-feather="edit-3" class="me-1"></i> Draw Arrival Border
                </button>
            </div>
        </div>

        <!-- Work Zones -->
        <div class="card mw-zone-card mb-3">
            <div class="card-header d-flex align-items-center gap-2">
                <span class="mw-zone-dot" style="background:#2D8659;"></span>
                <strong>Work Zones</strong>
            </div>
            <div class="card-body">
                <p class="text-muted small mb-2">
                    Named zones within the property. GPS pings attribute time to the zone
                    the crew is physically in. One plan can have multiple zones.
                </p>

                <div class="mb-2">
                    <label class="form-label small mb-1">Plan</label>
                    <select id="zone-plan-select" class="form-select form-select-sm">
                        <option value="">— select plan —</option>
                        <?php foreach ($plans as $p): ?>
                        <option value="<?= $p['id'] ?>">
                            <?= htmlspecialchars($p['title']) ?>
                            <?php if ($p['service_type']): ?>(<?= htmlspecialchars($p['service_type']) ?>)<?php endif; ?>
                        </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="mb-2">
                    <label class="form-label small mb-1">Zone Name</label>
                    <input id="zone-label-input" type="text" class="form-control form-control-sm"
                           placeholder="e.g. Willow Entrance Bed" maxlength="100">
                </div>
                <button id="btn-draw-zone" class="btn btn-sm btn-success w-100" onclick="startDrawWorkZone()" disabled>
                    <i data-feather="plus-circle" class="me-1"></i> Draw Work Zone
                </button>
            </div>
        </div>

        <!-- Drawing Controls (shown while drawing) -->
        <div id="draw-controls" class="card mw-zone-card mb-3" style="display:none;">
            <div class="card-body text-center">
                <p class="text-warning small mb-2">
                    <i data-feather="crosshair" class="me-1"></i>
                    Drawing mode active — click to add points, then click the first point (or double-click) to close the shape. It saves automatically.
                </p>
                <button class="btn btn-sm btn-outline-secondary w-100" onclick="cancelDraw()">
                    <i data-feather="x" class="me-1"></i> Cancel
                </button>
            </div>
        </div>

        <!-- Zone List -->
        <div class="card mw-zone-card">
            <div class="card-header">
                <strong>Zones on this Property</strong>
            </div>
            <div id="zone-list" class="list-group list-group-flush">
                <div class="list-group-item text-muted small text-center py-3" id="zone-list-empty">
                    No zones drawn yet
                </div>
            </div>
        </div>

    </div>

    <!-- Right: Map -->
    <div class="col-lg-8 col-md-7">
        <div class="card mw-zone-card">
            <div class="card-body p-0">
                <div id="zone-map" style="height:600px; border-radius: 0 0 8px 8px;"></div>
            </div>
        </div>
        <div class="d-flex align-items-center justify-content-between mt-2">
            <p class="text-muted small mb-0">
                <i data-feather="info" class="me-1"></i>
                Yellow = arrival border. Coloured = work zones. Hover a zone to see its name.
            </p>
            <div id="tileCacheStatus" class="mw-tile-status" style="display:none;"></div>
        </div>
    </div>

</div>

<script>
const PROPERTY_ID  = <?= (int)$propertyId ?>;
const MAP_CENTER   = { lat: <?= (float)$lat ?>, lng: <?= (float)$lng ?> };
const CSRF_TOKEN   = '<?= htmlspecialchars(generateCSRFToken()) ?>';
const GEOFENCE_API = '/crm/api/geofence.php';

const ZONE_COLORS = ['#2D8659','#e85d04','#0d6efd','#6f42c1','#20c997','#fd7e14','#dc3545','#0dcaf0'];

let tool = null;             // MapDrawTool — the shared drawing engine
let zoneList = [];           // zones for this property
const zoneOverlays = {};     // geofence_id -> Google Maps overlay
let drawIntent = null;       // {zoneType, planId, label} | null
let colorIdx = 0;

// Google Maps script callback
function initZoneEditor() {
    if (tool) return;
    tool = new MapDrawTool({
        mapContainer: 'zone-map',
        center:       MAP_CENTER,
        zoom:         20,
        marker:       true,
        onReady: function () {
            const b1 = document.getElementById('btn-draw-border');
            const b2 = document.getElementById('btn-draw-zone');
            if (b1) b1.disabled = false;
            if (b2) b2.disabled = false;

            // Property label pinned to top-left of the map
            const addrChip = document.createElement('div');
            addrChip.style.cssText = 'background:#fff;padding:4px 10px;border-radius:20px;box-shadow:0 1px 4px rgba(0,0,0,.35);font-size:12px;font-weight:600;color:#1a1a1a;margin:8px;white-space:nowrap;pointer-events:none;';
            addrChip.textContent = <?= json_encode($property['address'] ?? 'Property #' . $propertyId) ?>;
            tool.getMap().controls[google.maps.ControlPosition.TOP_LEFT].push(addrChip);

            loadZones();
            if (typeof feather !== 'undefined') feather.replace();
        },
        onComplete: function (m) { onShapeComplete(m); }
    });
    tool.init();
}

function loadZones() {
    MapDrawTool.loadZones(GEOFENCE_API, PROPERTY_ID).then(function (zones) {
        zoneList = zones || [];
        const coordArrays = [];
        zoneList.forEach(function (z) { addZoneOverlay(z); coordArrays.push(z.ring); });
        if (coordArrays.length) tool.fitAll(coordArrays);
        renderZoneList(zoneList);
    });
}

function addZoneOverlay(z) {
    const isArrival = z.zone_type === 'arrival_border';
    const color = isArrival ? '#FFD700' : ZONE_COLORS[colorIdx++ % ZONE_COLORS.length];
    z._color = color;
    const overlay = tool.renderShape(z.ring, {
        shapeType:   'polygon',
        color:       color,
        strokeColor: color,
        fillOpacity: isArrival ? 0.08 : 0.18,
        weight:      isArrival ? 3 : 2
    });
    if (overlay) zoneOverlays[z.id] = overlay;
}

function startDrawArrivalBorder() {
    if (!tool || !tool.isReady()) return;
    drawIntent = { zoneType: 'arrival_border', planId: null, label: 'Arrival Border' };
    showDrawControls();
    tool.startDraw('polygon');
}

function startDrawWorkZone() {
    if (!tool || !tool.isReady()) return;
    const planId = parseInt(document.getElementById('zone-plan-select').value);
    const label  = document.getElementById('zone-label-input').value.trim();
    if (!planId) { alert('Please select a plan first.'); return; }
    if (!label)  { alert('Please enter a zone name first.'); return; }
    drawIntent = { zoneType: 'work_zone', planId: planId, label: label };
    showDrawControls();
    tool.startDraw('polygon');
}

function onShapeComplete(m) {
    if (!drawIntent) { if (tool) tool.clearCurrent(); return; }
    const intent = drawIntent;
    drawIntent = null;
    hideDrawControls();
    if (!m || !m.coords || m.coords.length < 3) { tool.clearCurrent(); return; }

    // Drop the editable scratch shape; the styled saved overlay is re-added on success.
    tool.clearCurrent();

    MapDrawTool.saveZone(GEOFENCE_API, {
        csrfToken:  CSRF_TOKEN,
        propertyId: PROPERTY_ID,
        zoneType:   intent.zoneType,
        planId:     intent.planId,
        coords:     m.coords,
        label:      intent.label
    }).then(function (data) {
        if (!data || !data.success) { alert('Save failed: ' + ((data && data.error) || 'error')); return; }
        const z = {
            id:        data.geofence_id,
            zone_type: intent.zoneType,
            plan_id:   intent.planId,
            label:     intent.label,
            ring:      MapDrawTool.ringFromCoords(m.coords)
        };
        zoneList.push(z);
        addZoneOverlay(z);
        renderZoneList(zoneList);
    }).catch(function (err) { alert('Save failed: ' + err.message); });
}

function cancelDraw() {
    drawIntent = null;
    if (tool) { tool.stopDraw(); tool.clearCurrent(); }
    hideDrawControls();
}

function deleteZone(id) {
    if (!confirm('Delete this zone?')) return;
    MapDrawTool.deleteZone(GEOFENCE_API, CSRF_TOKEN, id).then(function (data) {
        if (!data || !data.success) { alert('Delete failed: ' + ((data && data.error) || 'error')); return; }
        if (zoneOverlays[id]) { tool.removeOverlay(zoneOverlays[id]); delete zoneOverlays[id]; }
        zoneList = zoneList.filter(function (z) { return z.id !== id; });
        renderZoneList(zoneList);
    }).catch(function (err) { alert('Delete failed: ' + err.message); });
}

function showDrawControls() {
    document.getElementById('draw-controls').style.display = '';
    document.getElementById('btn-draw-border').disabled = true;
    document.getElementById('btn-draw-zone').disabled   = true;
    if (typeof feather !== 'undefined') feather.replace();
}

function hideDrawControls() {
    document.getElementById('draw-controls').style.display = 'none';
    document.getElementById('btn-draw-border').disabled = false;
    document.getElementById('btn-draw-zone').disabled   = false;
}

function renderZoneList(zones) {
    const list  = document.getElementById('zone-list');
    const empty = document.getElementById('zone-list-empty');

    // Remove old zone rows
    list.querySelectorAll('.mw-zone-list-item').forEach(el => el.remove());

    const hasZones = zones && zones.length > 0;
    empty.style.display = hasZones ? 'none' : '';

    if (!hasZones) return;

    zones.forEach(z => {
        const isArrival = z.zone_type === 'arrival_border';
        const dot = isArrival ? '#FFD700' : (z._color || '#2D8659');
        const label = isArrival ? 'Arrival Border' : (z.label || 'Work Zone');

        const item = document.createElement('div');
        item.className = 'list-group-item mw-zone-list-item d-flex align-items-center justify-content-between py-2';
        item.innerHTML = `
            <div class="d-flex align-items-center gap-2">
                <span class="mw-zone-dot" style="background:${dot};"></span>
                <div>
                    <div class="small fw-semibold">${escHtml(label)}</div>
                    ${!isArrival && z.plan_id ? `<div class="text-muted" style="font-size:11px;">Plan #${z.plan_id}</div>` : ''}
                    ${isArrival ? `<div class="text-muted" style="font-size:11px;">Clock-in trigger</div>` : ''}
                </div>
            </div>
            <button class="btn btn-sm btn-outline-danger btn-xs" onclick="deleteZone(${z.id})" title="Delete zone">
                <i data-feather="trash-2"></i>
            </button>
        `;
        list.appendChild(item);
    });

    if (typeof feather !== 'undefined') feather.replace();
}

function escHtml(s) {
    return String(s).replace(/[&<>"']/g, c => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'})[c]);
}

// Fallback: if the Google callback didn't fire (script cached/race), init on load.
document.addEventListener('DOMContentLoaded', function () {
    if (!tool && typeof google !== 'undefined' && google.maps) initZoneEditor();
});
</script>

<?php include dirname(__DIR__) . '/includes/appstack_footer.php'; ?>

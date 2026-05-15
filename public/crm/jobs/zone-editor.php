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
    if (preg_match('#^(clients_appstack|quote-workflow|dashboard_appstack|jobs/)#', $raw)) {
        $returnTo = $raw;
    }
}

$pageTitle  = 'Zone Editor — ' . htmlspecialchars($property['address'] ?? 'Property');
$activePage = 'jobs';
$extraHead  = '
<link rel="stylesheet" href="https://unpkg.com/leaflet@1.9.4/dist/leaflet.css" />
<script src="https://unpkg.com/leaflet@1.9.4/dist/leaflet.js"></script>
<script src="/crm/js/geofence/zone-editor-manager.js"></script>
';
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
                <span class="mw-zone-dot" style="background:var(--mw-orange);"></span>
                <strong>Arrival Border</strong>
            </div>
            <div class="card-body">
                <p class="text-muted small mb-3">
                    One large polygon surrounding the entire property (including all addresses).
                    Crew entering this border auto-clocks in for the shift.
                </p>
                <button id="btn-draw-border" class="mw-bsetup-btn w-100" style="font-size:13px;padding:8px 14px;justify-content:center;" onclick="startDrawArrivalBorder()">
                    <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><polygon points="3 11 22 2 13 21 11 13 3 11"/></svg>
                    Draw Arrival Border
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
                <button id="btn-draw-zone" class="btn btn-sm btn-success w-100" onclick="startDrawWorkZone()">
                    <i data-feather="plus-circle" class="me-1"></i> Draw Work Zone
                </button>
            </div>
        </div>

        <!-- Drawing Controls (shown while drawing) -->
        <div id="draw-controls" class="card mw-zone-card mb-3" style="display:none;">
            <div class="card-body text-center">
                <p class="text-warning small mb-2">
                    <i data-feather="crosshair" class="me-1"></i>
                    Drawing mode active — click to add vertices
                </p>
                <div class="d-flex gap-2">
                    <button class="btn btn-sm btn-success flex-fill" onclick="zm.finishDraw()">
                        <i data-feather="check" class="me-1"></i> Finish
                    </button>
                    <button class="btn btn-sm btn-outline-secondary flex-fill" onclick="zm.cancelDraw(); hideDrawControls()">
                        <i data-feather="x" class="me-1"></i> Cancel
                    </button>
                </div>
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
                Yellow dashed = arrival border. Coloured = work zones. Hover a zone to see its name.
            </p>
            <div id="tileCacheStatus" class="mw-tile-status" style="display:none;"></div>
        </div>
    </div>

</div>

<script>
const PROPERTY_ID  = <?= (int)$propertyId ?>;
const MAP_CENTER   = [<?= (float)$lat ?>, <?= (float)$lng ?>];
const CSRF_TOKEN   = '<?= htmlspecialchars(generateCSRFToken()) ?>';

let zm;

document.addEventListener('DOMContentLoaded', () => {
    zm = new ZoneEditorManager({
        mapContainer:   'zone-map',
        apiBase:        '/crm/api/geofence.php',
        csrfToken:      CSRF_TOKEN,
        propertyId:     PROPERTY_ID,
        center:         MAP_CENTER,
        zoom:           20,
        onZonesChanged: renderZoneList,
    });
    zm.init();

    // Large green dot to indicate the property location
    if (typeof L !== 'undefined' && zm._map) {
        L.circleMarker(MAP_CENTER, {
            radius: 14,
            color: '#0D3B2E',
            weight: 3,
            fillColor: '#2D8659',
            fillOpacity: 0.9,
        }).addTo(zm._map).bindTooltip('Property Location', { permanent: false, direction: 'top' });
    }

    if (typeof feather !== 'undefined') feather.replace();
});

function startDrawArrivalBorder() {
    zm.startDrawArrivalBorder();
    showDrawControls('arrival_border');
}

function startDrawWorkZone() {
    const planId = parseInt(document.getElementById('zone-plan-select').value);
    const label  = document.getElementById('zone-label-input').value.trim();
    if (!planId) { alert('Please select a plan first.'); return; }
    if (!label)  { alert('Please enter a zone name first.'); return; }
    zm.startDrawWorkZone(planId, label);
    showDrawControls('work_zone');
}

function showDrawControls(type) {
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

// Override finishDraw to also hide controls
const origFinish = ZoneEditorManager.prototype.finishDraw;
ZoneEditorManager.prototype.finishDraw = function() {
    origFinish.call(this);
    hideDrawControls();
};

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
        const dot = isArrival ? '#FFD700' : (z.color ? z.color.stroke : '#2D8659');
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
            <button class="btn btn-sm btn-outline-danger btn-xs" onclick="zm.deleteZone(${z.id})" title="Delete zone">
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
</script>

<?php include dirname(__DIR__) . '/includes/appstack_footer.php'; ?>

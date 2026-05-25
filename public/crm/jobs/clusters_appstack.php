<?php
require_once dirname(__DIR__) . '/../loginAuth/auth.php';
require_once dirname(__DIR__) . '/includes/functions.php';
requireLogin();
$user = getCurrentUser();

if (!in_array($user['role'] ?? '', ['admin', 'manager'], true)) {
    header('Location: /crm/jobs/schedule_appstack.php');
    exit;
}

$pageTitle  = 'Route Clusters';
$activePage = 'jobs';
?>
<?php include dirname(__DIR__) . '/includes/appstack_head.php'; ?>

<div class="d-flex justify-content-between align-items-center mb-4">
    <div>
        <h1 class="h3 mb-1">Route Clusters</h1>
        <p class="text-muted mb-0">Group adjacent properties that share a parking stop to accurately split labour cost.</p>
    </div>
    <button class="btn btn-primary mw-btn-primary" data-toggle="modal" data-target="#createModal">
        <i data-feather="plus" class="feather-sm mr-1"></i> New Cluster
    </button>
</div>

<!-- Tabs -->
<ul class="nav nav-tabs mw-filter-tabs mb-4" id="clusterTabs">
    <li class="nav-item">
        <a class="nav-link active" data-toggle="tab" href="#tab-suggested">
            Suggested <span class="badge badge-warning ml-1" id="suggestedBadge" style="display:none"></span>
        </a>
    </li>
    <li class="nav-item">
        <a class="nav-link" data-toggle="tab" href="#tab-active">Active Clusters</a>
    </li>
</ul>

<div class="tab-content">

    <!-- ── Suggested ──────────────────────────────────────────────────── -->
    <div class="tab-pane fade show active" id="tab-suggested">
        <div id="suggestedLoading" class="text-center py-5">
            <div class="spinner-border text-success" role="status"></div>
            <p class="text-muted mt-2">Scanning properties for cluster candidates…</p>
        </div>
        <div id="suggestedList" class="row" style="display:none"></div>
        <div id="suggestedEmpty" class="mw-empty-state text-center py-5" style="display:none">
            <i data-feather="check-circle" style="width:48px;height:48px;color:var(--mw-lime)"></i>
            <h5 class="mt-3">No new clusters detected</h5>
            <p class="text-muted">All adjacent properties are already grouped, or there isn't enough route history yet.</p>
        </div>
    </div>

    <!-- ── Active Clusters ────────────────────────────────────────────── -->
    <div class="tab-pane fade" id="tab-active">
        <div id="activeLoading" class="text-center py-5">
            <div class="spinner-border text-success" role="status"></div>
        </div>
        <div id="activeList" style="display:none"></div>
        <div id="activeEmpty" class="mw-empty-state text-center py-5" style="display:none">
            <i data-feather="layers" style="width:48px;height:48px;color:var(--mw-light)"></i>
            <h5 class="mt-3">No clusters defined yet</h5>
            <p class="text-muted">Check the Suggested tab or create one manually.</p>
        </div>
    </div>

</div>

<!-- ── Create / Edit Modal ───────────────────────────────────────────── -->
<div class="modal fade" id="createModal" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title" id="modalTitle">New Cluster</h5>
                <button type="button" class="close" data-dismiss="modal"><span>&times;</span></button>
            </div>
            <div class="modal-body">
                <input type="hidden" id="editClusterId" value="">
                <div class="form-group">
                    <label class="mw-form-label">Cluster name</label>
                    <input type="text" class="form-control" id="clusterName" placeholder="e.g. Tina Wu / Ken Van Zenten">
                </div>
                <div class="form-group">
                    <label class="mw-form-label">Travel time to this stop <span class="text-muted">(minutes)</span></label>
                    <input type="number" class="form-control" id="clusterTravel" min="0" max="60" value="0">
                    <small class="form-text text-muted">Drive time from the previous stop to this cluster. Split equally across all members.</small>
                </div>
                <div class="form-group" id="propertyPickerGroup">
                    <label class="mw-form-label">Properties <span class="text-muted">(select 2 or more)</span></label>
                    <div id="propertyList" class="mw-cluster-property-list border rounded p-2" style="max-height:220px;overflow-y:auto">
                        <div class="text-muted small p-2">Loading properties…</div>
                    </div>
                </div>
                <div class="form-group">
                    <label class="mw-form-label">Notes <span class="text-muted">(optional)</span></label>
                    <textarea class="form-control" id="clusterNotes" rows="2"></textarea>
                </div>
                <div id="modalError" class="alert alert-danger" style="display:none"></div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-dismiss="modal">Cancel</button>
                <button type="button" class="btn btn-primary mw-btn-primary" id="saveClusterBtn">Save Cluster</button>
            </div>
        </div>
    </div>
</div>

<script>
(function () {
    const API = '/api/jobs/clusters.php';
    let allProperties = [];

    // ── Boot ────────────────────────────────────────────────────────────
    loadSuggested();
    loadActive();
    loadProperties();

    document.getElementById('saveClusterBtn').addEventListener('click', saveCluster);

    // ── Suggested ───────────────────────────────────────────────────────
    function loadSuggested() {
        fetch(API + '?action=detect')
            .then(r => r.json())
            .then(data => {
                const el    = document.getElementById('suggestedList');
                const empty = document.getElementById('suggestedEmpty');
                const load  = document.getElementById('suggestedLoading');
                load.style.display = 'none';

                const candidates = (data.candidates || []).filter(c => !c.already_clustered);
                if (!candidates.length) { empty.style.display = ''; return; }

                document.getElementById('suggestedBadge').textContent = candidates.length;
                document.getElementById('suggestedBadge').style.display = '';

                el.innerHTML = candidates.map(renderCandidate).join('');
                el.style.display = '';
                feather.replace();
            })
            .catch(() => {
                document.getElementById('suggestedLoading').innerHTML =
                    '<p class="text-danger">Detection failed. Check server logs.</p>';
            });
    }

    function renderCandidate(c) {
        const conf = { high: 'success', medium: 'warning', low: 'secondary' };
        const badge = `<span class="badge badge-${conf[c.confidence] || 'secondary'} mr-2">${c.confidence}</span>`;
        const dist  = c.proximity_m != null ? `${c.proximity_m}m apart` : 'No GPS data';
        const cooc  = c.cooccur_count > 0 ? `serviced together ${c.cooccur_count}×` : 'no schedule history yet';
        const ids   = JSON.stringify(c.property_ids);
        const name  = escHtml(c.suggested_name);

        const members = c.member_details.map(m =>
            `<li class="list-unstyled-item small text-muted">${escHtml(m.name || m.address)}, ${escHtml(m.city)}</li>`
        ).join('');

        return `
        <div class="col-md-6 col-lg-4 mb-3">
            <div class="card h-100 mw-cluster-card">
                <div class="card-body">
                    <div class="d-flex align-items-start justify-content-between mb-2">
                        <h6 class="card-title mb-0">${name}</h6>
                        ${badge}
                    </div>
                    <ul class="pl-0 mb-2">${members}</ul>
                    <p class="text-muted small mb-0">
                        <i data-feather="map-pin" class="feather-xs mr-1"></i>${dist} &nbsp;·&nbsp;
                        <i data-feather="calendar" class="feather-xs mr-1"></i>${cooc}
                    </p>
                </div>
                <div class="card-footer bg-transparent">
                    <button class="btn btn-sm btn-success w-100"
                        onclick="approveCandidate(${ids}, '${escAttr(c.suggested_name)}')">
                        <i data-feather="check" class="feather-sm mr-1"></i> Create Cluster
                    </button>
                </div>
            </div>
        </div>`;
    }

    window.approveCandidate = function(propertyIds, name) {
        document.getElementById('editClusterId').value = '';
        document.getElementById('modalTitle').textContent = 'Confirm Cluster';
        document.getElementById('clusterName').value = name;
        document.getElementById('clusterTravel').value = 0;
        document.getElementById('clusterNotes').value = '';

        // Pre-tick the properties
        waitForProperties(() => {
            document.querySelectorAll('#propertyList .mw-prop-check').forEach(cb => {
                cb.checked = propertyIds.includes(parseInt(cb.value));
            });
        });

        $('#createModal').modal('show');
    };

    // ── Active ──────────────────────────────────────────────────────────
    function loadActive() {
        fetch(API + '?action=list')
            .then(r => r.json())
            .then(data => {
                const el    = document.getElementById('activeList');
                const empty = document.getElementById('activeEmpty');
                const load  = document.getElementById('activeLoading');
                load.style.display = 'none';

                if (!data.clusters || !data.clusters.length) { empty.style.display = ''; return; }

                el.innerHTML = `
                <div class="table-responsive">
                <table class="table mw-list-table">
                    <thead><tr>
                        <th>Cluster</th>
                        <th>Members</th>
                        <th>Travel</th>
                        <th></th>
                    </tr></thead>
                    <tbody>${data.clusters.map(renderRow).join('')}</tbody>
                </table></div>`;
                el.style.display = '';
                feather.replace();
            });
    }

    function renderRow(c) {
        const travel = c.default_travel_minutes > 0
            ? `${c.default_travel_minutes} min`
            : '<span class="text-muted">—</span>';
        return `
        <tr>
            <td><strong>${escHtml(c.name)}</strong>
                ${c.notes ? `<br><small class="text-muted">${escHtml(c.notes)}</small>` : ''}
            </td>
            <td class="text-muted">${escHtml(c.member_names || '')}</td>
            <td>${travel}</td>
            <td class="text-right">
                <button class="btn btn-sm btn-outline-secondary mr-1"
                    onclick="editCluster(${c.id}, '${escAttr(c.name)}', ${c.default_travel_minutes}, '${escAttr(c.notes || '')}')">
                    <i data-feather="edit-2" class="feather-xs"></i>
                </button>
                <button class="btn btn-sm btn-outline-danger"
                    onclick="deleteCluster(${c.id}, '${escAttr(c.name)}')">
                    <i data-feather="trash-2" class="feather-xs"></i>
                </button>
            </td>
        </tr>`;
    }

    window.editCluster = function(id, name, travel, notes) {
        document.getElementById('editClusterId').value = id;
        document.getElementById('modalTitle').textContent = 'Edit Cluster';
        document.getElementById('clusterName').value = name;
        document.getElementById('clusterTravel').value = travel;
        document.getElementById('clusterNotes').value = notes;
        document.getElementById('propertyPickerGroup').style.display = 'none';
        $('#createModal').modal('show');
    };

    window.deleteCluster = function(id, name) {
        if (!confirm(`Remove cluster "${name}"? This will not delete any time records.`)) return;
        fetch(API, { method: 'POST', headers: {'Content-Type':'application/json'},
            body: JSON.stringify({ action: 'delete', cluster_id: id }) })
            .then(r => r.json())
            .then(data => { if (data.success) location.reload(); });
    };

    // ── Create / Edit ───────────────────────────────────────────────────
    function saveCluster() {
        const id      = document.getElementById('editClusterId').value;
        const name    = document.getElementById('clusterName').value.trim();
        const travel  = parseInt(document.getElementById('clusterTravel').value) || 0;
        const notes   = document.getElementById('clusterNotes').value.trim();
        const errEl   = document.getElementById('modalError');
        errEl.style.display = 'none';

        if (!name) { showError('Cluster name is required.'); return; }

        if (id) {
            // Edit — no property changes
            fetch(API, { method: 'POST', headers: {'Content-Type':'application/json'},
                body: JSON.stringify({ action: 'update', cluster_id: parseInt(id), name, travel_minutes: travel, notes }) })
                .then(r => r.json())
                .then(data => {
                    if (data.success) { $('#createModal').modal('hide'); location.reload(); }
                    else showError(data.message || 'Save failed.');
                });
        } else {
            const checked = [...document.querySelectorAll('#propertyList .mw-prop-check:checked')];
            const propIds = checked.map(cb => parseInt(cb.value));
            if (propIds.length < 2) { showError('Select at least 2 properties.'); return; }

            fetch(API, { method: 'POST', headers: {'Content-Type':'application/json'},
                body: JSON.stringify({ action: 'create', name, travel_minutes: travel, notes, property_ids: propIds }) })
                .then(r => r.json())
                .then(data => {
                    if (data.success) { $('#createModal').modal('hide'); location.reload(); }
                    else showError(data.message || 'Save failed.');
                });
        }
    }

    // ── Properties picker ───────────────────────────────────────────────
    function loadProperties() {
        fetch('/api/properties.php?action=list_active')
            .then(r => r.json())
            .then(data => {
                allProperties = data.properties || [];
                renderPropertyPicker();
            })
            .catch(() => {
                document.getElementById('propertyList').innerHTML =
                    '<p class="text-danger small p-2">Could not load properties.</p>';
            });
    }

    function renderPropertyPicker() {
        const el = document.getElementById('propertyList');
        if (!allProperties.length) { el.innerHTML = '<p class="text-muted small p-2">No properties found.</p>'; return; }
        el.innerHTML = allProperties.map(p => {
            const label = [p.contact_name, p.address, p.city].filter(Boolean).join(' · ');
            return `<label class="d-flex align-items-center p-1 rounded mw-prop-row mb-1">
                <input type="checkbox" class="mw-prop-check mr-2" value="${p.id}">
                <span class="small">${escHtml(label)}</span>
            </label>`;
        }).join('');
    }

    function waitForProperties(cb) {
        if (allProperties.length) { cb(); return; }
        const timer = setInterval(() => { if (allProperties.length) { clearInterval(timer); cb(); } }, 100);
    }

    $('#createModal').on('show.bs.modal', function () {
        const id = document.getElementById('editClusterId').value;
        document.getElementById('propertyPickerGroup').style.display = id ? 'none' : '';
        document.getElementById('modalError').style.display = 'none';
    });

    // ── Utilities ───────────────────────────────────────────────────────
    function showError(msg) {
        const el = document.getElementById('modalError');
        el.textContent = msg;
        el.style.display = '';
    }

    function escHtml(s) {
        return String(s).replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;');
    }
    function escAttr(s) {
        return String(s).replace(/'/g,"\\'");
    }
})();
</script>

<?php include dirname(__DIR__) . '/includes/appstack_footer.php'; ?>

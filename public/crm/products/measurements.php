<?php
/**
 * Saved Measurements — Admin list & CRUD
 * View, edit, and delete saved property_measurements records.
 */
require_once dirname(__DIR__) . '/../loginAuth/auth.php';
require_once dirname(__DIR__) . '/includes/functions.php';

requireLogin();
$user = getCurrentUser();

$csrfToken = generateCSRFToken();
$pageTitle = 'Saved Measurements';
$activePage = 'products';
$extraHead = '<meta name="csrf-token" content="' . htmlspecialchars($csrfToken) . '">';
?>
<?php include dirname(__DIR__) . '/includes/appstack_head.php'; ?>

<a href="index.php" class="mw-back-link mb-3 d-inline-flex align-items-center">
    <svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" class="mr-1"><line x1="19" y1="12" x2="5" y2="12"></line><polyline points="12 19 5 12 12 5"></polyline></svg>
    Back to Products &amp; Services
</a>

<div class="mw-page-header d-flex justify-content-between align-items-center">
    <h1 class="h3 mb-0">Saved Measurements</h1>
    <a href="area-measurement.php" class="btn btn-primary btn-sm">
        <i data-feather="map" style="width:14px;height:14px;"></i> Open Measurement Tool
    </a>
</div>

<!-- Filter Bar -->
<div class="card mb-3">
    <div class="card-body py-2">
        <div class="row align-items-end g-2">
            <div class="col-md-4">
                <label class="form-label small mb-1">Search Property / Name</label>
                <input type="text" class="form-control form-control-sm" id="measSearch" placeholder="Address or measurement name...">
            </div>
            <div class="col-md-3">
                <label class="form-label small mb-1">Type</label>
                <select class="form-control form-control-sm" id="measTypeFilter">
                    <option value="">All Types</option>
                </select>
            </div>
            <div class="col-md-3">
                <label class="form-label small mb-1">Group</label>
                <select class="form-control form-control-sm" id="measGroupFilter">
                    <option value="">All Groups</option>
                </select>
            </div>
            <div class="col-md-2">
                <button class="btn btn-outline-secondary btn-sm btn-block" id="btnMeasFilter">
                    <i data-feather="filter" style="width:13px;height:13px;"></i> Filter
                </button>
            </div>
        </div>
    </div>
</div>

<!-- Results -->
<div class="card">
    <div class="card-header d-flex justify-content-between align-items-center">
        <h5 class="card-title mb-0">All Measurements <span class="badge badge-secondary ml-2" id="measCount">—</span></h5>
    </div>
    <div class="card-body p-0">
        <div id="measLoading" class="text-center py-5">
            <div class="spinner-border text-primary" role="status"></div>
            <p class="mt-2 text-muted">Loading measurements...</p>
        </div>
        <table class="table table-hover mb-0" id="measTable" style="display:none;">
            <thead>
                <tr>
                    <th>Property</th>
                    <th>Name</th>
                    <th>Type</th>
                    <th>Group</th>
                    <th>Area / Length</th>
                    <th>Measured By</th>
                    <th>Date</th>
                    <th style="width:120px;"></th>
                </tr>
            </thead>
            <tbody id="measTableBody"></tbody>
        </table>
        <div id="measEmpty" class="text-center py-5 text-muted" style="display:none;">
            <i data-feather="map" style="width:40px;height:40px;opacity:0.3;" class="mb-2"></i>
            <p>No measurements found. Draw measurements using the <a href="area-measurement.php">Area Measurement Tool</a>.</p>
        </div>
    </div>
</div>

<!-- Inline Edit Modal -->
<div class="modal fade" id="measEditModal" tabindex="-1" role="dialog">
    <div class="modal-dialog" role="document">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title">Edit Measurement</h5>
                <button type="button" class="close" data-dismiss="modal"><span>&times;</span></button>
            </div>
            <div class="modal-body">
                <div class="form-group">
                    <label>Measurement Name <span class="text-danger">*</span></label>
                    <input type="text" class="form-control" id="editMeasName" maxlength="100">
                </div>
                <div class="form-group">
                    <label>Type</label>
                    <select class="form-control" id="editMeasType"></select>
                </div>
                <div class="form-group">
                    <label>Group</label>
                    <select class="form-control" id="editMeasGroup">
                        <option value="">— None —</option>
                    </select>
                </div>
                <div class="form-group">
                    <label>Notes</label>
                    <input type="text" class="form-control" id="editMeasNotes" maxlength="255">
                </div>
                <input type="hidden" id="editMeasId">
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-dismiss="modal">Cancel</button>
                <button type="button" class="btn btn-primary" id="btnSaveMeas">Save Changes</button>
            </div>
        </div>
    </div>
</div>

<script>
(function () {
    const csrf = document.querySelector('meta[name="csrf-token"]')?.content || '';
    let allData = { measurements: [], filter_types: [], filter_groups: [] };

    function esc(s) {
        return String(s || '').replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;');
    }

    function fmtArea(m) {
        if (m.linear_ft) return (parseFloat(m.linear_ft) || 0).toFixed(1) + ' lin ft';
        if (m.area_sqft) return (parseFloat(m.area_sqft) || 0).toFixed(1) + ' sq ft';
        return '—';
    }

    function fmtDate(s) {
        if (!s) return '—';
        return new Date(s).toLocaleDateString('en-CA', { year: 'numeric', month: 'short', day: 'numeric' });
    }

    function buildFilterDropdowns(data) {
        const typeEl  = document.getElementById('measTypeFilter');
        const groupEl = document.getElementById('measGroupFilter');
        const editTypeEl  = document.getElementById('editMeasType');
        const editGroupEl = document.getElementById('editMeasGroup');

        // Type dropdown
        const typeVal = typeEl.value;
        typeEl.innerHTML = '<option value="">All Types</option>' +
            data.filter_types.map(t => `<option value="${esc(t)}">${esc(t)}</option>`).join('');
        typeEl.value = typeVal;

        // Group dropdown
        const groupVal = groupEl.value;
        groupEl.innerHTML = '<option value="">All Groups</option>' +
            data.filter_groups.map(g => `<option value="${esc(g.group_key)}">${esc(g.group_label)}</option>`).join('');
        groupEl.value = groupVal;

        // Edit modal dropdowns
        if (editTypeEl) {
            editTypeEl.innerHTML = '<option value="">— Select —</option>' +
                data.filter_types.map(t => `<option value="${esc(t)}">${esc(t)}</option>`).join('');
        }
        if (editGroupEl) {
            editGroupEl.innerHTML = '<option value="">— None —</option>' +
                data.filter_groups.map(g => `<option value="${esc(g.group_key)}">${esc(g.group_label)}</option>`).join('');
        }
    }

    function renderTable(rows) {
        const tbody = document.getElementById('measTableBody');
        const table = document.getElementById('measTable');
        const empty = document.getElementById('measEmpty');
        const count = document.getElementById('measCount');

        count.textContent = rows.length;

        if (!rows.length) {
            table.style.display = 'none';
            empty.style.display = '';
            return;
        }

        tbody.innerHTML = rows.map(m => `
            <tr id="mrow-${m.id}">
                <td>
                    <a href="area-measurement.php?property_id=${m.property_id}" class="text-decoration-none">
                        ${esc(m.property_address || '—')}
                        <small class="text-muted d-block">${esc(m.property_city || '')}</small>
                    </a>
                </td>
                <td>${esc(m.measurement_name)}</td>
                <td><span class="badge badge-light border">${esc(m.measurement_type)}</span></td>
                <td><small class="text-muted">${esc(m.measurement_group_key || '—')}</small></td>
                <td>${fmtArea(m)}</td>
                <td><small>${esc(m.measured_by_name || '—')}</small></td>
                <td><small>${fmtDate(m.created_at)}</small></td>
                <td class="text-right">
                    <button class="btn btn-sm btn-outline-primary mr-1" onclick="measEdit(${m.id})">Edit</button>
                    <button class="btn btn-sm btn-outline-danger" onclick="measDelete(${m.id}, '${esc(m.measurement_name)}')">Delete</button>
                </td>
            </tr>
        `).join('');

        table.style.display = '';
        empty.style.display = 'none';

        if (window.feather) feather.replace();
    }

    function loadMeasurements() {
        const loading = document.getElementById('measLoading');
        const table   = document.getElementById('measTable');
        const empty   = document.getElementById('measEmpty');

        loading.style.display = '';
        table.style.display = 'none';
        empty.style.display = 'none';

        const search = document.getElementById('measSearch').value.trim();
        const type   = document.getElementById('measTypeFilter').value;
        const group  = document.getElementById('measGroupFilter').value;

        let url = '/crm/api/measurements.php?action=list';
        if (search) url += '&search=' + encodeURIComponent(search);
        if (type)   url += '&type=' + encodeURIComponent(type);
        if (group)  url += '&group_key=' + encodeURIComponent(group);

        fetch(url)
            .then(r => r.json())
            .then(data => {
                loading.style.display = 'none';
                allData = data;
                buildFilterDropdowns(data);
                renderTable(data.measurements || []);
                if (window.feather) feather.replace();
            })
            .catch(() => {
                loading.style.display = 'none';
                empty.style.display = '';
                empty.querySelector && (empty.innerHTML = '<p class="text-danger">Failed to load measurements.</p>');
            });
    }

    // Initial load
    loadMeasurements();

    // Filter button
    document.getElementById('btnMeasFilter')?.addEventListener('click', loadMeasurements);
    document.getElementById('measSearch')?.addEventListener('keypress', function(e) {
        if (e.key === 'Enter') loadMeasurements();
    });

    // Edit modal
    window.measEdit = function (id) {
        const m = allData.measurements.find(x => x.id === id);
        if (!m) return;
        document.getElementById('editMeasId').value   = id;
        document.getElementById('editMeasName').value = m.measurement_name;
        document.getElementById('editMeasNotes').value = m.notes || '';

        // Set dropdowns after they're populated
        setTimeout(() => {
            document.getElementById('editMeasType').value  = m.measurement_type || '';
            document.getElementById('editMeasGroup').value = m.measurement_group_key || '';
        }, 50);

        if (typeof $ !== 'undefined') {
            $('#measEditModal').modal('show');
        }
    };

    document.getElementById('btnSaveMeas')?.addEventListener('click', function () {
        const id   = parseInt(document.getElementById('editMeasId').value);
        const name = document.getElementById('editMeasName').value.trim();
        if (!name) { alert('Name is required'); return; }

        this.disabled = true;
        fetch('/crm/api/measurements.php?action=update', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({
                csrf_token:           csrf,
                id:                   id,
                measurement_name:     name,
                measurement_type:     document.getElementById('editMeasType').value,
                measurement_group_key:document.getElementById('editMeasGroup').value,
                notes:                document.getElementById('editMeasNotes').value,
            })
        }).then(r => r.json()).then(data => {
            this.disabled = false;
            if (data.success) {
                if (typeof $ !== 'undefined') $('#measEditModal').modal('hide');
                loadMeasurements();
            } else {
                alert(data.error || 'Save failed');
            }
        }).catch(() => { this.disabled = false; alert('Network error'); });
    });

    // Delete
    window.measDelete = function (id, name) {
        if (!confirm('Delete measurement "' + name + '"? This cannot be undone and will recalculate property totals.')) return;
        fetch('/crm/api/measurements.php?action=delete', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ csrf_token: csrf, id: id })
        }).then(r => r.json()).then(data => {
            if (data.success) {
                loadMeasurements();
            } else {
                alert(data.error || 'Delete failed');
            }
        });
    };
})();
</script>

<?php include dirname(__DIR__) . '/includes/appstack_footer.php'; ?>

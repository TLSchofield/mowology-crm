<?php
require_once __DIR__ . '/../loginAuth/auth.php';
require_once __DIR__ . '/includes/functions.php';

requireLogin();
requirePermission('settings.edit');
$user = getCurrentUser();

$pageTitle  = 'Privacy & Data';
$activePage = 'privacy';
$csrfToken  = generateCSRFToken();
$extraHead  = '<meta name="csrf-token" content="' . htmlspecialchars($csrfToken) . '">';
?>
<?php include __DIR__ . '/includes/appstack_head.php'; ?>

          <!-- Page header -->
          <div class="mw-page-header mb-4">
              <div>
                  <h1 class="h3 mb-0">
                      <i data-feather="shield" style="width:22px;height:22px;vertical-align:-3px;margin-right:8px;color:var(--mw-green);"></i>
                      Privacy &amp; Data Compliance
                  </h1>
                  <p class="text-muted mt-1 mb-0" style="font-size:.875rem;">
                      PIPEDA data subject requests, retention settings, and automated purge tools.
                  </p>
              </div>
              <div>
                  <a href="/privacy" target="_blank" class="btn btn-outline-secondary btn-sm">
                      <i data-feather="external-link" style="width:12px;height:12px;"></i> Public Privacy Policy
                  </a>
                  <a href="/privacy-request" target="_blank" class="btn btn-outline-secondary btn-sm ml-1">
                      <i data-feather="external-link" style="width:12px;height:12px;"></i> Request Form
                  </a>
              </div>
          </div>

          <!-- Tabs -->
          <ul class="nav nav-tabs mb-4" id="privacyTabs" role="tablist">
              <li class="nav-item">
                  <a class="nav-link active" id="requests-tab" data-toggle="tab" href="#requests-panel" role="tab">
                      DSAR Requests <span id="pendingCount" class="badge badge-warning ml-1" style="display:none;"></span>
                  </a>
              </li>
              <li class="nav-item">
                  <a class="nav-link" id="retention-tab" data-toggle="tab" href="#retention-panel" role="tab">
                      Data Retention
                  </a>
              </li>
              <li class="nav-item">
                  <a class="nav-link" id="log-request-tab" data-toggle="tab" href="#log-request-panel" role="tab">
                      Log Request
                  </a>
              </li>
          </ul>

          <div class="tab-content">

            <!-- ─── DSAR Requests tab ─────────────────────────────────────── -->
            <div class="tab-pane fade show active" id="requests-panel" role="tabpanel">
                <div class="card">
                    <div class="card-header d-flex align-items-center justify-content-between">
                        <h5 class="card-title mb-0">Data Subject Access Requests</h5>
                        <div class="d-flex" style="gap:8px;">
                            <select id="statusFilter" class="form-control form-control-sm" style="width:130px;">
                                <option value="">All statuses</option>
                                <option value="pending" selected>Pending</option>
                                <option value="in_progress">In Progress</option>
                                <option value="completed">Completed</option>
                                <option value="rejected">Rejected</option>
                            </select>
                            <button class="btn btn-sm btn-outline-secondary" onclick="loadRequests()">Refresh</button>
                        </div>
                    </div>
                    <div class="card-body p-0">
                        <div id="requestsLoading" class="text-center p-4 text-muted">Loading…</div>
                        <div id="requestsTable" style="display:none;">
                            <div class="table-responsive">
                                <table class="table table-hover mb-0">
                                    <thead>
                                        <tr>
                                            <th>#</th>
                                            <th>Type</th>
                                            <th>Requester</th>
                                            <th>Contact</th>
                                            <th>Status</th>
                                            <th>Submitted</th>
                                            <th></th>
                                        </tr>
                                    </thead>
                                    <tbody id="requestsBody"></tbody>
                                </table>
                            </div>
                        </div>
                        <div id="requestsEmpty" style="display:none;" class="text-center p-4 text-muted">
                            No requests found.
                        </div>
                    </div>
                    <div id="requestsPagination" class="card-footer d-flex align-items-center justify-content-between" style="display:none!important;">
                        <span id="pageInfo" class="text-muted small"></span>
                        <div style="gap:6px;display:flex;">
                            <button class="btn btn-sm btn-outline-secondary" id="prevPage" onclick="changePage(-1)">Previous</button>
                            <button class="btn btn-sm btn-outline-secondary" id="nextPage" onclick="changePage(1)">Next</button>
                        </div>
                    </div>
                </div>

                <!-- Status update modal -->
                <div class="modal fade" id="statusModal" tabindex="-1">
                    <div class="modal-dialog">
                        <div class="modal-content">
                            <div class="modal-header">
                                <h5 class="modal-title">Update Request Status</h5>
                                <button type="button" class="close" data-dismiss="modal"><span>&times;</span></button>
                            </div>
                            <div class="modal-body">
                                <input type="hidden" id="statusModalId">
                                <div id="statusModalAlert" style="display:none;" class="alert mb-3"></div>
                                <div class="form-group">
                                    <label class="form-label">Status</label>
                                    <select id="statusModalStatus" class="form-control">
                                        <option value="pending">Pending</option>
                                        <option value="in_progress">In Progress</option>
                                        <option value="completed">Completed</option>
                                        <option value="rejected">Rejected</option>
                                    </select>
                                </div>
                                <div class="form-group mb-0">
                                    <label class="form-label">Notes <small class="text-muted">(appended)</small></label>
                                    <textarea id="statusModalNotes" class="form-control" rows="2"
                                              placeholder="Optional note about this status change"></textarea>
                                </div>
                            </div>
                            <div class="modal-footer">
                                <button type="button" class="btn btn-secondary" data-dismiss="modal">Cancel</button>
                                <button type="button" class="btn btn-primary" id="statusSaveBtn" onclick="saveStatus()">Save Status</button>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Anonymize modal (admin only) -->
                <?php if (userHasPermission('database.manage')): ?>
                <div class="modal fade" id="anonymizeModal" tabindex="-1">
                    <div class="modal-dialog">
                        <div class="modal-content">
                            <div class="modal-header bg-danger text-white">
                                <h5 class="modal-title">
                                    <i data-feather="alert-triangle" style="width:16px;height:16px;vertical-align:-2px;margin-right:6px;"></i>
                                    Anonymise Contact
                                </h5>
                                <button type="button" class="close text-white" data-dismiss="modal"><span>&times;</span></button>
                            </div>
                            <div class="modal-body">
                                <div id="anonModalAlert" style="display:none;" class="alert mb-3"></div>
                                <p class="text-danger font-weight-bold">This action is irreversible.</p>
                                <p class="small">
                                    The contact's name, email, and phone will be replaced with anonymised placeholders.
                                    GPS coordinates on visits will be nulled. Photos will be deleted.
                                    Financial records are retained under legal hold (7-year CRA requirement).
                                </p>
                                <input type="hidden" id="anonContactId">
                                <div class="form-group mb-0">
                                    <label class="form-label">Reason for erasure <span class="text-danger">*</span></label>
                                    <textarea id="anonReason" class="form-control" rows="2"
                                              placeholder="e.g. PIPEDA deletion request — contact requested via email on 2026-04-01"></textarea>
                                </div>
                            </div>
                            <div class="modal-footer">
                                <button type="button" class="btn btn-secondary" data-dismiss="modal">Cancel</button>
                                <button type="button" class="btn btn-danger" id="anonConfirmBtn" onclick="doAnonymize()">
                                    Anonymise Contact
                                </button>
                            </div>
                        </div>
                    </div>
                </div>
                <?php endif; ?>
            </div>

            <!-- ─── Retention Settings tab ────────────────────────────────── -->
            <div class="tab-pane fade" id="retention-panel" role="tabpanel">
                <div class="row">
                    <div class="col-lg-7">
                        <div class="card mb-4">
                            <div class="card-header d-flex align-items-center justify-content-between">
                                <h5 class="card-title mb-0">Data Retention Periods</h5>
                                <small class="text-muted">Requires database.manage</small>
                            </div>
                            <div class="card-body p-0">
                                <div id="retentionLoading" class="text-center p-4 text-muted">Loading…</div>
                                <div id="retentionList" style="display:none;"></div>
                            </div>
                        </div>
                    </div>
                    <div class="col-lg-5">
                        <div class="card mb-4">
                            <div class="card-header"><h5 class="card-title mb-0">Run Purge Now</h5></div>
                            <div class="card-body">
                                <p class="text-muted small">
                                    Deletes location data older than the configured retention periods.
                                    This runs automatically each night at 3:00 AM.
                                </p>
                                <div id="purgeResult" style="display:none;" class="alert alert-success mb-3 small"></div>
                                <button class="btn btn-outline-danger" id="runPurgeBtn" onclick="runPurge()">
                                    <i data-feather="trash-2" style="width:14px;height:14px;"></i> Run Purge Now
                                </button>
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            <!-- ─── Log Request tab ──────────────────────────────────────── -->
            <div class="tab-pane fade" id="log-request-panel" role="tabpanel">
                <div class="card" style="max-width:580px;">
                    <div class="card-header"><h5 class="card-title mb-0">Log a Privacy Request (Admin)</h5></div>
                    <div class="card-body">
                        <p class="text-muted small mb-3">
                            Log a DSAR received by phone, email, or in person on behalf of a customer.
                        </p>
                        <div id="logRequestAlert" style="display:none;" class="alert mb-3"></div>
                        <div class="form-group">
                            <label class="form-label">Request Type <span class="text-danger">*</span></label>
                            <select id="logType" class="form-control">
                                <option value="access">Access — send data export</option>
                                <option value="deletion">Deletion — right to erasure</option>
                                <option value="correction">Correction — fix inaccurate data</option>
                            </select>
                        </div>
                        <div class="form-group">
                            <label class="form-label">Requester Name <span class="text-danger">*</span></label>
                            <input type="text" id="logName" class="form-control" placeholder="Full name">
                        </div>
                        <div class="form-group">
                            <label class="form-label">Requester Email <span class="text-danger">*</span></label>
                            <input type="email" id="logEmail" class="form-control" placeholder="Email address">
                        </div>
                        <div class="form-group mb-0">
                            <label class="form-label">Notes</label>
                            <textarea id="logNotes" class="form-control" rows="3"
                                      placeholder="How request was received, context, etc."></textarea>
                        </div>
                    </div>
                    <div class="card-footer">
                        <button class="btn btn-primary" onclick="logRequest()">Log Request</button>
                    </div>
                </div>
            </div>

          </div><!-- /tab-content -->

<script>
(function () {
    var CSRF = <?php echo json_encode($csrfToken); ?>;
    var API  = '/crm/api/privacy.php';
    var curPage = 1;
    var totalPages = 1;

    // ── Requests tab ──────────────────────────────────────────────────────────

    window.loadRequests = function (page) {
        page = page || curPage;
        var status = document.getElementById('statusFilter').value;
        document.getElementById('requestsLoading').style.display = 'block';
        document.getElementById('requestsTable').style.display = 'none';
        document.getElementById('requestsEmpty').style.display = 'none';
        document.getElementById('requestsPagination').style.display = 'none';

        fetch(API + '?action=list&status=' + encodeURIComponent(status) + '&page=' + page, {
            credentials: 'same-origin',
        })
        .then(function (r) { return r.json(); })
        .then(function (data) {
            document.getElementById('requestsLoading').style.display = 'none';
            if (!data.success) return;

            totalPages = data.total_pages || 1;
            curPage    = data.page || 1;

            var pending = data.requests.filter(function (r) { return r.status === 'pending'; }).length;
            var badge   = document.getElementById('pendingCount');
            if (status === '' || status === 'pending') {
                badge.textContent = data.total;
                badge.style.display = (data.total > 0) ? 'inline' : 'none';
            }

            if (!data.requests || !data.requests.length) {
                document.getElementById('requestsEmpty').style.display = 'block';
                return;
            }

            var tbody = document.getElementById('requestsBody');
            tbody.innerHTML = '';
            data.requests.forEach(function (r) {
                var statusClass = {pending:'warning',in_progress:'info',completed:'success',rejected:'secondary'}[r.status] || 'secondary';
                var typeLabel   = {access:'Access',deletion:'Deletion',correction:'Correction'}[r.request_type] || r.request_type;
                var tr = document.createElement('tr');
                tr.innerHTML = [
                    '<td><strong>#' + r.id + '</strong></td>',
                    '<td><span class="badge badge-light">' + typeLabel + '</span></td>',
                    '<td>' + esc(r.requester_name) + '<br><small class="text-muted">' + esc(r.requester_email) + '</small></td>',
                    '<td>' + (r.contact_name && r.contact_name !== ' ' ? '<span class="text-success">' + esc(r.contact_name) + '</span>' : '<span class="text-warning small">Match manually</span>') + '</td>',
                    '<td><span class="badge badge-' + statusClass + '">' + r.status.replace('_',' ') + '</span></td>',
                    '<td class="small text-muted">' + r.created_at.substring(0,10) + '</td>',
                    '<td class="text-right"><div style="display:flex;gap:4px;justify-content:flex-end;">',
                    (r.contact_id ? '<a href="/crm/api/privacy.php?action=export&contact_id=' + r.contact_id + '" class="btn btn-xs btn-outline-primary" title="Download export">Export</a>' : ''),
                    '<button class="btn btn-xs btn-outline-secondary" onclick="openStatusModal(' + r.id + ',\'' + r.status + '\')">Update</button>',
                    (<?php echo json_encode(userHasPermission('database.manage')); ?> && r.contact_id ? '<button class="btn btn-xs btn-outline-danger" onclick="openAnonModal(' + r.contact_id + ')">Erase</button>' : ''),
                    '</div></td>',
                ].join('');
                tbody.appendChild(tr);
            });

            document.getElementById('requestsTable').style.display = 'block';
            if (totalPages > 1) {
                document.getElementById('requestsPagination').style.removeProperty('display');
                document.getElementById('pageInfo').textContent = 'Page ' + curPage + ' of ' + totalPages + ' (' + data.total + ' total)';
                document.getElementById('prevPage').disabled = curPage <= 1;
                document.getElementById('nextPage').disabled = curPage >= totalPages;
            }
        })
        .catch(function () {
            document.getElementById('requestsLoading').textContent = 'Error loading requests.';
        });
    };

    window.changePage = function (delta) {
        curPage = Math.max(1, Math.min(totalPages, curPage + delta));
        loadRequests(curPage);
    };

    document.getElementById('statusFilter').addEventListener('change', function () {
        curPage = 1;
        loadRequests(1);
    });

    // ── Status modal ──────────────────────────────────────────────────────────

    window.openStatusModal = function (id, status) {
        document.getElementById('statusModalId').value = id;
        document.getElementById('statusModalStatus').value = status;
        document.getElementById('statusModalNotes').value = '';
        document.getElementById('statusModalAlert').style.display = 'none';
        $('#statusModal').modal('show');
    };

    window.saveStatus = function () {
        var btn = document.getElementById('statusSaveBtn');
        btn.disabled = true;
        fetch(API, {
            method: 'POST',
            credentials: 'same-origin',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({
                csrf_token: CSRF,
                action:     'update_status',
                id:         parseInt(document.getElementById('statusModalId').value),
                status:     document.getElementById('statusModalStatus').value,
                notes:      document.getElementById('statusModalNotes').value.trim(),
            }),
        })
        .then(function (r) { return r.json(); })
        .then(function (d) {
            btn.disabled = false;
            if (d.success) {
                $('#statusModal').modal('hide');
                loadRequests();
            } else {
                var a = document.getElementById('statusModalAlert');
                a.className = 'alert alert-danger mb-3';
                a.textContent = d.error;
                a.style.display = 'block';
            }
        })
        .catch(function () { btn.disabled = false; });
    };

    // ── Anonymize modal ───────────────────────────────────────────────────────

    window.openAnonModal = function (contactId) {
        document.getElementById('anonContactId').value = contactId;
        document.getElementById('anonReason').value = '';
        document.getElementById('anonModalAlert').style.display = 'none';
        $('#anonymizeModal').modal('show');
    };

    window.doAnonymize = function () {
        var btn    = document.getElementById('anonConfirmBtn');
        var reason = document.getElementById('anonReason').value.trim();
        if (!reason) {
            var a = document.getElementById('anonModalAlert');
            a.className = 'alert alert-danger mb-3';
            a.textContent = 'Please enter a reason.';
            a.style.display = 'block';
            return;
        }
        btn.disabled = true;
        fetch(API, {
            method: 'POST',
            credentials: 'same-origin',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({
                csrf_token: CSRF,
                action:     'anonymize',
                contact_id: parseInt(document.getElementById('anonContactId').value),
                reason:     reason,
            }),
        })
        .then(function (r) { return r.json(); })
        .then(function (d) {
            btn.disabled = false;
            if (d.success) {
                $('#anonymizeModal').modal('hide');
                loadRequests();
            } else {
                var a = document.getElementById('anonModalAlert');
                a.className = 'alert alert-danger mb-3';
                a.textContent = d.error;
                a.style.display = 'block';
            }
        })
        .catch(function () { btn.disabled = false; });
    };

    // ── Retention tab ─────────────────────────────────────────────────────────

    function loadRetention() {
        fetch(API + '?action=retention', { credentials: 'same-origin' })
        .then(function (r) { return r.json(); })
        .then(function (data) {
            document.getElementById('retentionLoading').style.display = 'none';
            if (!data.success) return;
            var list = document.getElementById('retentionList');
            list.innerHTML = '';
            data.settings.forEach(function (s) {
                var row = document.createElement('div');
                row.className = 'p-3 border-bottom d-flex align-items-center';
                row.innerHTML = [
                    '<div style="flex:1;">',
                    '<div class="font-weight-600">' + esc(s.data_type.replace(/_/g,' ')) + '</div>',
                    '<div class="text-muted small">' + esc(s.description || '') + '</div>',
                    '</div>',
                    '<div class="d-flex align-items-center" style="gap:8px;">',
                    '<input type="number" value="' + s.retention_days + '" min="1" max="3650"',
                    '  class="form-control form-control-sm" style="width:80px;" data-type="' + esc(s.data_type) + '">',
                    '<span class="text-muted small">days</span>',
                    '<button class="btn btn-sm btn-outline-primary" onclick="saveRetention(this,\'' + esc(s.data_type) + '\')">Save</button>',
                    '</div>',
                ].join('');
                list.appendChild(row);
            });
            document.getElementById('retentionList').style.display = 'block';
        });
    }

    window.saveRetention = function (btn, dataType) {
        var input = document.querySelector('input[data-type="' + dataType + '"]');
        var days  = parseInt(input.value);
        if (!days || days < 1) { alert('Enter a valid number of days.'); return; }
        btn.disabled = true;
        btn.textContent = 'Saving…';
        fetch(API, {
            method: 'POST',
            credentials: 'same-origin',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ csrf_token: CSRF, action: 'save_retention', data_type: dataType, retention_days: days }),
        })
        .then(function (r) { return r.json(); })
        .then(function (d) {
            btn.disabled = false;
            btn.textContent = d.success ? 'Saved ✓' : 'Save';
            setTimeout(function () { btn.textContent = 'Save'; }, 2000);
        })
        .catch(function () { btn.disabled = false; btn.textContent = 'Save'; });
    };

    window.runPurge = function () {
        var btn = document.getElementById('runPurgeBtn');
        btn.disabled = true;
        btn.textContent = 'Running purge…';
        fetch(API, {
            method: 'POST',
            credentials: 'same-origin',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ csrf_token: CSRF, action: 'run_purge' }),
        })
        .then(function (r) { return r.json(); })
        .then(function (d) {
            btn.disabled = false;
            btn.textContent = 'Run Purge Now';
            var el = document.getElementById('purgeResult');
            el.style.display = 'block';
            if (d.success) {
                el.className = 'alert alert-success mb-3 small';
                var r = d.result;
                el.innerHTML = 'Purge complete: <strong>' + r.crew_location_deleted + '</strong> crew pings, '
                    + '<strong>' + r.visit_gps_deleted + '</strong> GPS points, '
                    + '<strong>' + r.audit_log_deleted + '</strong> audit rows deleted.';
            } else {
                el.className = 'alert alert-danger mb-3 small';
                el.textContent = d.error;
            }
        })
        .catch(function () {
            btn.disabled = false;
            btn.textContent = 'Run Purge Now';
        });
    };

    // ── Log Request tab ───────────────────────────────────────────────────────

    window.logRequest = function () {
        var type  = document.getElementById('logType').value;
        var name  = document.getElementById('logName').value.trim();
        var email = document.getElementById('logEmail').value.trim();
        var notes = document.getElementById('logNotes').value.trim();
        var alert = document.getElementById('logRequestAlert');

        if (!name || !email) {
            alert.className = 'alert alert-danger mb-3';
            alert.textContent = 'Name and email are required.';
            alert.style.display = 'block';
            return;
        }

        fetch(API, {
            method: 'POST',
            credentials: 'same-origin',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ csrf_token: CSRF, action: 'create_request', request_type: type, requester_name: name, requester_email: email, notes: notes }),
        })
        .then(function (r) { return r.json(); })
        .then(function (d) {
            if (d.success) {
                alert.className = 'alert alert-success mb-3';
                alert.textContent = 'Request #' + d.id + ' logged. Switching to DSAR tab.';
                alert.style.display = 'block';
                document.getElementById('logName').value = '';
                document.getElementById('logEmail').value = '';
                document.getElementById('logNotes').value = '';
                setTimeout(function () {
                    $('#requests-tab').tab('show');
                    document.getElementById('statusFilter').value = 'pending';
                    loadRequests(1);
                }, 1200);
            } else {
                alert.className = 'alert alert-danger mb-3';
                alert.textContent = d.error;
                alert.style.display = 'block';
            }
        });
    };

    // ── Helpers ───────────────────────────────────────────────────────────────

    function esc(s) {
        var d = document.createElement('div');
        d.textContent = s || '';
        return d.innerHTML;
    }

    // ── Init ──────────────────────────────────────────────────────────────────

    document.addEventListener('DOMContentLoaded', function () {
        loadRequests(1);
        document.getElementById('retention-tab').addEventListener('shown.bs.tab', function () {
            if (document.getElementById('retentionLoading').style.display !== 'none') {
                loadRetention();
            }
        });
    });
}());
</script>

<?php include __DIR__ . '/includes/appstack_footer.php'; ?>

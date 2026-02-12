<?php
require_once dirname(__DIR__) . '/../loginAuth/auth.php';
require_once dirname(__DIR__) . '/includes/functions.php';

requireLogin();
$user = getCurrentUser();

$pageTitle = 'Weather Actions';
$activePage = 'weather-ops';
?>
<?php include dirname(__DIR__) . '/includes/appstack_head.php'; ?>

          <!-- Page Header -->
          <div class="mw-page-header d-flex justify-content-between align-items-start">
            <div>
              <h1 class="h3 mb-0"><i data-feather="cloud-lightning" style="width:28px;height:28px;"></i> Weather Action List</h1>
              <p class="text-muted mb-0">Visits flagged by weather — approve moves, keep as-is, or override</p>
            </div>
            <div>
              <button class="btn btn-outline-primary mr-2" onclick="runWeatherGuard()" id="runGuardBtn">
                <i data-feather="refresh-cw"></i> Run Weather Check Now
              </button>
              <a href="/crm/admin/ops_weather.php" class="btn btn-outline-secondary">
                <i data-feather="settings"></i> Settings
              </a>
            </div>
          </div>

          <!-- Summary Cards -->
          <div class="row mb-3" id="summaryCards">
            <div class="col-md-3">
              <div class="card">
                <div class="card-body text-center">
                  <h2 class="mb-0" id="countNotOk" style="color:#dc3545;">—</h2>
                  <small class="text-muted">NOT OK</small>
                </div>
              </div>
            </div>
            <div class="col-md-3">
              <div class="card">
                <div class="card-body text-center">
                  <h2 class="mb-0" id="countBorderline" style="color:#e85d04;">—</h2>
                  <small class="text-muted">BORDERLINE</small>
                </div>
              </div>
            </div>
            <div class="col-md-3">
              <div class="card">
                <div class="card-body text-center">
                  <h2 class="mb-0" id="countTotal" style="color:#333;">—</h2>
                  <small class="text-muted">Total Flagged</small>
                </div>
              </div>
            </div>
            <div class="col-md-3">
              <div class="card">
                <div class="card-body text-center">
                  <h2 class="mb-0" id="lastRun" style="color:var(--mw-green);font-size:0.9rem;">—</h2>
                  <small class="text-muted">Last Check</small>
                </div>
              </div>
            </div>
          </div>

          <!-- Action List Table -->
          <div class="card">
            <div class="card-header d-flex justify-content-between align-items-center">
              <h5 class="card-title mb-0">Flagged Visits</h5>
              <div class="d-flex" style="gap:0.5rem;">
                <input type="date" class="form-control form-control-sm" id="filterFrom" style="width:auto;">
                <input type="date" class="form-control form-control-sm" id="filterTo" style="width:auto;">
                <button class="btn btn-sm btn-outline-primary" onclick="loadActions()">Filter</button>
              </div>
            </div>
            <div class="card-body p-0">
              <div class="table-responsive">
                <table class="table table-hover mb-0">
                  <thead>
                    <tr>
                      <th>Visit</th>
                      <th>Date</th>
                      <th>Property</th>
                      <th>Service</th>
                      <th>Status</th>
                      <th>Reason</th>
                      <th>Worst Hour</th>
                      <th>Actions</th>
                    </tr>
                  </thead>
                  <tbody id="actionsBody">
                    <tr><td colspan="8" class="text-center text-muted py-4">Loading...</td></tr>
                  </tbody>
                </table>
              </div>
            </div>
          </div>

          <!-- Recent Log -->
          <div class="card mt-3">
            <div class="card-header">
              <h5 class="card-title mb-0">Recent Weather Actions Log</h5>
            </div>
            <div class="card-body p-0">
              <div class="table-responsive" style="max-height:300px;overflow-y:auto;">
                <table class="table table-sm mb-0">
                  <thead>
                    <tr>
                      <th>Time</th>
                      <th>Action</th>
                      <th>Entity</th>
                      <th>Details</th>
                    </tr>
                  </thead>
                  <tbody id="logBody">
                    <tr><td colspan="4" class="text-center text-muted py-3">—</td></tr>
                  </tbody>
                </table>
              </div>
            </div>
          </div>

          <script>
          document.addEventListener('DOMContentLoaded', function() {
            // Set default date range
            const today = new Date().toISOString().split('T')[0];
            const nextWeek = new Date(Date.now() + 7 * 86400000).toISOString().split('T')[0];
            document.getElementById('filterFrom').value = today;
            document.getElementById('filterTo').value = nextWeek;

            loadActions();
          });

          function loadActions() {
            const from = document.getElementById('filterFrom').value;
            const to = document.getElementById('filterTo').value;

            fetch('/crm/api/weather-actions.php?action=list&from=' + from + '&to=' + to)
              .then(r => r.json())
              .then(data => {
                if (data.success) {
                  renderActions(data.visits || []);
                  renderLog(data.logs || []);
                  updateSummary(data.visits || []);
                }
              })
              .catch(err => console.error('Error loading actions:', err));
          }

          function renderActions(visits) {
            const tbody = document.getElementById('actionsBody');
            if (visits.length === 0) {
              tbody.innerHTML = '<tr><td colspan="8" class="text-center text-muted py-4">No flagged visits — all clear!</td></tr>';
              return;
            }

            tbody.innerHTML = visits.map(v => {
              const statusBadge = v.weather_status === 'NOT_OK'
                ? '<span class="badge badge-danger">NOT OK</span>'
                : '<span class="badge badge-warning">BORDERLINE</span>';

              const worstHour = v.worst_hour
                ? (v.worst_hour.hour ? v.worst_hour.hour.substring(11, 16) : '—') +
                  ' (' + (v.worst_hour.precip_chance_pct || 0) + '% / ' +
                  (v.worst_hour.wind_kph || 0) + 'km/h / ' +
                  (v.worst_hour.temp_c || 0) + '°C)'
                : '—';

              const crewName = v.crew_first_name ? v.crew_first_name + ' ' + (v.crew_last_name || '') : '—';

              return '<tr>' +
                '<td><strong>' + escapeHtml(v.visit_number) + '</strong><br><small class="text-muted">' + escapeHtml(crewName) + '</small></td>' +
                '<td>' + v.scheduled_date + '<br><small>' + (v.scheduled_time_start || '') + '</small></td>' +
                '<td>' + escapeHtml(v.address_line1 || '') + '<br><small class="text-muted">' + escapeHtml(v.city || '') + '</small></td>' +
                '<td>' + escapeHtml(v.service_name || v.service_type || '—') + '<br><small class="text-muted">' + (v.weather_policy || 'ANY') + '</small></td>' +
                '<td>' + statusBadge + '</td>' +
                '<td>' + escapeHtml(v.weather_reason || '—') + '<br><small class="text-muted">' + escapeHtml(v.snapshot_summary || '') + '</small></td>' +
                '<td><small>' + worstHour + '</small></td>' +
                '<td>' +
                  '<div class="btn-group-vertical btn-group-sm">' +
                    '<button class="btn btn-outline-success" onclick="keepVisit(' + v.visit_id + ')" title="Keep as-is"><i data-feather="check"></i> Keep</button>' +
                    '<button class="btn btn-outline-danger" onclick="dismissVisit(' + v.visit_id + ')" title="Dismiss"><i data-feather="x"></i> Dismiss</button>' +
                  '</div>' +
                  (v.weather_card_path ? '<br><a href="/crm/' + escapeHtml(v.weather_card_path) + '" target="_blank" class="btn btn-sm btn-outline-secondary mt-1"><i data-feather="image"></i></a>' : '') +
                '</td>' +
              '</tr>';
            }).join('');

            if (typeof feather !== 'undefined') feather.replace();
          }

          function renderLog(logs) {
            const tbody = document.getElementById('logBody');
            if (logs.length === 0) {
              tbody.innerHTML = '<tr><td colspan="4" class="text-center text-muted py-3">No recent actions</td></tr>';
              return;
            }

            tbody.innerHTML = logs.map(l => {
              let details = l.details;
              try { details = JSON.stringify(JSON.parse(l.details), null, 0); } catch(e) {}
              if (details && details.length > 80) details = details.substring(0, 77) + '...';

              return '<tr>' +
                '<td><small>' + l.created_at + '</small></td>' +
                '<td><span class="badge badge-secondary">' + escapeHtml(l.action_type) + '</span></td>' +
                '<td>' + l.entity_type + ' #' + l.entity_id + '</td>' +
                '<td><small class="text-muted">' + escapeHtml(details || '') + '</small></td>' +
              '</tr>';
            }).join('');
          }

          function updateSummary(visits) {
            const notOk = visits.filter(v => v.weather_status === 'NOT_OK').length;
            const borderline = visits.filter(v => v.weather_status === 'BORDERLINE').length;
            document.getElementById('countNotOk').textContent = notOk;
            document.getElementById('countBorderline').textContent = borderline;
            document.getElementById('countTotal').textContent = visits.length;
          }

          function keepVisit(visitId) {
            if (!confirm('Keep this visit despite weather concerns?')) return;

            fetch('/crm/api/weather-actions.php?action=keep', {
              method: 'POST',
              headers: { 'Content-Type': 'application/json' },
              body: JSON.stringify({ visit_id: visitId, reason: 'Manual override' })
            })
            .then(r => r.json())
            .then(data => {
              if (data.success) loadActions();
              else alert('Error: ' + (data.error || 'Unknown'));
            })
            .catch(err => alert('Error: ' + err.message));
          }

          function dismissVisit(visitId) {
            fetch('/crm/api/weather-actions.php?action=dismiss', {
              method: 'POST',
              headers: { 'Content-Type': 'application/json' },
              body: JSON.stringify({ visit_id: visitId })
            })
            .then(r => r.json())
            .then(data => {
              if (data.success) loadActions();
              else alert('Error: ' + (data.error || 'Unknown'));
            })
            .catch(err => alert('Error: ' + err.message));
          }

          function runWeatherGuard() {
            const btn = document.getElementById('runGuardBtn');
            btn.disabled = true;
            btn.innerHTML = '<i data-feather="loader"></i> Running...';

            fetch('/crm/api/weather-actions.php?action=run-guard', {
              method: 'POST',
              headers: { 'Content-Type': 'application/json' },
              body: '{}'
            })
            .then(r => r.json())
            .then(data => {
              btn.disabled = false;
              btn.innerHTML = '<i data-feather="refresh-cw"></i> Run Weather Check Now';
              if (typeof feather !== 'undefined') feather.replace();

              if (data.success) {
                const msg = 'Guard complete: ' + (data.evaluated || 0) + ' evaluated, ' +
                  (data.ok || 0) + ' OK, ' + (data.not_ok || 0) + ' NOT_OK, ' +
                  (data.borderline || 0) + ' borderline, ' +
                  (data.auto_moved || 0) + ' auto-moved';
                alert(msg);
                document.getElementById('lastRun').textContent = data.run_at || 'Just now';
                loadActions();
              } else {
                alert('Error: ' + (data.error || 'Unknown'));
              }
            })
            .catch(err => {
              btn.disabled = false;
              btn.innerHTML = '<i data-feather="refresh-cw"></i> Run Weather Check Now';
              alert('Error: ' + err.message);
            });
          }

          function escapeHtml(text) {
            if (!text) return '';
            const div = document.createElement('div');
            div.textContent = text;
            return div.innerHTML;
          }
          </script>

<?php include dirname(__DIR__) . '/includes/appstack_footer.php'; ?>

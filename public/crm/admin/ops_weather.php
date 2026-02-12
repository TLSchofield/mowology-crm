<?php
require_once dirname(__DIR__) . '/../loginAuth/auth.php';
require_once dirname(__DIR__) . '/includes/functions.php';

requireLogin();
$user = getCurrentUser();

if (($user['role'] ?? '') !== 'admin') {
    header('Location: /crm/dashboard_appstack.php');
    exit;
}

$pageTitle = 'Ops Weather Constraints';
$activePage = 'ops-weather';
?>
<?php include dirname(__DIR__) . '/includes/appstack_head.php'; ?>

          <!-- Page Header -->
          <div class="mw-page-header">
            <div>
              <h1 class="h3 mb-0"><i data-feather="cloud-rain" style="width:28px;height:28px;"></i> Weather Operations Settings</h1>
              <p class="text-muted mb-0">Global weather thresholds, borderline bands, and reschedule defaults</p>
            </div>
          </div>

          <!-- Tabs -->
          <ul class="nav nav-tabs mb-3" role="tablist">
            <li class="nav-item">
              <a class="nav-link active" data-toggle="tab" href="#tab-global">Global Defaults</a>
            </li>
            <li class="nav-item">
              <a class="nav-link" data-toggle="tab" href="#tab-services">Service Weather Rules</a>
            </li>
          </ul>

          <div class="tab-content">
            <!-- Tab 1: Global Defaults -->
            <div class="tab-pane fade show active" id="tab-global" role="tabpanel">
              <div class="card">
                <div class="card-header">
                  <h5 class="card-title mb-0">Default Weather Thresholds</h5>
                </div>
                <div class="card-body">
                  <form id="globalConstraintsForm">
                    <div class="row">
                      <div class="col-md-6">
                        <h6 class="mb-3" style="color:var(--mw-green);">Precipitation</h6>
                        <div class="form-group">
                          <label>Default Max Precip Chance (%)</label>
                          <input type="number" class="form-control" id="gc_max_precip_chance" min="0" max="100" step="5">
                          <small class="form-text text-muted">Visits with precip chance above this are flagged NOT_OK</small>
                        </div>
                        <div class="form-group">
                          <label>Default Max Precip mm/hr</label>
                          <input type="number" class="form-control" id="gc_max_precip_mm" min="0" max="50" step="0.5">
                          <small class="form-text text-muted">Hourly precipitation threshold (primary metric)</small>
                        </div>
                        <div class="form-group">
                          <label>Borderline Band — Low (%)</label>
                          <input type="number" class="form-control" id="gc_borderline_low" min="0" max="100" step="5">
                          <small class="form-text text-muted">Below this = OK, above = uncertain zone</small>
                        </div>
                        <div class="form-group">
                          <label>Borderline Band — High (%)</label>
                          <input type="number" class="form-control" id="gc_borderline_high" min="0" max="100" step="5">
                          <small class="form-text text-muted">Above this = NOT_OK, between low and high = BORDERLINE</small>
                        </div>
                      </div>
                      <div class="col-md-6">
                        <h6 class="mb-3" style="color:var(--mw-green);">Temperature &amp; Wind</h6>
                        <div class="form-group">
                          <label>Default Min Temperature (°C)</label>
                          <input type="number" class="form-control" id="gc_min_temp" min="-40" max="50" step="1">
                        </div>
                        <div class="form-group">
                          <label>Default Max Temperature (°C)</label>
                          <input type="number" class="form-control" id="gc_max_temp" min="-40" max="50" step="1">
                        </div>
                        <div class="form-group">
                          <label>Default Max Wind (km/h)</label>
                          <input type="number" class="form-control" id="gc_max_wind" min="0" max="200" step="5">
                        </div>
                      </div>
                    </div>

                    <hr>

                    <h6 class="mb-3" style="color:var(--mw-green);">Reschedule Behaviour</h6>
                    <div class="row">
                      <div class="col-md-4">
                        <div class="form-group">
                          <label>Default Move Window (hours)</label>
                          <input type="number" class="form-control" id="gc_move_window" min="1" max="168" step="1">
                          <small class="form-text text-muted">How far forward to search for alternate slots</small>
                        </div>
                      </div>
                      <div class="col-md-4">
                        <div class="form-group">
                          <label>Default Timeband Start</label>
                          <input type="time" class="form-control" id="gc_timeband_start">
                        </div>
                      </div>
                      <div class="col-md-4">
                        <div class="form-group">
                          <label>Default Timeband End</label>
                          <input type="time" class="form-control" id="gc_timeband_end">
                        </div>
                      </div>
                    </div>

                    <div class="row">
                      <div class="col-md-4">
                        <div class="form-group">
                          <label>Decision Time</label>
                          <input type="time" class="form-control" id="gc_decision_time">
                          <small class="form-text text-muted">When the daily weather check runs</small>
                        </div>
                      </div>
                      <div class="col-md-4">
                        <div class="form-group">
                          <label>Lookahead Days</label>
                          <input type="number" class="form-control" id="gc_lookahead_days" min="1" max="7" step="1">
                          <small class="form-text text-muted">How many days ahead to evaluate</small>
                        </div>
                      </div>
                      <div class="col-md-4">
                        <div class="form-group mt-4">
                          <label class="d-flex align-items-center" style="gap:0.5rem;">
                            <input type="checkbox" id="gc_auto_reschedule"> Enable Auto-Reschedule (globally)
                          </label>
                          <label class="d-flex align-items-center mt-2" style="gap:0.5rem;">
                            <input type="checkbox" id="gc_swap_suggestions"> Enable Swap Suggestions
                          </label>
                        </div>
                      </div>
                    </div>

                    <div class="mt-3">
                      <button type="button" class="btn btn-primary" onclick="saveGlobalConstraints()">
                        <i data-feather="save"></i> Save Global Defaults
                      </button>
                      <span id="globalSaveStatus" class="ml-3 text-muted"></span>
                    </div>
                  </form>
                </div>
              </div>
            </div>

            <!-- Tab 2: Service Weather Rules -->
            <div class="tab-pane fade" id="tab-services" role="tabpanel">
              <div class="card">
                <div class="card-header d-flex justify-content-between align-items-center">
                  <h5 class="card-title mb-0">Service Package Weather Rules</h5>
                  <small class="text-muted">Per-service overrides — NULL fields inherit global defaults</small>
                </div>
                <div class="card-body p-0">
                  <div class="table-responsive">
                    <table class="table table-hover mb-0" id="serviceRulesTable">
                      <thead>
                        <tr>
                          <th>Service</th>
                          <th>Category</th>
                          <th>Policy</th>
                          <th>Max Precip %</th>
                          <th>Max mm/hr</th>
                          <th>Temp Range</th>
                          <th>Max Wind</th>
                          <th>Auto</th>
                          <th>Actions</th>
                        </tr>
                      </thead>
                      <tbody id="serviceRulesBody">
                        <tr><td colspan="9" class="text-center text-muted py-4">Loading service packages...</td></tr>
                      </tbody>
                    </table>
                  </div>
                </div>
              </div>

              <!-- Edit Service Weather Modal -->
              <div class="modal fade" id="editServiceWeatherModal" tabindex="-1" role="dialog">
                <div class="modal-dialog" role="document">
                  <div class="modal-content">
                    <div class="modal-header">
                      <h5 class="modal-title" id="editServiceModalTitle">Edit Weather Rules</h5>
                      <button type="button" class="close" data-dismiss="modal"><span>&times;</span></button>
                    </div>
                    <div class="modal-body">
                      <form id="serviceWeatherForm">
                        <input type="hidden" id="sw_id">
                        <div class="form-group">
                          <label>Weather Policy</label>
                          <select class="form-control" id="sw_policy" onchange="togglePolicyFields()">
                            <option value="ANY">ANY — All conditions OK</option>
                            <option value="DRY_ONLY">DRY_ONLY — No rain allowed</option>
                            <option value="LIGHT_RAIN_OK">LIGHT_RAIN_OK — Light rain is fine</option>
                            <option value="TEMP_LIMITED">TEMP_LIMITED — Temperature sensitive</option>
                            <option value="WIND_LIMITED">WIND_LIMITED — Wind sensitive</option>
                            <option value="CUSTOM">CUSTOM — Custom thresholds</option>
                          </select>
                        </div>

                        <div id="sw_thresholds">
                          <div class="row">
                            <div class="col-md-6">
                              <div class="form-group">
                                <label>Max Precip Chance (%)</label>
                                <input type="number" class="form-control" id="sw_precip_chance" min="0" max="100" step="5" placeholder="Use global">
                              </div>
                            </div>
                            <div class="col-md-6">
                              <div class="form-group">
                                <label>Max Precip mm/hr</label>
                                <input type="number" class="form-control" id="sw_precip_mm" min="0" max="50" step="0.5" placeholder="Use global">
                              </div>
                            </div>
                          </div>
                          <div class="row">
                            <div class="col-md-6">
                              <div class="form-group">
                                <label>Min Temp (°C)</label>
                                <input type="number" class="form-control" id="sw_min_temp" min="-40" max="50" placeholder="Use global">
                              </div>
                            </div>
                            <div class="col-md-6">
                              <div class="form-group">
                                <label>Max Temp (°C)</label>
                                <input type="number" class="form-control" id="sw_max_temp" min="-40" max="50" placeholder="Use global">
                              </div>
                            </div>
                          </div>
                          <div class="form-group">
                            <label>Max Wind (km/h)</label>
                            <input type="number" class="form-control" id="sw_max_wind" min="0" max="200" step="5" placeholder="Use global">
                          </div>
                        </div>

                        <hr>
                        <h6>Reschedule Controls</h6>
                        <div class="row">
                          <div class="col-md-4">
                            <div class="form-group">
                              <label>Move Window (hrs)</label>
                              <input type="number" class="form-control" id="sw_move_window" min="1" max="168" placeholder="Use global">
                            </div>
                          </div>
                          <div class="col-md-4">
                            <div class="form-group">
                              <label>Timeband Start</label>
                              <input type="time" class="form-control" id="sw_timeband_start">
                            </div>
                          </div>
                          <div class="col-md-4">
                            <div class="form-group">
                              <label>Timeband End</label>
                              <input type="time" class="form-control" id="sw_timeband_end">
                            </div>
                          </div>
                        </div>
                        <div class="form-group">
                          <label class="d-flex align-items-center" style="gap:0.5rem;">
                            <input type="checkbox" id="sw_auto_reschedule"> Auto-Reschedule
                          </label>
                        </div>
                        <div class="form-group">
                          <label class="d-flex align-items-center" style="gap:0.5rem;">
                            <input type="checkbox" id="sw_manual_uncertain" checked> Require Manual Approval on Borderline
                          </label>
                        </div>

                        <div class="mw-weather-preview p-3 mt-3" style="background:var(--mw-light);border-radius:6px;" id="sw_preview">
                          <!-- Preview line generated by JS -->
                        </div>
                      </form>
                    </div>
                    <div class="modal-footer">
                      <button class="btn btn-secondary" data-dismiss="modal">Cancel</button>
                      <button class="btn btn-primary" onclick="saveServiceWeatherRules()">Save Rules</button>
                    </div>
                  </div>
                </div>
              </div>
            </div>
          </div>

          <script>
          // ============================================================
          // Global Constraints
          // ============================================================
          let globalConstraints = {};

          document.addEventListener('DOMContentLoaded', function() {
            loadGlobalConstraints();
            loadServiceRules();
          });

          function loadGlobalConstraints() {
            fetch('/crm/api/ops-settings.php?action=get&key=weather_ops_constraints')
              .then(r => r.json())
              .then(data => {
                if (data.success && data.value) {
                  globalConstraints = data.value;
                  populateGlobalForm(globalConstraints);
                }
              })
              .catch(err => console.error('Error loading constraints:', err));
          }

          function populateGlobalForm(c) {
            document.getElementById('gc_max_precip_chance').value = c.default_max_precip_chance_pct ?? 50;
            document.getElementById('gc_max_precip_mm').value = c.default_max_precip_mm_per_hr ?? 2.5;
            document.getElementById('gc_borderline_low').value = c.borderline_precip_chance_low ?? 30;
            document.getElementById('gc_borderline_high').value = c.borderline_precip_chance_high ?? 50;
            document.getElementById('gc_min_temp').value = c.default_min_temp_c ?? -5;
            document.getElementById('gc_max_temp').value = c.default_max_temp_c ?? 40;
            document.getElementById('gc_max_wind').value = c.default_max_wind_kph ?? 50;
            document.getElementById('gc_move_window').value = c.default_move_window_hours ?? 48;
            document.getElementById('gc_timeband_start').value = c.default_timeband_start ?? '07:00';
            document.getElementById('gc_timeband_end').value = c.default_timeband_end ?? '18:00';
            document.getElementById('gc_decision_time').value = c.decision_time ?? '12:00';
            document.getElementById('gc_lookahead_days').value = c.lookahead_days ?? 2;
            document.getElementById('gc_auto_reschedule').checked = !!c.auto_reschedule_enabled;
            document.getElementById('gc_swap_suggestions').checked = c.swap_suggestions_enabled !== false;
          }

          function saveGlobalConstraints() {
            const constraints = {
              default_max_precip_chance_pct: parseInt(document.getElementById('gc_max_precip_chance').value) || 50,
              default_max_precip_mm_per_hr: parseFloat(document.getElementById('gc_max_precip_mm').value) || 2.5,
              borderline_precip_chance_low: parseInt(document.getElementById('gc_borderline_low').value) || 30,
              borderline_precip_chance_high: parseInt(document.getElementById('gc_borderline_high').value) || 50,
              default_min_temp_c: parseFloat(document.getElementById('gc_min_temp').value),
              default_max_temp_c: parseFloat(document.getElementById('gc_max_temp').value),
              default_max_wind_kph: parseFloat(document.getElementById('gc_max_wind').value) || 50,
              default_move_window_hours: parseInt(document.getElementById('gc_move_window').value) || 48,
              default_timeband_start: document.getElementById('gc_timeband_start').value || '07:00',
              default_timeband_end: document.getElementById('gc_timeband_end').value || '18:00',
              decision_time: document.getElementById('gc_decision_time').value || '12:00',
              lookahead_days: parseInt(document.getElementById('gc_lookahead_days').value) || 2,
              auto_reschedule_enabled: document.getElementById('gc_auto_reschedule').checked,
              swap_suggestions_enabled: document.getElementById('gc_swap_suggestions').checked,
            };

            fetch('/crm/api/ops-settings.php?action=save', {
              method: 'POST',
              headers: { 'Content-Type': 'application/json' },
              body: JSON.stringify({
                key: 'weather_ops_constraints',
                value: constraints,
                description: 'Weather operations constraints: thresholds, borderline bands, reschedule defaults'
              })
            })
            .then(r => r.json())
            .then(data => {
              const status = document.getElementById('globalSaveStatus');
              if (data.success) {
                status.textContent = 'Saved!';
                status.style.color = 'var(--mw-green)';
              } else {
                status.textContent = 'Error: ' + (data.error || 'Unknown');
                status.style.color = '#dc3545';
              }
              setTimeout(() => { status.textContent = ''; }, 3000);
            })
            .catch(err => alert('Error: ' + err.message));
          }

          // ============================================================
          // Service Weather Rules
          // ============================================================
          let allServicePackages = [];

          function loadServiceRules() {
            fetch('/crm/api/ops-settings.php?action=get-service-weather-rules')
              .then(r => r.json())
              .then(data => {
                if (data.success) {
                  allServicePackages = data.packages;
                  renderServiceTable();
                }
              })
              .catch(err => console.error('Error loading service rules:', err));
          }

          function renderServiceTable() {
            const tbody = document.getElementById('serviceRulesBody');
            if (allServicePackages.length === 0) {
              tbody.innerHTML = '<tr><td colspan="9" class="text-center text-muted py-4">No service packages found</td></tr>';
              return;
            }

            tbody.innerHTML = allServicePackages.map(sp => {
              const policy = sp.weather_policy || 'ANY';
              const policyClass = policy === 'ANY' ? 'badge-secondary' :
                                  policy === 'DRY_ONLY' ? 'badge-warning' :
                                  policy === 'CUSTOM' ? 'badge-info' : 'badge-primary';
              const tempRange = (sp.min_temp_c !== null || sp.max_temp_c !== null)
                ? (sp.min_temp_c ?? '?') + '° to ' + (sp.max_temp_c ?? '?') + '°'
                : '—';

              return '<tr>' +
                '<td><strong>' + escapeHtml(sp.package_name) + '</strong>' +
                  (!sp.is_active ? ' <span class="badge badge-secondary">Inactive</span>' : '') +
                '</td>' +
                '<td>' + escapeHtml(sp.category || '—') + '</td>' +
                '<td><span class="badge ' + policyClass + '">' + policy + '</span></td>' +
                '<td>' + (sp.max_precip_chance_pct !== null ? sp.max_precip_chance_pct + '%' : '—') + '</td>' +
                '<td>' + (sp.max_precip_mm_per_hr !== null ? sp.max_precip_mm_per_hr : '—') + '</td>' +
                '<td>' + tempRange + '</td>' +
                '<td>' + (sp.max_wind_kph !== null ? sp.max_wind_kph + ' km/h' : '—') + '</td>' +
                '<td>' + (sp.auto_reschedule ? '<span class="text-success">Yes</span>' : '<span class="text-muted">No</span>') + '</td>' +
                '<td><button class="btn btn-sm btn-outline-primary" onclick="editServiceWeather(' + sp.id + ')"><i data-feather="edit-2"></i></button></td>' +
              '</tr>';
            }).join('');

            if (typeof feather !== 'undefined') feather.replace();
          }

          function editServiceWeather(id) {
            const sp = allServicePackages.find(s => s.id == id);
            if (!sp) return;

            document.getElementById('editServiceModalTitle').textContent = 'Weather Rules: ' + sp.package_name;
            document.getElementById('sw_id').value = sp.id;
            document.getElementById('sw_policy').value = sp.weather_policy || 'ANY';
            document.getElementById('sw_precip_chance').value = sp.max_precip_chance_pct ?? '';
            document.getElementById('sw_precip_mm').value = sp.max_precip_mm_per_hr ?? '';
            document.getElementById('sw_min_temp').value = sp.min_temp_c ?? '';
            document.getElementById('sw_max_temp').value = sp.max_temp_c ?? '';
            document.getElementById('sw_max_wind').value = sp.max_wind_kph ?? '';
            document.getElementById('sw_move_window').value = sp.move_window_hours ?? '';
            document.getElementById('sw_timeband_start').value = sp.move_timeband_start ?? '';
            document.getElementById('sw_timeband_end').value = sp.move_timeband_end ?? '';
            document.getElementById('sw_auto_reschedule').checked = !!parseInt(sp.auto_reschedule);
            document.getElementById('sw_manual_uncertain').checked = parseInt(sp.require_manual_if_uncertain) !== 0;

            togglePolicyFields();
            updatePreview();
            $('#editServiceWeatherModal').modal('show');
          }

          function togglePolicyFields() {
            const policy = document.getElementById('sw_policy').value;
            const show = policy !== 'ANY';
            document.getElementById('sw_thresholds').style.display = show ? 'block' : 'none';
            updatePreview();
          }

          function updatePreview() {
            const policy = document.getElementById('sw_policy').value;
            const preview = document.getElementById('sw_preview');
            let parts = [policy.replace(/_/g, ' ')];

            if (policy !== 'ANY') {
              const pc = document.getElementById('sw_precip_chance').value;
              const mm = document.getElementById('sw_precip_mm').value;
              const mt = document.getElementById('sw_min_temp').value;
              const xt = document.getElementById('sw_max_temp').value;
              const w = document.getElementById('sw_max_wind').value;
              if (pc) parts.push('max ' + pc + '% precip');
              if (mm) parts.push('max ' + mm + 'mm/hr');
              if (mt) parts.push('min ' + mt + '°C');
              if (xt) parts.push('max ' + xt + '°C');
              if (w) parts.push('max ' + w + ' km/h wind');
            }

            preview.innerHTML = '<small class="text-muted">Preview:</small> <strong>' + parts.join(', ') + '</strong>';
          }

          // Bind preview updates
          document.addEventListener('DOMContentLoaded', function() {
            ['sw_policy','sw_precip_chance','sw_precip_mm','sw_min_temp','sw_max_temp','sw_max_wind'].forEach(function(id) {
              var el = document.getElementById(id);
              if (el) el.addEventListener('input', updatePreview);
            });
          });

          function saveServiceWeatherRules() {
            const data = {
              id: parseInt(document.getElementById('sw_id').value),
              weather_policy: document.getElementById('sw_policy').value,
              max_precip_chance_pct: document.getElementById('sw_precip_chance').value || null,
              max_precip_mm_per_hr: document.getElementById('sw_precip_mm').value || null,
              min_temp_c: document.getElementById('sw_min_temp').value !== '' ? document.getElementById('sw_min_temp').value : null,
              max_temp_c: document.getElementById('sw_max_temp').value !== '' ? document.getElementById('sw_max_temp').value : null,
              max_wind_kph: document.getElementById('sw_max_wind').value || null,
              move_window_hours: document.getElementById('sw_move_window').value || null,
              move_timeband_start: document.getElementById('sw_timeband_start').value || null,
              move_timeband_end: document.getElementById('sw_timeband_end').value || null,
              auto_reschedule: document.getElementById('sw_auto_reschedule').checked ? 1 : 0,
              require_manual_if_uncertain: document.getElementById('sw_manual_uncertain').checked ? 1 : 0,
            };

            fetch('/crm/api/ops-settings.php?action=save-service-weather-rules', {
              method: 'POST',
              headers: { 'Content-Type': 'application/json' },
              body: JSON.stringify(data)
            })
            .then(r => r.json())
            .then(result => {
              if (result.success) {
                $('#editServiceWeatherModal').modal('hide');
                loadServiceRules();
              } else {
                alert('Error: ' + (result.error || 'Unknown'));
              }
            })
            .catch(err => alert('Error: ' + err.message));
          }

          function escapeHtml(text) {
            if (!text) return '';
            const div = document.createElement('div');
            div.textContent = text;
            return div.innerHTML;
          }
          </script>

<?php include dirname(__DIR__) . '/includes/appstack_footer.php'; ?>

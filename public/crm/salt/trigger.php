<?php
/**
 * Manual Salt Run Trigger
 * Emergency page for triggering a salt run outside the normal cron window.
 * Fetches live weather, shows preview, then dispatches crew alerts + captures
 * the authoritative weather decision record on confirmation.
 */
require_once dirname(__DIR__) . '/../loginAuth/auth.php';
require_once dirname(__DIR__) . '/includes/functions.php';

requireLogin();
$user = getCurrentUser();

if (($user['role'] ?? '') !== 'admin') {
    header('Location: ../dashboard_appstack.php');
    exit;
}

$pageTitle  = 'Manual Salt Run Trigger';
$activePage = 'jobs';
$csrfToken  = generateCSRFToken();
$tomorrow   = date('Y-m-d', strtotime('+1 day'));
$today      = date('Y-m-d');
?>
<?php include dirname(__DIR__) . '/includes/appstack_head.php'; ?>

<div class="row">
  <div class="col-12 col-lg-8">

    <!-- Header -->
    <div class="card mb-4" style="border-left:4px solid #1565C0;">
      <div class="card-body" style="padding:20px 24px;">
        <div class="d-flex align-items-center">
          <div style="background:#E3F2FD;border-radius:50%;width:44px;height:44px;display:flex;align-items:center;justify-content:center;margin-right:14px;flex-shrink:0;">
            <i data-feather="alert-triangle" style="width:20px;height:20px;color:#1565C0;"></i>
          </div>
          <div>
            <h4 class="mb-0" style="color:#0D3B2E;">Manual Salt Run Trigger</h4>
            <p class="mb-0 text-muted" style="font-size:13px;margin-top:2px;">Use this when a sudden cold snap requires an unscheduled salt run. Fetches a live weather snapshot, creates emergency visits for all active winter-service properties, and alerts crew by SMS.</p>
          </div>
        </div>
      </div>
    </div>

    <!-- Step 1: Configure -->
    <div class="card mb-3" id="configCard">
      <div class="card-header">
        <h5 class="card-title mb-0">
          <span class="badge badge-primary mr-2" style="background:#0D3B2E;">1</span>
          Service Date &amp; Conditions
        </h5>
      </div>
      <div class="card-body">
        <div class="mw-form-row">
          <label class="mw-form-label">Service date <span style="color:#dc3545;">*</span></label>
          <div class="mw-form-field">
            <input type="date" id="serviceDate" class="form-control" value="<?= htmlspecialchars($tomorrow) ?>"
                   min="<?= htmlspecialchars($today) ?>" max="<?= htmlspecialchars(date('Y-m-d', strtotime('+7 days'))) ?>">
            <small class="form-text text-muted">The date the salt run will be performed.</small>
          </div>
        </div>

        <div class="mw-form-row mt-3">
          <label class="mw-form-label">Manual temp override <small class="text-muted">(optional)</small></label>
          <div class="mw-form-field">
            <div class="input-group" style="max-width:200px;">
              <input type="number" id="manualTemp" class="form-control" placeholder="Auto from EC forecast" step="0.5" min="-40" max="10">
              <div class="input-group-append"><span class="input-group-text">°C</span></div>
            </div>
            <small class="form-text text-muted">Leave blank to use Environment Canada forecast. Enter a value when you know conditions are bad regardless of the official forecast (e.g. black ice visible, freezing rain started).</small>
          </div>
        </div>

        <div class="mw-form-row mt-3" id="manualNotesRow" style="display:none;">
          <label class="mw-form-label">Reason for override</label>
          <div class="mw-form-field">
            <input type="text" id="manualNotes" class="form-control" placeholder="e.g. Black ice observed on-site, freezing rain started at 8pm" maxlength="200">
            <small class="form-text text-muted">This is stored in the official weather decision record alongside the temperature.</small>
          </div>
        </div>

        <div class="mt-3">
          <button id="previewBtn" class="btn btn-outline-primary">
            <i data-feather="eye" class="feather-sm mr-1"></i> Fetch Weather &amp; Preview
          </button>
        </div>
      </div>
    </div>

    <!-- Step 2: Preview (hidden until fetched) -->
    <div id="previewSection" style="display:none;">
      <div class="card mb-3" id="weatherCard">
        <div class="card-header" style="background:#E3F2FD;border-bottom:2px solid #1565C0;">
          <h5 class="card-title mb-0" style="color:#0D47A1;">
            <i data-feather="cloud-snow" class="feather-sm mr-2"></i>
            <span id="weatherCardTitle">Live Weather Snapshot</span>
          </h5>
        </div>
        <div class="card-body" id="weatherCardBody">
          <!-- populated by JS -->
        </div>
      </div>

      <div class="card mb-3">
        <div class="card-header">
          <h5 class="card-title mb-0">
            <span class="badge badge-primary mr-2" style="background:#0D3B2E;">2</span>
            Properties &amp; Crew to be Notified
          </h5>
        </div>
        <div class="card-body p-0">
          <div id="propertiesList"><!-- populated by JS --></div>
          <div id="crewList" class="px-3 pb-3"><!-- populated by JS --></div>
        </div>
      </div>

      <!-- Step 3: Confirm -->
      <div class="card mb-4 border-danger" id="confirmCard">
        <div class="card-header" style="background:#FFEBEE;">
          <h5 class="card-title mb-0" style="color:#C62828;">
            <span class="badge badge-danger mr-2">3</span>
            Confirm &amp; Trigger
          </h5>
        </div>
        <div class="card-body">
          <p class="text-muted" style="font-size:13px;">
            This will <strong>immediately send SMS alerts</strong> to all SMS-enabled crew members, capture an authoritative weather decision record, and create emergency visits for any properties that don't already have a visit on the selected date.
          </p>
          <div class="alert alert-warning py-2" style="font-size:12px;">
            <i data-feather="info" class="feather-sm mr-1"></i>
            Quiet hours are bypassed for manual triggers — crew will receive the SMS immediately.
          </div>
          <button id="triggerBtn" class="btn btn-danger btn-lg">
            <i data-feather="zap" class="feather-sm mr-2"></i> Trigger Salt Run Now
          </button>
          <button id="cancelBtn" class="btn btn-outline-secondary btn-lg ml-2">Cancel</button>
        </div>
      </div>
    </div>

    <!-- Result -->
    <div id="resultSection" style="display:none;">
      <div class="card mb-4" id="resultCard">
        <div class="card-body" id="resultBody"><!-- populated by JS --></div>
      </div>
    </div>

  </div>

  <!-- Sidebar info -->
  <div class="col-12 col-lg-4">
    <div class="card mb-3">
      <div class="card-header"><h6 class="card-title mb-0">When to use this</h6></div>
      <div class="card-body" style="font-size:12px;color:#555;line-height:1.7;">
        <p><strong>Normal trigger:</strong> The daily cron runs at 2pm and automatically detects freeze conditions for the following night. You don't need to do anything.</p>
        <p><strong>Use this page when:</strong></p>
        <ul>
          <li>Conditions changed after 2pm (temperature dropped unexpectedly)</li>
          <li>Black ice or freezing rain started during the evening</li>
          <li>A client called requesting an emergency salt run</li>
          <li>The cron missed a day and you need to trigger manually</li>
        </ul>
        <p class="mb-0"><strong>What it does:</strong> Fetches live EC forecast, locks in the weather snapshot as authoritative proof, creates emergency visits, and fires SMS alerts to all crew.</p>
      </div>
    </div>
    <div class="card mb-3">
      <div class="card-header"><h6 class="card-title mb-0">Weather Decision Record</h6></div>
      <div class="card-body" style="font-size:12px;color:#555;line-height:1.7;">
        <p>Every trigger — automated or manual — writes a <strong>salt_weather_decisions</strong> record. This captures:</p>
        <ul>
          <li>Exact temperature at decision time</li>
          <li>Full EC API response</li>
          <li>Who triggered it (you, or the cron)</li>
          <li>Whether a manual override was used and why</li>
        </ul>
        <p class="mb-0">This record is the legal foundation of the Winter Service Report PDF.</p>
      </div>
    </div>
    <a href="dashboard.php" class="btn btn-outline-secondary btn-block">
      <i data-feather="arrow-left" class="feather-sm mr-1"></i> Back to Salt Dashboard
    </a>
  </div>
</div>

<script>
(function () {
    const csrf = <?= json_encode($csrfToken) ?>;
    let previewData = null;

    const el = (id) => document.getElementById(id);
    const show = (id) => { const e = el(id); if (e) e.style.display = ''; };
    const hide = (id) => { const e = el(id); if (e) e.style.display = 'none'; };

    // Show manual notes field when temp override is entered
    el('manualTemp').addEventListener('input', function () {
        if (this.value.trim() !== '') {
            el('manualNotesRow').style.display = '';
        } else {
            el('manualNotesRow').style.display = 'none';
        }
    });

    // ── PREVIEW ──────────────────────────────────────────────────────────────
    el('previewBtn').addEventListener('click', async function () {
        const serviceDate = el('serviceDate').value;
        const manualTemp  = el('manualTemp').value.trim();
        const manualNotes = el('manualNotes') ? el('manualNotes').value.trim() : '';

        this.disabled = true;
        this.innerHTML = '<span class="spinner-border spinner-border-sm mr-2"></span>Fetching weather...';

        const fd = new FormData();
        fd.append('action', 'preview');
        fd.append('csrf_token', csrf);
        fd.append('service_date', serviceDate);
        if (manualTemp !== '') { fd.append('manual_temp', manualTemp); }
        if (manualNotes !== '') { fd.append('manual_notes', manualNotes); }

        try {
            const res  = await fetch('/crm/api/salt-manual-trigger.php', { method: 'POST', body: fd });
            const data = await res.json();
            previewData = data;

            if (!data.success) {
                alert('Preview error: ' + (data.error || 'Unknown error'));
                return;
            }

            renderPreview(data);
            show('previewSection');
            el('previewSection').scrollIntoView({ behavior: 'smooth', block: 'start' });
        } catch (e) {
            alert('Network error: ' + e.message);
        } finally {
            this.disabled = false;
            this.innerHTML = '<i data-feather="eye"></i> Fetch Weather &amp; Preview';
            if (window.feather) feather.replace();
        }
    });

    function renderPreview(data) {
        // Weather card
        const isOverride = data.is_manual_override;
        const temp = data.effective_temp !== null ? data.effective_temp.toFixed(1) : '?';
        const cond = data.effective_cond || 'Unknown';
        const triggerTemp = typeof data.trigger_temp === 'number' ? data.trigger_temp.toFixed(1) : '0.0';
        const meetsThreshold = data.effective_temp !== null && data.effective_temp <= parseFloat(triggerTemp);

        let weatherHtml = '';
        if (data.weather_error && !isOverride) {
            weatherHtml += '<div class="alert alert-warning py-2 mb-2"><i data-feather="alert-triangle" class="feather-sm mr-1"></i>' + escHtml(data.weather_error) + '</div>';
        }
        if (isOverride) {
            weatherHtml += '<div class="alert alert-warning py-2 mb-3" style="font-size:12px;"><strong>Manual temperature override active.</strong> The official forecast has been replaced with your entered value for this trigger.</div>';
        }
        weatherHtml += '<div style="display:grid;grid-template-columns:repeat(3,1fr);gap:12px;margin-bottom:16px;">';
        weatherHtml += statBlock('Forecast low', temp + '°C', meetsThreshold ? '#C62828' : '#2D8659');
        weatherHtml += statBlock('Service threshold', '≤' + triggerTemp + '°C', '#666');
        weatherHtml += statBlock('Condition', cond.length > 20 ? cond.substring(0, 20) + '…' : cond, '#444');
        weatherHtml += '</div>';
        weatherHtml += '<div style="font-size:11px;color:#666;">';
        weatherHtml += '<strong>Source:</strong> ' + (isOverride ? 'Manual override by admin' : 'Environment Canada — Vancouver, BC') + ' &bull; ';
        weatherHtml += '<strong>Captured:</strong> ' + new Date().toLocaleTimeString('en-CA');
        weatherHtml += '</div>';

        el('weatherCardBody').innerHTML = weatherHtml;
        el('weatherCardTitle').textContent = 'Live Weather Snapshot — ' + formatDate(data.service_date);

        // Properties list
        let propHtml = '<div class="table-responsive"><table class="table table-sm table-hover mb-0" style="font-size:12px;">';
        propHtml += '<thead class="thead-light"><tr><th>Property</th><th>Plan</th><th>Visit on date</th></tr></thead><tbody>';
        if (data.properties && data.properties.length) {
            data.properties.forEach(p => {
                const visitStatus = p.existing_visit
                    ? '<span class="badge badge-success">' + escHtml(p.existing_visit.visit_number || p.existing_visit.id) + ' — ' + escHtml(p.existing_visit.status) + '</span>'
                    : '<span class="badge badge-warning">Will create</span>';
                propHtml += '<tr><td>' + escHtml(p.property_address) + '</td><td>' + escHtml(p.plan_title || p.plan_number) + '</td><td>' + visitStatus + '</td></tr>';
            });
        } else {
            propHtml += '<tr><td colspan="3" class="text-muted text-center py-3">No active winter-service properties found.</td></tr>';
        }
        propHtml += '</tbody></table></div>';

        let crewHtml = '';
        if (data.crew_list && data.crew_list.length) {
            crewHtml = '<div class="pt-2 px-0" style="border-top:1px solid #eee;">';
            crewHtml += '<p class="mb-2 px-3" style="font-size:12px;font-weight:600;color:#555;">SMS will be sent to ' + data.crew_sms_count + ' crew member(s):</p>';
            crewHtml += '<div class="px-3 pb-2">';
            data.crew_list.forEach(c => {
                crewHtml += '<span class="badge mr-1 mb-1" style="background:#E8F3F0;color:#0D3B2E;font-size:11px;padding:5px 8px;">'
                    + '<i data-feather="user" style="width:11px;height:11px;margin-right:4px;"></i>'
                    + escHtml(c.full_name) + '</span>';
            });
            crewHtml += '</div></div>';
        } else {
            crewHtml = '<div class="p-3 text-muted" style="font-size:12px;">No SMS-enabled crew found.</div>';
        }

        el('propertiesList').innerHTML = propHtml;
        el('crewList').innerHTML = crewHtml;

        if (window.feather) feather.replace();
    }

    function statBlock(label, value, color) {
        return '<div style="background:#f8f9fa;border-radius:6px;padding:12px;text-align:center;">'
            + '<div style="font-size:11px;color:#666;text-transform:uppercase;letter-spacing:0.5px;margin-bottom:4px;">' + escHtml(label) + '</div>'
            + '<div style="font-size:20px;font-weight:bold;color:' + color + ';">' + escHtml(value) + '</div>'
            + '</div>';
    }

    // ── TRIGGER ───────────────────────────────────────────────────────────────
    el('triggerBtn').addEventListener('click', async function () {
        if (!previewData || !previewData.properties) {
            alert('Please fetch the weather preview first.');
            return;
        }
        const serviceDate = el('serviceDate').value;
        const propCount   = previewData.properties.length;
        const crewCount   = previewData.crew_sms_count || 0;

        if (!confirm('Send salt run alerts to ' + crewCount + ' crew member(s) for ' + propCount + ' propert' + (propCount === 1 ? 'y' : 'ies') + ' on ' + formatDate(serviceDate) + '?\n\nThis cannot be undone.')) {
            return;
        }

        this.disabled = true;
        this.innerHTML = '<span class="spinner-border spinner-border-sm mr-2"></span>Triggering...';

        const manualTemp  = el('manualTemp').value.trim();
        const manualNotes = el('manualNotes') ? el('manualNotes').value.trim() : '';

        const fd = new FormData();
        fd.append('action', 'trigger');
        fd.append('csrf_token', csrf);
        fd.append('service_date', serviceDate);
        if (manualTemp !== '') { fd.append('manual_temp', manualTemp); }
        if (manualNotes !== '') { fd.append('manual_notes', manualNotes); }

        try {
            const res  = await fetch('/crm/api/salt-manual-trigger.php', { method: 'POST', body: fd });
            const data = await res.json();

            hide('previewSection');
            renderResult(data);
            show('resultSection');
            el('resultSection').scrollIntoView({ behavior: 'smooth', block: 'start' });
        } catch (e) {
            alert('Network error: ' + e.message);
            this.disabled = false;
            this.innerHTML = '<i data-feather="zap"></i> Trigger Salt Run Now';
        }
    });

    el('cancelBtn').addEventListener('click', function () {
        hide('previewSection');
        el('manualTemp').value = '';
        hide('manualNotesRow');
    });

    function renderResult(data) {
        if (!data.success && data.error) {
            el('resultBody').innerHTML = '<div class="alert alert-danger"><strong>Error:</strong> ' + escHtml(data.error) + '</div>';
            return;
        }

        const isSuccess = data.sms_sent > 0 || data.decisions_captured > 0;
        const headerColor = isSuccess ? '#2D8659' : '#e85d04';
        const icon = isSuccess ? 'check-circle' : 'alert-circle';

        let html = '<div class="d-flex align-items-center mb-3">';
        html += '<div style="background:' + (isSuccess ? '#E8F3F0' : '#FFF3E0') + ';border-radius:50%;width:48px;height:48px;display:flex;align-items:center;justify-content:center;margin-right:14px;">'
            + '<i data-feather="' + icon + '" style="color:' + headerColor + ';width:24px;height:24px;"></i></div>';
        html += '<div><h5 class="mb-0" style="color:' + headerColor + ';">' + (isSuccess ? 'Salt Run Triggered Successfully' : 'Trigger Completed with Issues') + '</h5>'
            + '<small class="text-muted">' + escHtml(formatDate(data.service_date)) + '</small></div>';
        html += '</div>';

        html += '<div style="display:grid;grid-template-columns:repeat(2,1fr);gap:10px;margin-bottom:16px;">';
        html += statBlock('SMS Sent', data.sms_sent + ' crew', '#2D8659');
        html += statBlock('Decisions Captured', data.decisions_captured, '#1565C0');
        html += statBlock('Visits Created', data.visits_created, '#0D3B2E');
        html += statBlock('Visits Linked', data.visits_linked, '#555');
        html += '</div>';

        if (data.sms_results && data.sms_results.length) {
            html += '<h6 style="font-size:12px;color:#444;font-weight:600;margin-bottom:6px;">SMS Results</h6>';
            html += '<ul style="font-size:12px;list-style:none;padding:0;margin-bottom:14px;">';
            data.sms_results.forEach(r => {
                const color = r.status === 'sent' ? '#2D8659' : '#dc3545';
                html += '<li style="padding:3px 0;"><span style="color:' + color + ';font-weight:bold;">' + escHtml(r.status.toUpperCase()) + '</span> — ' + escHtml(r.crew) + (r.reason ? ' <span class="text-muted">(' + escHtml(r.reason) + ')</span>' : '') + '</li>';
            });
            html += '</ul>';
        }

        if (data.errors && data.errors.length) {
            html += '<div class="alert alert-warning py-2" style="font-size:12px;"><strong>Issues:</strong><ul class="mb-0 pl-3">';
            data.errors.forEach(e => { html += '<li>' + escHtml(e) + '</li>'; });
            html += '</ul></div>';
        }

        html += '<div class="mt-3">';
        html += '<a href="dashboard.php" class="btn btn-primary btn-sm mr-2"><i data-feather="bar-chart-2" class="feather-sm mr-1"></i> Salt Dashboard</a>';
        html += '<button onclick="location.reload()" class="btn btn-outline-secondary btn-sm"><i data-feather="refresh-cw" class="feather-sm mr-1"></i> Trigger Another</button>';
        html += '</div>';

        el('resultBody').innerHTML = html;
        if (window.feather) feather.replace();
    }

    function formatDate(dateStr) {
        if (!dateStr) return '';
        const d = new Date(dateStr + 'T12:00:00');
        return d.toLocaleDateString('en-CA', { weekday: 'long', year: 'numeric', month: 'long', day: 'numeric' });
    }

    function escHtml(str) {
        if (str === null || str === undefined) return '';
        return String(str).replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;');
    }

    // Init feather
    if (window.feather) feather.replace();
})();
</script>

<?php include dirname(__DIR__) . '/includes/appstack_footer.php'; ?>

<?php
/**
 * Jobber Re-Consent Campaign — Import, FSA Approval, Send Progress
 *
 * @package Mowology CRM
 */
require_once dirname(__DIR__) . '/../loginAuth/auth.php';
require_once dirname(__DIR__) . '/includes/functions.php';
requireLogin();
$user = getCurrentUser();
requirePermission('admin');

$pageTitle  = 'Jobber Re-Consent Campaign';
$activePage = 'marketing';
?>
<?php include dirname(__DIR__) . '/includes/appstack_head.php'; ?>

          <!-- ── Page Header ─────────────────────────────────────────── -->
          <div class="d-flex justify-content-between align-items-center mb-3">
              <div>
                  <h1 class="h3 mb-0">Jobber Re-Consent Campaign</h1>
                  <p class="text-muted mb-0">Import clients, approve FSA territories, drip re-consent emails</p>
              </div>
              <a href="campaigns.php" class="btn btn-outline-secondary btn-sm">
                  <i data-feather="arrow-left" class="mr-1"></i> Back to Marketing
              </a>
          </div>

          <!-- ── Step Wizard Tabs ────────────────────────────────────── -->
          <ul class="nav nav-tabs mw-marketing-tabs mb-4" id="reconsentTabs">
              <li class="nav-item">
                  <a class="nav-link active" href="#" onclick="showStep(1);return false;" id="tabStep1">
                      <strong>1.</strong> Upload &amp; Preview
                  </a>
              </li>
              <li class="nav-item">
                  <a class="nav-link" href="#" onclick="showStep(2);return false;" id="tabStep2">
                      <strong>2.</strong> FSA Approval
                  </a>
              </li>
              <li class="nav-item">
                  <a class="nav-link" href="#" onclick="showStep(3);return false;" id="tabStep3">
                      <strong>3.</strong> Send Progress
                  </a>
              </li>
          </ul>

          <!-- ═══════════════════════════════════════════════════════════
               STEP 1 — UPLOAD & PREVIEW
          ════════════════════════════════════════════════════════════════ -->
          <div id="step1">
              <div class="card">
                  <div class="card-header"><h5 class="card-title mb-0">CSV File Paths</h5></div>
                  <div class="card-body">
                      <p class="text-muted mb-3">Enter the server paths to your Jobber CSV export files.</p>
                      <div class="form-group">
                          <label>CSV File 1 (required)</label>
                          <input type="text" class="form-control" id="csvFile1" value="/home/mowology/public_html/tmp_import/Jobber Clients 1 of 2.csv">
                      </div>
                      <div class="form-group">
                          <label>CSV File 2 (optional)</label>
                          <input type="text" class="form-control" id="csvFile2" value="/home/mowology/public_html/tmp_import/Jobber Clients 2 of 2.csv">
                      </div>
                      <button class="btn btn-primary" onclick="previewImport()" id="btnPreview">
                          <i data-feather="eye" class="mr-1"></i> Preview Import
                      </button>
                  </div>
              </div>

              <!-- Preview Results -->
              <div id="previewResults" style="display:none;">
                  <div class="mw-camp-stats mb-3">
                      <div class="mw-camp-stat-card"><span class="mw-camp-stat-number" id="previewTotal">—</span><span class="mw-camp-stat-label">Total CSV Rows</span></div>
                      <div class="mw-camp-stat-card sent"><span class="mw-camp-stat-number" id="previewImportable">—</span><span class="mw-camp-stat-label">Importable</span></div>
                      <div class="mw-camp-stat-card"><span class="mw-camp-stat-number" id="previewDuplicates">—</span><span class="mw-camp-stat-label">Already in CRM</span></div>
                      <div class="mw-camp-stat-card"><span class="mw-camp-stat-number" id="previewNoEmail">—</span><span class="mw-camp-stat-label">No Email</span></div>
                  </div>

                  <!-- FSA Preview -->
                  <div class="card mb-3">
                      <div class="card-header"><h5 class="card-title mb-0">FSA Territory Breakdown</h5></div>
                      <div class="card-body">
                          <div id="fsaPreviewGrid" class="mw-reconsent-fsa-grid"></div>
                      </div>
                  </div>

                  <!-- Sample Rows -->
                  <div class="card mb-3">
                      <div class="card-header"><h5 class="card-title mb-0">Sample Rows (first 20)</h5></div>
                      <div class="card-body p-0">
                          <div class="table-responsive">
                              <table class="table table-sm mb-0">
                                  <thead><tr><th>Name</th><th>Email</th><th>Phone</th><th>City</th><th>FSA</th><th>Company</th></tr></thead>
                                  <tbody id="sampleRowsBody"></tbody>
                              </table>
                          </div>
                      </div>
                  </div>

                  <button class="btn btn-success btn-lg" onclick="runImport()" id="btnImport">
                      <i data-feather="upload" class="mr-1"></i> Import All &amp; Continue to FSA Approval
                  </button>
              </div>
          </div>

          <!-- ═══════════════════════════════════════════════════════════
               STEP 2 — FSA APPROVAL
          ════════════════════════════════════════════════════════════════ -->
          <div id="step2" style="display:none;">
              <div class="d-flex justify-content-between align-items-center mb-3">
                  <p class="text-muted mb-0">Approve each postal code territory to queue re-consent emails. Skip areas you don't want to contact.</p>
                  <div>
                      <button class="btn btn-outline-success btn-sm mr-2" onclick="approveAll()">
                          <i data-feather="check-circle" class="mr-1"></i> Approve All
                      </button>
                      <button class="btn btn-success" onclick="showStep(3)">
                          Continue to Progress <i data-feather="arrow-right" class="ml-1"></i>
                      </button>
                  </div>
              </div>

              <div id="fsaApprovalGrid" class="mw-reconsent-fsa-grid"></div>
          </div>

          <!-- ═══════════════════════════════════════════════════════════
               STEP 3 — SEND PROGRESS
          ════════════════════════════════════════════════════════════════ -->
          <div id="step3" style="display:none;">
              <!-- Stats Bar -->
              <div class="mw-camp-stats mb-3" id="progressStats">
                  <div class="mw-camp-stat-card"><span class="mw-camp-stat-number" id="statTotal">—</span><span class="mw-camp-stat-label">Total</span></div>
                  <div class="mw-camp-stat-card sent"><span class="mw-camp-stat-number" id="statQueued">—</span><span class="mw-camp-stat-label">Queued</span></div>
                  <div class="mw-camp-stat-card"><span class="mw-camp-stat-number" id="statSent">—</span><span class="mw-camp-stat-label">Sent</span></div>
                  <div class="mw-camp-stat-card"><span class="mw-camp-stat-number" id="statConfirmed">—</span><span class="mw-camp-stat-label">Confirmed</span></div>
                  <div class="mw-camp-stat-card"><span class="mw-camp-stat-number" id="statUnsubscribed">—</span><span class="mw-camp-stat-label">Unsubscribed</span></div>
                  <div class="mw-camp-stat-card"><span class="mw-camp-stat-number" id="statSkipped">—</span><span class="mw-camp-stat-label">Skipped</span></div>
              </div>

              <!-- Progress Bar -->
              <div class="card mb-3">
                  <div class="card-body">
                      <div class="d-flex justify-content-between mb-2">
                          <span class="font-weight-bold">Send Progress</span>
                          <span id="progressPercent">0%</span>
                      </div>
                      <div class="progress" style="height: 20px;">
                          <div class="progress-bar bg-success" id="progressBar" style="width: 0%"></div>
                      </div>
                      <small class="text-muted mt-1 d-block">Cron sends 10 emails per day at 9:00 AM. <span id="progressEta"></span></small>
                  </div>
              </div>

              <!-- Actions -->
              <div class="card mb-3">
                  <div class="card-body">
                      <div class="d-flex align-items-center">
                          <button class="btn btn-outline-primary mr-3" onclick="triggerManualSend(10)">
                              <i data-feather="send" class="mr-1"></i> Send 10 Now
                          </button>
                          <button class="btn btn-outline-primary mr-3" onclick="triggerManualSend(1)">
                              <i data-feather="mail" class="mr-1"></i> Send 1 Test
                          </button>
                          <button class="btn btn-outline-secondary" onclick="loadStats()">
                              <i data-feather="refresh-cw" class="mr-1"></i> Refresh Stats
                          </button>
                      </div>
                  </div>
              </div>

              <!-- FSA Breakdown -->
              <div class="card">
                  <div class="card-header"><h5 class="card-title mb-0">Territory Breakdown</h5></div>
                  <div class="card-body">
                      <div id="fsaProgressGrid" class="mw-reconsent-fsa-grid"></div>
                  </div>
              </div>
          </div>


<script>
// ═══════════════════════════════════════════════════════════════════════
// Jobber Re-Consent Campaign JS
// ═══════════════════════════════════════════════════════════════════════

const API = '/crm/api/jobber-reconsent.php';
let currentBatchId = localStorage.getItem('jobber_reconsent_batch') || '';

// ── Step Navigation ─────────────────────────────────────────────────

function showStep(n) {
    document.querySelectorAll('#step1,#step2,#step3').forEach(el => el.style.display = 'none');
    document.querySelectorAll('#reconsentTabs .nav-link').forEach(el => el.classList.remove('active'));
    document.getElementById('step' + n).style.display = '';
    document.getElementById('tabStep' + n).classList.add('active');

    if (n === 2) loadFsaReview();
    if (n === 3) { loadStats(); loadFsaProgress(); }
}

// ── Step 1: Preview ─────────────────────────────────────────────────

async function previewImport() {
    const btn = document.getElementById('btnPreview');
    btn.disabled = true;
    btn.innerHTML = '<span class="spinner-border spinner-border-sm mr-1"></span> Parsing...';

    try {
        const res = await fetch(API + '?action=preview', {
            method: 'POST',
            headers: {'Content-Type': 'application/json'},
            body: JSON.stringify({
                file1: document.getElementById('csvFile1').value,
                file2: document.getElementById('csvFile2').value,
            })
        });
        const data = await res.json();
        if (!data.success) { alert(data.error); return; }

        document.getElementById('previewTotal').textContent = data.total_csv_rows;
        document.getElementById('previewImportable').textContent = data.importable;
        document.getElementById('previewDuplicates').textContent = data.duplicates;
        document.getElementById('previewNoEmail').textContent = data.no_email;

        // FSA Preview chips
        const grid = document.getElementById('fsaPreviewGrid');
        grid.innerHTML = Object.entries(data.fsa_preview).map(([fsa, count]) =>
            `<div class="mw-reconsent-fsa-chip"><strong>${esc(fsa)}</strong> <span>${count}</span></div>`
        ).join('');

        // Sample rows
        const tbody = document.getElementById('sampleRowsBody');
        tbody.innerHTML = data.sample_rows.map(r =>
            `<tr><td>${esc(r.name)}</td><td>${esc(r.email)}</td><td>${esc(r.phone)}</td><td>${esc(r.city)}</td><td><span class="badge badge-secondary">${esc(r.fsa)}</span></td><td>${r.is_company ? '<span class="badge badge-info">Company</span>' : ''}</td></tr>`
        ).join('');

        document.getElementById('previewResults').style.display = '';
    } catch (e) {
        alert('Error: ' + e.message);
    } finally {
        btn.disabled = false;
        btn.innerHTML = '<i data-feather="eye" class="mr-1"></i> Preview Import';
        if (typeof feather !== 'undefined') feather.replace();
    }
}

// ── Step 1: Import ──────────────────────────────────────────────────

async function runImport() {
    if (!confirm('Import all importable contacts into the CRM and queue them for FSA approval?')) return;

    const btn = document.getElementById('btnImport');
    btn.disabled = true;
    btn.innerHTML = '<span class="spinner-border spinner-border-sm mr-1"></span> Importing...';

    try {
        const res = await fetch(API + '?action=import', {
            method: 'POST',
            headers: {'Content-Type': 'application/json'},
            body: JSON.stringify({
                file1: document.getElementById('csvFile1').value,
                file2: document.getElementById('csvFile2').value,
            })
        });
        const data = await res.json();
        if (!data.success) { alert(data.error); return; }

        currentBatchId = data.batch_id;
        localStorage.setItem('jobber_reconsent_batch', currentBatchId);

        alert(`Imported ${data.imported} contacts (${data.skipped} skipped as duplicates).\n\nBatch ID: ${data.batch_id}\n\nProceeding to FSA approval.`);
        showStep(2);
    } catch (e) {
        alert('Error: ' + e.message);
    } finally {
        btn.disabled = false;
        btn.innerHTML = '<i data-feather="upload" class="mr-1"></i> Import All & Continue to FSA Approval';
        if (typeof feather !== 'undefined') feather.replace();
    }
}

// ── Step 2: FSA Review ──────────────────────────────────────────────

async function loadFsaReview() {
    const grid = document.getElementById('fsaApprovalGrid');
    grid.innerHTML = '<div class="text-center py-4"><span class="spinner-border"></span></div>';

    try {
        const res = await fetch(API + '?action=fsa-review&batch_id=' + encodeURIComponent(currentBatchId));
        const data = await res.json();
        if (!data.success) { grid.innerHTML = '<p class="text-danger">' + data.error + '</p>'; return; }

        grid.innerHTML = data.fsa_blocks.map(block => {
            const isApproved = parseInt(block.approved_count) > 0;
            const isSkipped  = parseInt(block.skipped_count) > 0;
            const statusClass = isApproved ? 'approved' : (isSkipped ? 'skipped' : '');
            const statusBadge = isApproved
                ? '<span class="badge badge-success">Approved</span>'
                : (isSkipped ? '<span class="badge badge-secondary">Skipped</span>' : '<span class="badge badge-warning">Pending</span>');

            return `<div class="mw-reconsent-fsa-card ${statusClass}" id="fsa-${block.fsa}">
                <div class="d-flex justify-content-between align-items-start mb-2">
                    <div>
                        <h5 class="mb-0">${esc(block.fsa === '---' ? 'No Postal Code' : block.fsa)}</h5>
                        <small class="text-muted">${esc(block.city || 'Unknown')}</small>
                    </div>
                    <span class="h4 mb-0 font-weight-bold">${block.contact_count}</span>
                </div>
                <p class="text-muted small mb-2">${esc(block.sample_names)}</p>
                <div class="d-flex align-items-center">
                    ${statusBadge}
                    <div class="ml-auto">
                        <button class="btn btn-sm btn-outline-success mr-1" onclick="approveFsa('${esc(block.fsa)}', true)" ${isApproved ? 'disabled' : ''}>
                            <i data-feather="check" style="width:14px;height:14px;"></i> Approve
                        </button>
                        <button class="btn btn-sm btn-outline-secondary" onclick="approveFsa('${esc(block.fsa)}', false)" ${isSkipped ? 'disabled' : ''}>
                            <i data-feather="x" style="width:14px;height:14px;"></i> Skip
                        </button>
                    </div>
                </div>
            </div>`;
        }).join('');

        if (typeof feather !== 'undefined') feather.replace();
    } catch (e) {
        grid.innerHTML = '<p class="text-danger">Error: ' + e.message + '</p>';
    }
}

async function approveFsa(fsa, approved) {
    try {
        const res = await fetch(API + '?action=approve-fsa', {
            method: 'POST',
            headers: {'Content-Type': 'application/json'},
            body: JSON.stringify({ fsa, approved, batch_id: currentBatchId })
        });
        const data = await res.json();
        if (!data.success) { alert(data.error); return; }
        loadFsaReview();
    } catch (e) { alert('Error: ' + e.message); }
}

async function approveAll() {
    if (!confirm('Approve ALL FSA territories for re-consent emails?')) return;
    try {
        const res = await fetch(API + '?action=approve-all', {
            method: 'POST',
            headers: {'Content-Type': 'application/json'},
            body: JSON.stringify({ batch_id: currentBatchId })
        });
        const data = await res.json();
        if (!data.success) { alert(data.error); return; }
        alert(`Approved ${data.updated} contacts for sending.`);
        loadFsaReview();
    } catch (e) { alert('Error: ' + e.message); }
}

// ── Step 3: Stats ───────────────────────────────────────────────────

async function loadStats() {
    try {
        const res = await fetch(API + '?action=stats&batch_id=' + encodeURIComponent(currentBatchId));
        const data = await res.json();
        if (!data.success) return;

        const q = data.queue;
        const r = data.responses;
        const total = parseInt(q.total) || 0;
        const queued = parseInt(q.queued) || 0;
        const sent = parseInt(q.sent) || 0;
        const skipped = parseInt(q.skipped) || 0;

        document.getElementById('statTotal').textContent = total;
        document.getElementById('statQueued').textContent = queued;
        document.getElementById('statSent').textContent = sent;
        document.getElementById('statConfirmed').textContent = r?.confirmed || 0;
        document.getElementById('statUnsubscribed').textContent = r?.unsubscribed || 0;
        document.getElementById('statSkipped').textContent = skipped;

        // Progress bar
        const sendable = total - skipped;
        const pct = sendable > 0 ? Math.round((sent / sendable) * 100) : 0;
        document.getElementById('progressBar').style.width = pct + '%';
        document.getElementById('progressPercent').textContent = pct + '%';

        // ETA
        const remaining = queued;
        if (remaining > 0) {
            const days = Math.ceil(remaining / 10);
            document.getElementById('progressEta').textContent = `~${days} days remaining at 10/day`;
        } else if (sent > 0) {
            document.getElementById('progressEta').textContent = 'All queued emails sent!';
        }
    } catch (e) {
        console.error('Stats error:', e);
    }
}

async function loadFsaProgress() {
    try {
        const res = await fetch(API + '?action=fsa-review&batch_id=' + encodeURIComponent(currentBatchId));
        const data = await res.json();
        if (!data.success) return;

        const grid = document.getElementById('fsaProgressGrid');
        grid.innerHTML = data.fsa_blocks.map(block => {
            const total = parseInt(block.contact_count);
            const sent = parseInt(block.sent_count) || 0;
            const pct = total > 0 ? Math.round((sent / total) * 100) : 0;
            const isSkipped = parseInt(block.skipped_count) > 0;

            return `<div class="mw-reconsent-fsa-card ${isSkipped ? 'skipped' : ''}">
                <div class="d-flex justify-content-between mb-1">
                    <strong>${esc(block.fsa === '---' ? 'No Postal Code' : block.fsa)}</strong>
                    <span>${sent}/${total} sent</span>
                </div>
                <div class="progress" style="height:6px;">
                    <div class="progress-bar bg-success" style="width:${pct}%"></div>
                </div>
            </div>`;
        }).join('');
    } catch (e) {
        console.error('FSA progress error:', e);
    }
}

async function triggerManualSend(limit) {
    const label = limit === 1 ? 'Send 1 test email now?' : `Send ${limit} emails now?`;
    if (!confirm(label)) return;

    try {
        const res = await fetch(API + '?action=trigger-send', {
            method: 'POST',
            headers: {'Content-Type': 'application/json'},
            body: JSON.stringify({ limit })
        });
        const data = await res.json();
        if (!data.success) { alert(data.error); return; }
        alert(`Sent: ${data.sent}, Failed: ${data.failed}`);
        loadStats();
        loadFsaProgress();
    } catch (e) { alert('Error: ' + e.message); }
}

// ── Utilities ───────────────────────────────────────────────────────

function esc(str) {
    if (!str) return '';
    const d = document.createElement('div');
    d.textContent = str;
    return d.innerHTML;
}

// ── Init ────────────────────────────────────────────────────────────

document.addEventListener('DOMContentLoaded', function() {
    // If we have a batch ID, skip to FSA approval or progress
    if (currentBatchId) {
        // Check if we have queued/sent items to determine which step
        fetch(API + '?action=stats&batch_id=' + encodeURIComponent(currentBatchId))
            .then(r => r.json())
            .then(data => {
                if (data.success && data.queue) {
                    const q = data.queue;
                    if (parseInt(q.sent) > 0) showStep(3);
                    else if (parseInt(q.queued) > 0) showStep(3);
                    else showStep(2);
                }
            })
            .catch(() => {});
    }
});
</script>

<?php include dirname(__DIR__) . '/includes/appstack_footer.php'; ?>

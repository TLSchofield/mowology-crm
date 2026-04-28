<?php
require_once dirname(__DIR__) . '/../loginAuth/auth.php';
require_once dirname(__DIR__) . '/includes/functions.php';
requireLogin();
$user = getCurrentUser();
requirePermission('expenses.edit');

$pageTitle  = 'Bank Import';
$activePage = 'accounting';
?>
<?php include dirname(__DIR__) . '/includes/appstack_head.php'; ?>

<div class="mw-page-header d-flex justify-content-between align-items-center flex-wrap gap-2 mb-3">
    <div>
        <h1 class="h3 mb-0">Bank Import</h1>
        <p class="text-muted mb-0 small">Upload a bank CSV — transactions are auto-categorized and de-duplicated</p>
    </div>
    <a href="/crm/accounting_appstack.php" class="btn btn-sm btn-outline-secondary">← Dashboard</a>
</div>

<!-- ══════════════════════════════════════════════════════════════════════════ -->
<!-- STEP 1 — Upload CSV                                                        -->
<!-- ══════════════════════════════════════════════════════════════════════════ -->
<div id="step-upload" class="card mb-3">
    <div class="card-header"><h5 class="card-title mb-0">Step 1 — Upload Bank CSV</h5></div>
    <div class="card-body">
        <div class="row g-3">
            <div class="col-md-4">
                <label class="form-label small">Bank / Format</label>
                <select id="preset" class="form-select" onchange="updatePresetHint()">
                    <option value="td">TD Bank</option>
                    <option value="rbc">RBC</option>
                    <option value="bmo">BMO</option>
                    <option value="cibc">CIBC</option>
                    <option value="scotiabank">Scotiabank</option>
                    <option value="generic">Generic (single amount column)</option>
                    <option value="custom">Custom column mapping…</option>
                </select>
                <div id="preset-hint" class="form-text mt-1"></div>
            </div>
            <div class="col-md-4">
                <label class="form-label small">Account Name <span class="text-muted">(optional label)</span></label>
                <input type="text" id="account-name" class="form-control" placeholder="e.g. TD Business Chequing">
            </div>
            <div class="col-md-4">
                <label class="form-label small">File <span class="text-muted">(CSV, PDF, or photo)</span></label>
                <input type="file" id="csv-file" class="form-control" accept=".csv,.txt,.pdf,.jpg,.jpeg,.png,.webp,.heic" onchange="onFileSelected()">
            </div>
        </div>

        <!-- PDF notice (shown when PDF is selected) -->
        <div id="pdf-notice" class="alert alert-info py-2 mt-3 d-none small">
            <i data-feather="file-text" style="width:14px;height:14px;"></i>
            <strong>PDF detected.</strong> Transactions will be extracted automatically — no column mapping needed.
            Results may vary depending on your bank's PDF format.
        </div>

        <!-- Image/scan notice (shown when a photo or scan is selected) -->
        <div id="image-notice" class="alert alert-info py-2 mt-3 d-none small">
            <i data-feather="camera" style="width:14px;height:14px;"></i>
            <strong>Image detected.</strong> The statement will be scanned automatically using OCR.
            For best results: ensure the page is flat, well-lit, and the text is in focus.
        </div>

        <!-- Custom mapping panel (hidden by default, CSV only) -->
        <div id="custom-mapping" class="mt-3 p-3 border rounded" style="display:none; background:var(--bs-light)">
            <p class="small text-muted mb-2">Enter zero-based column index for each field (0 = first column):</p>
            <div class="row g-2">
                <div class="col-4 col-md-2"><label class="form-label small">Date</label><input type="number" id="col-date" class="form-control form-control-sm" value="0" min="0"></div>
                <div class="col-4 col-md-2"><label class="form-label small">Description</label><input type="number" id="col-desc" class="form-control form-control-sm" value="1" min="0"></div>
                <div class="col-4 col-md-2"><label class="form-label small">Debit</label><input type="number" id="col-debit" class="form-control form-control-sm" placeholder="col#" min="0"></div>
                <div class="col-4 col-md-2"><label class="form-label small">Credit</label><input type="number" id="col-credit" class="form-control form-control-sm" placeholder="col#" min="0"></div>
                <div class="col-4 col-md-2"><label class="form-label small">Amount</label><input type="number" id="col-amount" class="form-control form-control-sm" placeholder="col#" min="0"></div>
                <div class="col-4 col-md-2"><label class="form-label small">Skip Rows</label><input type="number" id="skip-rows" class="form-control form-control-sm" value="1" min="0"></div>
            </div>
        </div>

        <div class="mt-3">
            <button class="btn btn-primary" onclick="uploadAndPreview()" id="btn-preview">
                <i data-feather="upload" class="mw-btn-icon"></i> Upload &amp; Preview
            </button>
        </div>

        <!-- Processing indicator — shown during upload/OCR -->
        <div id="processing-indicator" class="d-none mt-3 p-3 border rounded" style="background:var(--bs-light)">
            <div class="d-flex align-items-center gap-3">
                <div class="spinner-border spinner-border-sm text-primary flex-shrink-0" role="status"></div>
                <div>
                    <div class="fw-semibold small" id="processing-status">Uploading file…</div>
                    <div class="text-muted" style="font-size:0.78rem" id="processing-detail">Please wait — PDF extraction can take up to 60 seconds</div>
                </div>
            </div>
            <div class="progress mt-2" style="height:4px">
                <div id="processing-bar" class="progress-bar progress-bar-striped progress-bar-animated bg-primary" style="width:5%"></div>
            </div>
        </div>
    </div>
</div>

<!-- ══════════════════════════════════════════════════════════════════════════ -->
<!-- STEP 2 — Review & Categorize                                               -->
<!-- ══════════════════════════════════════════════════════════════════════════ -->
<div id="step-preview" style="display:none">
    <div class="card mb-3">
        <div class="card-header d-flex justify-content-between align-items-center">
            <h5 class="card-title mb-0">Step 2 — Review Transactions</h5>
            <div class="d-flex gap-2">
                <span id="preview-summary" class="text-muted small"></span>
            </div>
        </div>
        <div class="card-body py-2">
            <div class="d-flex gap-3 flex-wrap align-items-center mb-2">
                <div class="form-check form-check-inline">
                    <input class="form-check-input" type="checkbox" id="skip-duplicates" checked>
                    <label class="form-check-label small" for="skip-duplicates">Skip probable duplicates</label>
                </div>
                <div class="form-check form-check-inline">
                    <input class="form-check-input" type="checkbox" id="hide-duplicates">
                    <label class="form-check-label small" for="hide-duplicates">Hide duplicates from preview</label>
                </div>
                <button class="btn btn-xs btn-outline-secondary ms-auto" onclick="resetUpload()">← Back</button>
            </div>

            <!-- Alert: Duplicate count -->
            <div id="dupe-alert" class="alert alert-warning py-2 small d-none">
                <i data-feather="alert-triangle" style="width:14px;height:14px"></i>
                <span id="dupe-msg"></span>
            </div>
        </div>

        <!-- Preview table -->
        <div class="table-responsive" style="max-height:500px; overflow-y:auto;">
            <table class="table table-sm table-hover mw-acct-table mb-0">
                <thead style="position:sticky;top:0;z-index:1;background:var(--bs-card-bg,#fff)">
                    <tr>
                        <th style="width:30px"><input type="checkbox" id="chk-all" onchange="toggleAll(this)"></th>
                        <th style="width:100px">Date</th>
                        <th>Description</th>
                        <th style="width:80px">Type</th>
                        <th style="width:90px" class="text-end">Amount</th>
                        <th style="width:200px">Account</th>
                        <th style="width:70px">Status</th>
                    </tr>
                </thead>
                <tbody id="preview-tbody"></tbody>
            </table>
        </div>

        <div class="card-footer d-flex justify-content-between align-items-center py-2">
            <span class="text-muted small" id="selected-count">0 rows selected</span>
            <button class="btn btn-success" onclick="commitImport()">
                <i data-feather="check-circle" class="mw-btn-icon"></i> Import Selected Transactions
            </button>
        </div>
    </div>
</div>

<!-- ══════════════════════════════════════════════════════════════════════════ -->
<!-- STEP 3 — Import Complete                                                   -->
<!-- ══════════════════════════════════════════════════════════════════════════ -->
<div id="step-done" style="display:none">
    <div class="card mb-3">
        <div class="card-body text-center py-5">
            <div class="mw-acct-done-icon">✓</div>
            <h4 class="mt-3 mb-1" id="done-title">Import Complete</h4>
            <p class="text-muted" id="done-body"></p>
            <div class="d-flex justify-content-center gap-3 mt-3">
                <button class="btn btn-outline-secondary" onclick="undoImport()">Undo Import</button>
                <a href="/crm/accounting/transactions.php" class="btn btn-primary">View Transactions</a>
                <button class="btn btn-outline-primary" onclick="resetUpload()">Import Another File</button>
            </div>
        </div>
    </div>
</div>

<!-- ── Past Import Sessions ──────────────────────────────────────────────────── -->
<div class="card">
    <div class="card-header d-flex justify-content-between align-items-center">
        <h5 class="card-title mb-0">Import History</h5>
        <button class="btn btn-sm btn-outline-secondary" onclick="loadSessions()">Refresh</button>
    </div>
    <div class="card-body p-0">
        <table class="table table-sm mb-0">
            <thead>
                <tr>
                    <th>Date</th>
                    <th>Bank / Account</th>
                    <th>Rows</th>
                    <th>Imported</th>
                    <th>Duplicates</th>
                    <th>Status</th>
                    <th></th>
                </tr>
            </thead>
            <tbody id="sessions-tbody">
                <tr><td colspan="7" class="text-muted text-center py-3">Loading…</td></tr>
            </tbody>
        </table>
    </div>
</div>

<script>
const API = '/crm/api/accounting-bank-import.php';
const API_ACC = '/crm/api/accounting-accounts.php';

let previewRows    = [];
let lastSessionId  = null;
let allAccounts    = [];

const PRESET_HINTS = {
    td:         'Columns: Date, Description, Debit, Credit, Balance',
    rbc:        'Columns: AccType, AccNum, Date, ChequeNum, Desc1, Desc2, CAD$, USD$',
    bmo:        'Columns: Date, Description, Withdrawal, Deposit, Balance',
    cibc:       'Columns: Date, Description, Debit, Credit',
    scotiabank: 'Columns: Date, Description, Withdrawal, Deposit, Balance',
    generic:    'Columns: Date, Description, Amount (positive=income, negative=expense)',
    custom:     'Enter your column numbers below',
};

document.addEventListener('DOMContentLoaded', () => {
    loadAccounts();
    loadSessions();
    updatePresetHint();
});

async function loadAccounts() {
    const r = await fetch(API_ACC + '?action=list');
    const d = await r.json();
    if (d.ok) allAccounts = d.accounts;
}

function updatePresetHint() {
    const preset = document.getElementById('preset').value;
    document.getElementById('preset-hint').textContent = PRESET_HINTS[preset] || '';
    document.getElementById('custom-mapping').style.display = preset === 'custom' ? '' : 'none';
}

function onFileSelected() {
    const file = document.getElementById('csv-file').files[0];
    if (!file) return;

    const name   = file.name.toLowerCase();
    const isPdf  = name.endsWith('.pdf');
    const isImg  = /\.(jpe?g|png|webp|heic)$/.test(name);
    const isAuto = isPdf || isImg;

    document.getElementById('pdf-notice').classList.toggle('d-none', !isPdf);
    document.getElementById('image-notice').classList.toggle('d-none', !isImg);
    document.getElementById('preset').closest('.col-md-4').style.display = isAuto ? 'none' : '';

    if (isAuto) {
        document.getElementById('custom-mapping').style.display = 'none';
        if (typeof feather !== 'undefined') feather.replace();
        return;
    }

    // CSV — auto-detect preset from filename
    const keys = ['td', 'rbc', 'bmo', 'cibc', 'scotiabank'];
    for (const k of keys) {
        if (name.includes(k)) {
            document.getElementById('preset').value = k;
            updatePresetHint();
            return;
        }
    }
}

async function uploadAndPreview() {
    const file = document.getElementById('csv-file').files[0];
    if (!file) { alert('Please select a CSV file.'); return; }

    const preset      = document.getElementById('preset').value;
    const accountName = document.getElementById('account-name').value;

    const form = new FormData();
    form.append('action',       'preview');
    form.append('csv',          file);
    form.append('preset',       preset);
    form.append('bank_name',    preset);
    form.append('account_name', accountName);

    if (preset === 'custom') {
        ['date','desc','debit','credit','amount'].forEach(k => {
            const v = document.getElementById('col-' + k)?.value;
            if (v !== '' && v !== null) form.append('col_' + (k === 'desc' ? 'description' : k), v);
        });
        form.append('skip_rows', document.getElementById('skip-rows').value);
    }

    const btn       = document.getElementById('btn-preview');
    const indicator = document.getElementById('processing-indicator');
    const statusEl  = document.getElementById('processing-status');
    const detailEl  = document.getElementById('processing-detail');
    const barEl     = document.getElementById('processing-bar');
    const isPdfOrImg = /\.(pdf|jpe?g|png|webp|heic)$/i.test(file.name);

    btn.disabled = true;
    btn.innerHTML = '<span class="spinner-border spinner-border-sm me-1" role="status"></span> Analyzing…';
    indicator.classList.remove('d-none');
    statusEl.textContent = 'Uploading file…';
    detailEl.textContent = isPdfOrImg
        ? 'PDF/image extraction can take up to 60 seconds — hang tight'
        : 'Parsing CSV…';
    barEl.style.width = '8%';

    // Animate progress bar to give a sense of forward motion
    let pct = 8;
    const progressTimer = setInterval(() => {
        // Slow crawl: 8% → 85% over ~55s, never reaches 100 until done
        const increment = pct < 30 ? 3 : pct < 60 ? 1.5 : pct < 80 ? 0.5 : 0.1;
        pct = Math.min(pct + increment, 85);
        barEl.style.width = pct + '%';
        if (pct > 20 && pct < 60) {
            statusEl.textContent = isPdfOrImg ? 'Running OCR on statement pages…' : 'Parsing rows…';
        } else if (pct >= 60) {
            statusEl.textContent = isPdfOrImg ? 'Categorizing transactions…' : 'Checking for duplicates…';
        }
    }, 1000);

    try {
        const r = await fetch(API, { method: 'POST', body: form });
        if (!r.ok && r.status === 0) throw new Error('Network error — check your connection.');
        const text = await r.text();
        let d;
        try { d = JSON.parse(text); } catch (_) {
            if (r.redirected || text.trim().startsWith('<')) {
                throw new Error('Session expired — please refresh the page and log in again.');
            }
            throw new Error('Server returned an unexpected response (HTTP ' + r.status + ').');
        }

        if (!d.ok) throw new Error(d.error);

        barEl.style.width = '100%';
        statusEl.textContent = 'Done — loading preview…';

        previewRows = d.preview.rows;
        renderPreview(d.preview);

        document.getElementById('step-upload').style.display  = 'none';
        document.getElementById('step-preview').style.display = '';
        document.getElementById('step-done').style.display    = 'none';

    } catch (err) {
        alert('Preview failed: ' + err.message);
    } finally {
        clearInterval(progressTimer);
        indicator.classList.add('d-none');
        btn.disabled = false;
        btn.innerHTML = '<i data-feather="upload" class="mw-btn-icon"></i> Upload &amp; Preview';
        if (typeof feather !== 'undefined') feather.replace();
    }
}

function renderPreview(preview) {
    const totals = preview.totals;
    document.getElementById('preview-summary').textContent =
        `${totals.rows} rows · +${fmtMoney(totals.income)} income · −${fmtMoney(totals.expense)} expenses · ${totals.duplicates} duplicates`;

    const dupeAlert = document.getElementById('dupe-alert');
    if (totals.duplicates > 0) {
        document.getElementById('dupe-msg').textContent =
            `${totals.duplicates} transaction${totals.duplicates > 1 ? 's' : ''} may already exist in the ledger and will be skipped.`;
        dupeAlert.classList.remove('d-none');
    } else {
        dupeAlert.classList.add('d-none');
    }

    renderPreviewTable(preview.rows);
    updateSelectedCount();
}

function renderPreviewTable(rows) {
    const hideDupes = document.getElementById('hide-duplicates').checked;
    const tbody     = document.getElementById('preview-tbody');

    const accountOptions = allAccounts
        .filter(a => a.sub_type !== 'header')
        .map(a => `<option value="${a.id}">${a.code} – ${a.name}</option>`)
        .join('');

    tbody.innerHTML = rows.map((row, i) => {
        if (hideDupes && row.is_duplicate) return '';
        const isDupe = row.is_duplicate;
        const rowClass = isDupe ? 'mw-acct-row-review' : '';
        const typeClass = row.type === 'income' ? 'mw-acct-badge-income' : 'mw-acct-badge-expense';
        const dupeTag = isDupe ? '<span class="badge bg-warning text-dark" style="font-size:9px">Probable duplicate</span>' : '';
        const autoTag = row.auto_cat ? '<span class="badge bg-light text-secondary" style="font-size:9px;border:1px solid #dee2e6">auto</span>' : '';

        return `<tr class="${rowClass}" data-idx="${i}">
            <td><input type="checkbox" class="row-check" data-idx="${i}" ${isDupe ? '' : 'checked'} onchange="updateSelectedCount()"></td>
            <td class="small">${row.date}</td>
            <td>
                <div class="small fw-bold">${esc(row.description)}</div>
                <div class="d-flex gap-1 mt-1">${dupeTag}${autoTag}</div>
            </td>
            <td><span class="badge ${typeClass}">${row.type}</span></td>
            <td class="text-end small fw-bold ${row.type === 'income' ? 'mw-acct-color-income' : 'mw-acct-color-expense'}">${fmtMoney(row.amount)}</td>
            <td>
                <select class="form-select form-select-sm account-sel" data-idx="${i}" onchange="updateRowAccount(this)">
                    ${accountOptions}
                </select>
            </td>
            <td class="small text-muted">${isDupe ? '<i class="text-warning">Skip</i>' : '<span class="text-success">Import</span>'}</td>
        </tr>`;
    }).join('');

    // Set selected accounts
    tbody.querySelectorAll('.account-sel').forEach(sel => {
        const idx = parseInt(sel.dataset.idx);
        sel.value = previewRows[idx]?.account_id || '';
    });

    // Re-init feather if present
    if (typeof feather !== 'undefined') feather.replace();
}

function updateRowAccount(sel) {
    const idx = parseInt(sel.dataset.idx);
    if (previewRows[idx]) {
        previewRows[idx].account_id = parseInt(sel.value);
        previewRows[idx].auto_cat   = false;
    }
}

function toggleAll(master) {
    document.querySelectorAll('.row-check').forEach(cb => cb.checked = master.checked);
    updateSelectedCount();
}

function updateSelectedCount() {
    const checked = document.querySelectorAll('.row-check:checked').length;
    document.getElementById('selected-count').textContent = `${checked} rows selected`;
}

async function commitImport() {
    const selectedIdxs = [];
    document.querySelectorAll('.row-check:checked').forEach(cb => selectedIdxs.push(parseInt(cb.dataset.idx)));

    if (!selectedIdxs.length) { alert('Select at least one transaction to import.'); return; }

    const rowsToImport   = selectedIdxs.map(i => previewRows[i]).filter(Boolean);
    const skipDuplicates = document.getElementById('skip-duplicates').checked;

    const r = await fetch(API, {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({
            action:           'commit',
            rows:             rowsToImport,
            bank_name:        document.getElementById('preset').value,
            account_name:     document.getElementById('account-name').value,
            skip_duplicates:  skipDuplicates,
        }),
    });
    const d = await r.json();
    if (!d.ok) { alert('Import failed: ' + d.error); return; }

    lastSessionId = d.result.session_id;
    const res     = d.result;

    document.getElementById('step-preview').style.display = 'none';
    document.getElementById('step-done').style.display    = '';
    document.getElementById('done-title').textContent     = '✓ Import Complete';
    document.getElementById('done-body').innerHTML =
        `<strong>${res.imported}</strong> transactions imported &nbsp;·&nbsp; <strong>${res.duplicates}</strong> duplicates skipped<br>
         <a href="/crm/accounting/transactions.php" class="text-muted small">View all transactions →</a>`;

    loadSessions();
}

async function undoImport() {
    if (!lastSessionId || !confirm('Undo this import? All imported transactions will be deleted.')) return;
    const r = await fetch(API, {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({ action: 'rollback', session_id: lastSessionId }),
    });
    const d = await r.json();
    if (d.ok) {
        alert(`Rolled back — ${d.deleted} transactions removed.`);
        resetUpload();
        loadSessions();
    } else {
        alert('Rollback failed: ' + d.error);
    }
}

function resetUpload() {
    document.getElementById('csv-file').value = '';
    document.getElementById('step-upload').style.display  = '';
    document.getElementById('step-preview').style.display = 'none';
    document.getElementById('step-done').style.display    = 'none';
    previewRows   = [];
    lastSessionId = null;
}

async function loadSessions() {
    const r = await fetch(API + '?action=sessions');
    const d = await r.json();
    if (!d.ok) return;

    const tbody = document.getElementById('sessions-tbody');
    if (!d.sessions.length) {
        tbody.innerHTML = '<tr><td colspan="7" class="text-muted text-center py-3">No imports yet.</td></tr>';
        return;
    }

    tbody.innerHTML = d.sessions.map(s => {
        const badge = {
            imported:     '<span class="badge bg-success">Imported</span>',
            pending:      '<span class="badge bg-warning text-dark">Pending</span>',
            rolled_back:  '<span class="badge bg-secondary">Rolled Back</span>',
        }[s.status] || s.status;

        return `<tr>
            <td class="small">${s.created_at?.substring(0, 10) || ''}</td>
            <td class="small">${esc(s.bank_name || '')} ${s.account_name ? '<span class="text-muted">(' + esc(s.account_name) + ')</span>' : ''}</td>
            <td class="small">${s.row_count}</td>
            <td class="small text-success">${s.imported_count}</td>
            <td class="small text-warning">${s.duplicate_count}</td>
            <td>${badge}</td>
            <td>
                ${s.status === 'imported'
                    ? `<button class="btn btn-xs btn-outline-danger" onclick="rollbackSession(${s.id})">Undo</button>`
                    : ''}
            </td>
        </tr>`;
    }).join('');
}

async function rollbackSession(sessionId) {
    if (!confirm('Undo this import batch? All imported transactions will be deleted.')) return;
    const r = await fetch(API, {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({ action: 'rollback', session_id: sessionId }),
    });
    const d = await r.json();
    alert(d.ok ? `Rolled back — ${d.deleted} transactions removed.` : 'Error: ' + d.error);
    loadSessions();
}

function fmtMoney(v) {
    return '$' + parseFloat(v || 0).toLocaleString('en-CA', {minimumFractionDigits:2, maximumFractionDigits:2});
}
function esc(s) {
    return String(s||'').replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;');
}

// Hide-duplicates toggle
document.addEventListener('change', e => {
    if (e.target.id === 'hide-duplicates') renderPreviewTable(previewRows);
});
</script>

<style>
.mw-acct-done-icon {
    width: 64px; height: 64px; border-radius: 50%;
    background: var(--mw-green); color: #fff;
    font-size: 2rem; line-height: 64px; text-align: center;
    margin: 0 auto;
}
</style>

<?php include dirname(__DIR__) . '/includes/appstack_footer.php'; ?>

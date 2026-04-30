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
        <p class="text-muted mb-0 small">Upload a bank statement — transactions are auto-categorized and de-duplicated</p>
    </div>
    <a href="/crm/accounting_appstack.php" class="btn btn-sm btn-outline-secondary">← Accounting</a>
</div>

<!-- ── Accounting Sub-Nav ─────────────────────────────────────────────────── -->
<div class="mw-filter-tabs mb-3">
    <a href="/crm/accounting_appstack.php"       class="mw-filter-tab">Dashboard</a>
    <a href="/crm/accounting/transactions.php"   class="mw-filter-tab">Transactions</a>
    <a href="/crm/accounting/bank-import.php"    class="mw-filter-tab active">Bank Import</a>
    <a href="/crm/accounting/reports.php"        class="mw-filter-tab">Reports</a>
    <a href="/crm/accounting/chart-of-accounts.php" class="mw-filter-tab">Chart of Accounts</a>
</div>

<!-- ── Step Indicator ────────────────────────────────────────────────────── -->
<div class="mw-bi-steps mb-3" id="step-indicator">
    <div class="mw-bi-step mw-bi-step--active" id="ind-1">
        <div class="mw-bi-step-num">1</div>
        <div>
            <div>Upload</div>
            <div class="small text-muted fw-normal" style="font-size:11px">Choose file &amp; account</div>
        </div>
    </div>
    <div class="mw-bi-step" id="ind-2">
        <div class="mw-bi-step-num">2</div>
        <div>
            <div>Review</div>
            <div class="small text-muted fw-normal" style="font-size:11px">Categorize &amp; confirm</div>
        </div>
    </div>
    <div class="mw-bi-step" id="ind-3">
        <div class="mw-bi-step-num">3</div>
        <div>
            <div>Done</div>
            <div class="small text-muted fw-normal" style="font-size:11px">Transactions added</div>
        </div>
    </div>
</div>

<!-- ══════════════════════════════════════════════════════════════════════════ -->
<!-- STEP 1 — Upload                                                            -->
<!-- ══════════════════════════════════════════════════════════════════════════ -->
<div id="step-upload" class="card mb-3">
    <div class="card-body">

        <!-- Drop zone -->
        <div class="mw-bi-dropzone mb-3" id="drop-zone" onclick="document.getElementById('csv-file').click()">
            <div class="mw-bi-dropzone-icon">
                <i data-feather="upload-cloud" style="width:22px;height:22px"></i>
            </div>
            <div class="mw-bi-dropzone-label" id="dropzone-label">Click to choose a file, or drag &amp; drop</div>
            <div class="mw-bi-dropzone-sub" id="dropzone-sub">CSV, PDF, or photo of your statement</div>
            <input type="file" id="csv-file" class="d-none"
                   accept=".csv,.txt,.pdf,.jpg,.jpeg,.png,.webp,.heic"
                   onchange="onFileSelected()">
        </div>

        <!-- Options row -->
        <div class="row g-3">
            <div class="col-md-4" id="preset-col">
                <label class="form-label small fw-semibold">Bank / Format</label>
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
                <label class="form-label small fw-semibold">Bank Account <span class="text-danger">*</span></label>
                <select id="bank-account-id" class="form-select">
                    <option value="">— Select account —</option>
                </select>
            </div>
        </div>

        <!-- Notices -->
        <div id="pdf-notice" class="mw-bi-notice mw-bi-notice--info d-none">
            <i data-feather="file-text" style="width:16px;height:16px;flex-shrink:0;margin-top:1px"></i>
            <div><strong>PDF detected.</strong> Transactions will be extracted automatically — no column mapping needed.
            Results may vary depending on your bank's PDF format.</div>
        </div>
        <div id="image-notice" class="mw-bi-notice mw-bi-notice--info d-none">
            <i data-feather="camera" style="width:16px;height:16px;flex-shrink:0;margin-top:1px"></i>
            <div><strong>Image detected.</strong> The statement will be scanned with OCR.
            For best results: flat, well-lit, text in focus.</div>
        </div>

        <!-- Custom mapping -->
        <div id="custom-mapping" class="mt-3 p-3 border rounded" style="display:none; background:var(--bs-light)">
            <p class="small text-muted mb-2">Zero-based column indices (0 = first column):</p>
            <div class="row g-2">
                <div class="col-4 col-md-2"><label class="form-label small">Date</label><input type="number" id="col-date" class="form-control form-control-sm" value="0" min="0"></div>
                <div class="col-4 col-md-2"><label class="form-label small">Description</label><input type="number" id="col-desc" class="form-control form-control-sm" value="1" min="0"></div>
                <div class="col-4 col-md-2"><label class="form-label small">Debit</label><input type="number" id="col-debit" class="form-control form-control-sm" placeholder="col#" min="0"></div>
                <div class="col-4 col-md-2"><label class="form-label small">Credit</label><input type="number" id="col-credit" class="form-control form-control-sm" placeholder="col#" min="0"></div>
                <div class="col-4 col-md-2"><label class="form-label small">Amount</label><input type="number" id="col-amount" class="form-control form-control-sm" placeholder="col#" min="0"></div>
                <div class="col-4 col-md-2"><label class="form-label small">Skip Rows</label><input type="number" id="skip-rows" class="form-control form-control-sm" value="1" min="0"></div>
            </div>
        </div>

        <!-- Processing indicator -->
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

        <div class="mt-3 d-flex align-items-center gap-2 flex-wrap">
            <button class="btn btn-primary" onclick="uploadAndPreview()" id="btn-preview">
                <i data-feather="upload" class="mw-btn-icon"></i> Upload &amp; Preview
            </button>
            <?php if (($user['role'] ?? '') === 'admin'): ?>
            <button class="btn btn-outline-secondary btn-sm" onclick="runDebugOcr()" id="btn-debug-ocr" title="Show raw OCR text and parse rejection log">
                <i data-feather="terminal" class="mw-btn-icon"></i> Debug OCR
            </button>
            <?php endif; ?>
        </div>
    </div>
</div>

<!-- Debug OCR modal (admin only) -->
<?php if (($user['role'] ?? '') === 'admin'): ?>
<div class="mw-modal-overlay" id="debug-ocr-modal" onclick="if(event.target===this)closeDebugOcr()">
    <div class="mw-modal" style="max-width:900px;max-height:90vh;overflow:hidden;display:flex;flex-direction:column">
        <div class="mw-modal-header d-flex justify-content-between align-items-center" style="flex-shrink:0">
            <h5 class="mb-0">OCR Debug Output</h5>
            <button class="btn btn-sm btn-outline-secondary" onclick="closeDebugOcr()">✕ Close</button>
        </div>
        <div style="overflow-y:auto;flex:1;padding:1rem">
            <div id="debug-ocr-loading" class="text-center py-4 d-none">
                <div class="spinner-border text-primary mb-2"></div>
                <div class="text-muted small">Running OCR debug — this may take 30–60s…</div>
            </div>
            <div id="debug-ocr-content"></div>
        </div>
    </div>
</div>
<?php endif; ?>

<!-- ══════════════════════════════════════════════════════════════════════════ -->
<!-- STEP 2 — Review & Categorize                                               -->
<!-- ══════════════════════════════════════════════════════════════════════════ -->
<div id="step-preview" style="display:none">
    <div class="card mb-3">
        <div class="card-header d-flex justify-content-between align-items-center flex-wrap gap-2">
            <div class="d-flex align-items-center gap-3">
                <h5 class="card-title mb-0">Review Transactions</h5>
                <span id="preview-summary" class="text-muted small"></span>
            </div>
            <button class="btn btn-xs btn-outline-secondary" onclick="resetUpload()">← Back</button>
        </div>
        <div class="card-body pb-0">

            <!-- Balance self-check -->
            <div id="balance-check-ok" class="mw-bi-balance-strip mw-bi-balance-strip--ok d-none">
                <div class="mw-bi-balance-icon">✓</div>
                <span id="balance-check-ok-msg"></span>
            </div>
            <div id="balance-check-warn" class="mw-bi-balance-strip mw-bi-balance-strip--warn d-none">
                <div class="mw-bi-balance-icon">!</div>
                <span id="balance-check-warn-msg"></span>
            </div>

            <!-- Match / dupe alerts -->
            <div id="dupe-alert" class="mw-bi-notice mw-bi-notice--info d-none mb-2">
                <i data-feather="info" style="width:15px;height:15px;flex-shrink:0;margin-top:1px"></i>
                <span id="dupe-msg"></span>
            </div>
            <div id="match-alert" class="mw-bi-notice mw-bi-notice--info d-none mb-2" style="background:rgb(45 134 89 / .07);border-color:rgb(45 134 89 / .2);color:#1a5f4a">
                <i data-feather="check-circle" style="width:15px;height:15px;flex-shrink:0;margin-top:1px"></i>
                <span id="match-msg"></span>
            </div>

            <!-- Filter tabs + skip-dupes control -->
            <div class="d-flex align-items-center gap-3 flex-wrap py-2 border-bottom mb-0">
                <div class="mw-filter-tabs" style="margin-bottom:0">
                    <button type="button" class="mw-filter-tab active" onclick="setFilter('all',this)">
                        All <span id="count-all" class="badge bg-secondary ms-1">0</span>
                    </button>
                    <button type="button" class="mw-filter-tab" onclick="setFilter('matched',this)">
                        Receipts matched <span id="count-matched" class="badge bg-success ms-1">0</span>
                    </button>
                    <button type="button" class="mw-filter-tab" onclick="setFilter('unmatched',this)">
                        Unmatched <span id="count-unmatched" class="badge bg-primary ms-1">0</span>
                    </button>
                    <button type="button" class="mw-filter-tab" onclick="setFilter('duplicate',this)">
                        Already imported <span id="count-dupe" class="badge bg-warning text-dark ms-1">0</span>
                    </button>
                </div>
                <div class="form-check form-check-inline ms-auto mb-0">
                    <input class="form-check-input" type="checkbox" id="skip-duplicates" checked>
                    <label class="form-check-label small" for="skip-duplicates">Skip already imported</label>
                </div>
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
                        <th style="width:140px">Receipt / Status</th>
                    </tr>
                </thead>
                <tbody id="preview-tbody"></tbody>
            </table>
        </div>

        <div class="card-footer d-flex justify-content-between align-items-center py-2">
            <div class="d-flex align-items-center gap-3">
                <span class="text-muted small" id="selected-count">0 rows selected</span>
                <button class="btn btn-xs btn-outline-secondary" onclick="addManualRow()" title="Add a transaction that wasn't parsed from the statement">
                    <i data-feather="plus" class="mw-btn-icon"></i> Add missing row
                </button>
            </div>
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

<!-- ── Import History ─────────────────────────────────────────────────────── -->
<div class="card">
    <div class="card-header d-flex justify-content-between align-items-center">
        <h5 class="card-title mb-0">Import History</h5>
        <button class="btn btn-sm btn-outline-secondary" onclick="loadSessions()">Refresh</button>
    </div>
    <div class="card-body p-0">
        <table class="table table-sm mw-acct-table mb-0">
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
const API     = '/crm/api/accounting-bank-import.php';
const API_ACC = '/crm/api/accounting-accounts.php';

let previewRows      = [];
let lastSessionId    = null;
let allAccounts      = [];
let lastBalanceCheck = null;

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
    initDropZone();
});

function initDropZone() {
    const zone = document.getElementById('drop-zone');
    zone.addEventListener('dragover',  e => { e.preventDefault(); zone.classList.add('mw-bi-dropzone--over'); });
    zone.addEventListener('dragleave', e => { zone.classList.remove('mw-bi-dropzone--over'); });
    zone.addEventListener('drop', e => {
        e.preventDefault();
        zone.classList.remove('mw-bi-dropzone--over');
        const dt = e.dataTransfer;
        if (dt && dt.files.length) {
            document.getElementById('csv-file').files = dt.files;
            onFileSelected();
        }
    });
}

async function loadAccounts() {
    const r = await fetch(API_ACC + '?action=list');
    const d = await r.json();
    if (!d.ok) return;
    allAccounts = d.accounts;
    const sel = document.getElementById('bank-account-id');
    d.accounts.filter(a => a.sub_type === 'bank').forEach(a => {
        sel.insertAdjacentHTML('beforeend',
            `<option value="${a.id}">${a.code} – ${a.name}</option>`);
    });
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

    // Update drop zone label
    document.getElementById('dropzone-label').textContent = file.name;
    document.getElementById('dropzone-sub').textContent = isPdf
        ? 'PDF bank statement — auto-extracted'
        : isImg
            ? 'Statement photo — will be scanned with OCR'
            : 'CSV file — select your bank format below';

    document.getElementById('pdf-notice').classList.toggle('d-none', !isPdf);
    document.getElementById('image-notice').classList.toggle('d-none', !isImg);
    document.getElementById('preset-col').style.display = isAuto ? 'none' : '';

    if (isAuto) {
        document.getElementById('custom-mapping').style.display = 'none';
        if (typeof feather !== 'undefined') feather.replace();
        return;
    }

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
    if (!file) { mwToast('Please choose a file first.', 'warning'); return; }

    const preset        = document.getElementById('preset').value;
    const bankAccountId = parseInt(document.getElementById('bank-account-id').value);
    if (!bankAccountId) { mwToast('Please select which bank account this statement is for.', 'warning'); return; }

    const form = new FormData();
    form.append('action',          'preview');
    form.append('csv',             file);
    form.append('preset',          preset);
    form.append('bank_name',       preset);
    form.append('bank_account_id', bankAccountId);

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

    let pct = 8;
    const progressTimer = setInterval(() => {
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
        if (!d.ok) throw new Error(d.error || 'Unknown error');

        barEl.style.width = '100%';
        statusEl.textContent = 'Done — loading preview…';

        previewRows = d.preview.rows;
        renderPreview(d.preview);

        document.getElementById('step-upload').style.display  = 'none';
        document.getElementById('step-preview').style.display = '';
        document.getElementById('step-done').style.display    = 'none';
        setStepIndicator(2);

    } catch (err) {
        mwToast('Preview failed: ' + err.message, 'error');
    } finally {
        clearInterval(progressTimer);
        indicator.classList.add('d-none');
        btn.disabled = false;
        btn.innerHTML = '<i data-feather="upload" class="mw-btn-icon"></i> Upload &amp; Preview';
        if (typeof feather !== 'undefined') feather.replace();
    }
}

function setStepIndicator(active) {
    [1, 2, 3].forEach(n => {
        const el = document.getElementById('ind-' + n);
        el.classList.remove('mw-bi-step--active', 'mw-bi-step--done');
        if (n < active)      el.classList.add('mw-bi-step--done');
        else if (n === active) el.classList.add('mw-bi-step--active');
    });
}

let activeFilter = 'all';

function setFilter(filter, btn) {
    activeFilter = filter;
    document.querySelectorAll('.mw-filter-tab[onclick]').forEach(b => b.classList.remove('active'));
    if (btn) btn.classList.add('active');
    renderPreviewTable(previewRows);
}

function renderPreview(preview) {
    const totals    = preview.totals;
    const matched   = totals.matched  || 0;
    const dupes     = totals.duplicates || 0;
    const unmatched = totals.rows - dupes - matched;

    const rows           = preview.rows || [];
    const receiptMatches   = rows.filter(r => !r.is_duplicate && r.match_candidate && r.matched_expense).length;
    const etransferMatches = rows.filter(r => !r.is_duplicate && r.match_candidate && r.matched_invoice && r.match_method === 'etransfer').length;
    const processorMatches = rows.filter(r => !r.is_duplicate && r.match_candidate && r.matched_invoice && r.match_method !== 'etransfer').length;

    let summaryHtml = `${totals.rows} rows &nbsp;·&nbsp; <span class="text-success">+${fmtMoney(totals.income)}</span> &nbsp;·&nbsp; <span class="text-danger">−${fmtMoney(totals.expense)}</span>`;
    if (etransferMatches > 0) summaryHtml += ` &nbsp;·&nbsp; <span class="text-success fw-bold">✓ ${etransferMatches} e-Transfer${etransferMatches > 1 ? 's' : ''} matched</span>`;
    if (processorMatches > 0) summaryHtml += ` &nbsp;·&nbsp; <span class="text-success fw-bold">✓ ${processorMatches} payment${processorMatches > 1 ? 's' : ''} matched</span>`;
    if (receiptMatches   > 0) summaryHtml += ` &nbsp;·&nbsp; <span class="text-success fw-bold">✓ ${receiptMatches} receipt${receiptMatches > 1 ? 's' : ''} matched</span>`;
    if (dupes            > 0) summaryHtml += ` &nbsp;·&nbsp; <span class="text-warning">${dupes} already imported</span>`;
    document.getElementById('preview-summary').innerHTML = summaryHtml;

    document.getElementById('count-all').textContent       = totals.rows;
    document.getElementById('count-matched').textContent   = matched;
    document.getElementById('count-unmatched').textContent = unmatched;
    document.getElementById('count-dupe').textContent      = dupes;

    // Balance self-check
    lastBalanceCheck = preview.balance_check || null;
    const bc    = preview.balance_check || {};
    const bcOk  = document.getElementById('balance-check-ok');
    const bcW   = document.getElementById('balance-check-warn');
    bcOk.classList.add('d-none');
    bcW.classList.add('d-none');
    if (bc.available) {
        const fmt = v => v != null
            ? '$' + parseFloat(v).toLocaleString('en-CA', {minimumFractionDigits:2, maximumFractionDigits:2})
            : '?';
        if (bc.matches) {
            document.getElementById('balance-check-ok-msg').innerHTML =
                `Balances verified &nbsp;·&nbsp; Opening <strong>${fmt(bc.opening)}</strong> → Closing <strong>${fmt(bc.closing)}</strong> &nbsp;·&nbsp; Computed ${fmt(bc.computed)} ✓`;
            bcOk.classList.remove('d-none');
        } else {
            const discAbs = Math.abs(parseFloat(bc.discrepancy || 0));
            const isLarge = discAbs > 50;
            bcW.className = 'mw-bi-balance-strip ' + (isLarge ? 'mw-bi-balance-strip--err' : 'mw-bi-balance-strip--warn');
            document.getElementById('balance-check-warn-msg').innerHTML =
                `Balance mismatch: Opening <strong>${fmt(bc.opening)}</strong>, closing <strong>${fmt(bc.closing)}</strong>, computed <strong>${fmt(bc.computed)}</strong> (off by <strong>${fmt(bc.discrepancy)}</strong>).`
                + (isLarge ? ' Some transactions may not have been parsed — review before importing.' : '');
            bcW.classList.remove('d-none');
        }
    }

    if (dupes > 0) {
        document.getElementById('dupe-msg').textContent =
            `${dupes} transaction${dupes > 1 ? 's were' : ' was'} already imported — unchecked by default.`;
        document.getElementById('dupe-alert').classList.remove('d-none');
    } else {
        document.getElementById('dupe-alert').classList.add('d-none');
    }
    if (matched > 0) {
        let matchMsg = '';
        if (etransferMatches > 0) matchMsg += `${etransferMatches} e-Transfer${etransferMatches > 1 ? 's' : ''} matched to invoice${etransferMatches > 1 ? 's' : ''}. `;
        if (processorMatches > 0) matchMsg += `${processorMatches} processor payment${processorMatches > 1 ? 's' : ''} matched to invoice${processorMatches > 1 ? 's' : ''} (net of fees). `;
        if (receiptMatches   > 0) matchMsg += `${receiptMatches} bank transaction${receiptMatches > 1 ? 's' : ''} matched to expense receipts. `;
        matchMsg += 'Importing will mark them as reconciled ✓';
        document.getElementById('match-msg').textContent = matchMsg;
        document.getElementById('match-alert').classList.remove('d-none');
    } else {
        document.getElementById('match-alert').classList.add('d-none');
    }

    renderPreviewTable(preview.rows);
    updateSelectedCount();
    if (typeof feather !== 'undefined') feather.replace();
}

function renderPreviewTable(rows) {
    const tbody = document.getElementById('preview-tbody');

    const accountOptions = allAccounts
        .filter(a => a.sub_type !== 'header')
        .map(a => `<option value="${a.id}">${a.code} – ${a.name}</option>`)
        .join('');

    tbody.innerHTML = rows.map((row, i) => {
        // Manually added rows — always show, always editable
        if (row.manual) {
            return `<tr class="mw-bi-manual-row" data-idx="${i}">
                <td><input type="checkbox" class="row-check" data-idx="${i}" checked onchange="updateSelectedCount()"></td>
                <td><input type="date" class="form-control form-control-sm" value="${esc(row.date||'')}" oninput="updateManualRow(${i},'date',this.value)"></td>
                <td><input type="text" class="form-control form-control-sm" placeholder="Description" value="${esc(row.description||'')}" oninput="updateManualRow(${i},'description',this.value)"></td>
                <td>
                    <select class="form-select form-select-sm" onchange="updateManualRow(${i},'type',this.value)">
                        <option value="expense" ${row.type==='expense'?'selected':''}>expense</option>
                        <option value="income"  ${row.type==='income' ?'selected':''}>income</option>
                    </select>
                </td>
                <td><input type="number" class="form-control form-control-sm text-end" step="0.01" min="0" placeholder="0.00" value="${row.amount||''}" oninput="updateManualRow(${i},'amount',parseFloat(this.value)||0)"></td>
                <td>
                    <select class="form-select form-select-sm account-sel" data-idx="${i}" onchange="updateRowAccount(this)">
                        ${accountOptions}
                    </select>
                </td>
                <td><span class="badge bg-warning text-dark" style="font-size:9px">Manual</span></td>
            </tr>`;
        }

        const isDupe    = row.is_duplicate;
        const isMatched = !isDupe && row.match_candidate;

        if (activeFilter === 'matched'   && !isMatched)            return '';
        if (activeFilter === 'duplicate' && !isDupe)               return '';
        if (activeFilter === 'unmatched' && (isDupe || isMatched)) return '';

        const typeClass = row.type === 'income' ? 'mw-acct-badge-income' : 'mw-acct-badge-expense';
        const autoTag   = row.auto_cat ? '<span class="badge bg-light text-secondary" style="font-size:9px;border:1px solid #dee2e6">auto</span>' : '';
        const amtClass  = row.type === 'income' ? 'mw-acct-color-income' : 'mw-acct-color-expense';

        let statusCell = '';
        if (isDupe) {
            statusCell = '<span class="badge bg-warning text-dark" style="font-size:9px">Already imported</span>';
        } else if (isMatched && row.matched_invoice) {
            const inv    = row.matched_invoice;
            const conf   = row.match_confidence || 0;
            const fee    = row.processing_fee || 0;
            const method = row.match_method || '';

            let mainBadge;
            if (method === 'reference') {
                mainBadge = '<span class="badge bg-success" style="font-size:9px">✓ Exact match</span>';
            } else if (method === 'etransfer') {
                mainBadge = `<span class="badge bg-success" style="font-size:9px">✓ e-Transfer matched</span>
                             <span class="badge bg-light text-secondary border ms-1" style="font-size:9px">${conf}%</span>`;
            } else {
                mainBadge = `<span class="badge bg-success" style="font-size:9px">✓ Invoice matched</span>
                             <span class="badge bg-light text-secondary border ms-1" style="font-size:9px">${conf}%</span>`;
            }

            const refChip = inv.payment_reference
                ? `<span class="mw-tx-detail-docref ms-1" style="font-size:9px">${esc(inv.payment_reference.slice(0,14))}</span>`
                : '';
            const feeNote = fee > 0
                ? ` &nbsp;·&nbsp; <span class="text-secondary">−${fmtMoney(fee)} fee</span>` : '';

            statusCell = `<div class="d-flex flex-wrap align-items-center gap-1">
                            ${mainBadge}${refChip}
                          </div>
                          <div class="small fw-bold mt-1">${esc(inv.invoice_number||'')}</div>
                          <div class="small text-muted" style="font-size:10px">${esc(inv.client_name||'')}
                            &nbsp;·&nbsp; ${fmtMoney(inv.invoice_amount||0)}${feeNote}
                          </div>`;
        } else if (isMatched && row.matched_expense) {
            const exp  = row.matched_expense || {};
            const conf = row.match_confidence || 0;
            const thumb = exp.receipt_path
                ? `<img src="${esc(exp.receipt_path)}" style="height:30px;border-radius:3px;margin-top:3px;display:block">`
                : '';
            statusCell = `<span class="badge bg-success" style="font-size:9px">✓ Receipt matched</span>
                          <span class="badge bg-light text-secondary border ms-1" style="font-size:9px">${conf}%</span>
                          <div class="small text-muted mt-1" style="font-size:10px">${esc(exp.vendor_name||'')}</div>${thumb}`;
        } else {
            statusCell = '<span class="text-success small">Import</span>';
        }

        return `<tr class="${isDupe ? 'mw-acct-row-review' : isMatched ? 'table-success' : ''}" data-idx="${i}">
            <td><input type="checkbox" class="row-check" data-idx="${i}" ${isDupe ? '' : 'checked'} onchange="updateSelectedCount()"></td>
            <td class="small">${row.date}</td>
            <td>
                <div class="small fw-bold">${esc(row.description)}</div>
                <div class="d-flex gap-1 mt-1">${autoTag}</div>
            </td>
            <td><span class="badge ${typeClass}">${row.type}</span></td>
            <td class="text-end small fw-bold ${amtClass}">${fmtMoney(row.amount)}</td>
            <td>
                <select class="form-select form-select-sm account-sel" data-idx="${i}" onchange="updateRowAccount(this)" ${isDupe ? 'disabled' : ''}>
                    ${accountOptions}
                </select>
            </td>
            <td class="small">${statusCell}</td>
        </tr>`;
    }).join('');

    tbody.querySelectorAll('.account-sel').forEach(sel => {
        const idx = parseInt(sel.dataset.idx);
        sel.value = previewRows[idx]?.account_id || '';
    });

    if (typeof feather !== 'undefined') feather.replace();
}

function updateRowAccount(sel) {
    const idx = parseInt(sel.dataset.idx);
    if (previewRows[idx]) {
        previewRows[idx].account_id = parseInt(sel.value);
        previewRows[idx].auto_cat   = false;
    }
}

function updateManualRow(idx, field, value) {
    if (previewRows[idx]) previewRows[idx][field] = value;
    if (field === 'amount' || field === 'type') updateBalanceStrip();
}

function updateBalanceStrip() {
    const bc = lastBalanceCheck;
    if (!bc || !bc.available || bc.opening == null || bc.closing == null) return;
    const fmt = v => '$' + parseFloat(v).toLocaleString('en-CA', {minimumFractionDigits:2, maximumFractionDigits:2});
    let computed = parseFloat(bc.opening);
    for (const r of previewRows) {
        if (r.is_duplicate) continue;
        const amt = parseFloat(r.amount) || 0;
        computed += r.type === 'income' ? amt : -amt;
    }
    computed = Math.round(computed * 100) / 100;
    const closing = parseFloat(bc.closing);
    const disc    = Math.round((computed - closing) * 100) / 100;
    const bcOk = document.getElementById('balance-check-ok');
    const bcW  = document.getElementById('balance-check-warn');
    if (Math.abs(disc) < 0.02) {
        bcOk.classList.remove('d-none');
        bcW.classList.add('d-none');
        document.getElementById('balance-check-ok-msg').innerHTML =
            `Balances verified &nbsp;·&nbsp; Opening <strong>${fmt(bc.opening)}</strong> → Closing <strong>${fmt(bc.closing)}</strong> &nbsp;·&nbsp; Computed ${fmt(computed)} ✓`;
    } else {
        bcOk.classList.add('d-none');
        bcW.classList.remove('d-none');
        const isLarge = Math.abs(disc) > 50;
        bcW.className = 'mw-bi-balance-strip ' + (isLarge ? 'mw-bi-balance-strip--err' : 'mw-bi-balance-strip--warn');
        document.getElementById('balance-check-warn-msg').innerHTML =
            `Balance mismatch: Opening <strong>${fmt(bc.opening)}</strong>, closing <strong>${fmt(bc.closing)}</strong>, `
            + `computed <strong>${fmt(computed)}</strong> (off by <strong>${fmt(disc)}</strong>).`
            + (isLarge ? ' Some transactions may not have been parsed — review before importing.' : '');
    }
}

function addManualRow() {
    const today = new Date().toISOString().slice(0, 10);
    const defaultAcct = allAccounts.find(a => a.sub_type !== 'header');
    previewRows.push({
        date: today, description: '', amount: 0,
        type: 'expense', account_id: defaultAcct ? defaultAcct.id : null,
        is_duplicate: false, manual: true,
    });
    renderPreviewTable(previewRows);
    updateSelectedCount();
    // Scroll to the new row and focus its date input
    const tbl = document.querySelector('#preview-tbody').closest('.table-responsive');
    if (tbl) tbl.scrollTop = tbl.scrollHeight;
    const manualRows = document.querySelectorAll('.mw-bi-manual-row');
    if (manualRows.length) {
        manualRows[manualRows.length - 1].querySelector('input[type=date]')?.focus();
    }
    updateBalanceStrip();
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
    if (!selectedIdxs.length) { mwToast('Select at least one transaction to import.', 'warning'); return; }

    const rowsToImport   = selectedIdxs.map(i => previewRows[i]).filter(Boolean);
    const skipDuplicates = document.getElementById('skip-duplicates').checked;

    const r = await fetch(API, {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({
            action:          'commit',
            rows:            rowsToImport,
            bank_name:       document.getElementById('preset').value,
            bank_account_id: parseInt(document.getElementById('bank-account-id').value) || 0,
            skip_duplicates: skipDuplicates,
        }),
    });
    const d = await r.json();
    if (!d.ok) { mwToast('Import failed: ' + d.error, 'error'); return; }

    lastSessionId = d.result.session_id;
    const res     = d.result;

    document.getElementById('step-preview').style.display = 'none';
    document.getElementById('step-done').style.display    = '';
    setStepIndicator(3);

    const reconciledLine = res.reconciled > 0
        ? ` &nbsp;·&nbsp; <strong class="text-success">${res.reconciled} reconciled ✓</strong>` : '';
    document.getElementById('done-title').textContent   = 'Import Complete';
    document.getElementById('done-body').innerHTML =
        `<strong>${res.imported}</strong> transactions imported${reconciledLine} &nbsp;·&nbsp; <strong>${res.duplicates}</strong> skipped<br>
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
        mwToast(`Rolled back — ${d.deleted} transactions removed.`, 'success');
        resetUpload();
        loadSessions();
    } else {
        mwToast('Rollback failed: ' + d.error, 'error');
    }
}

function resetUpload() {
    document.getElementById('csv-file').value = '';
    document.getElementById('dropzone-label').textContent = 'Click to choose a file, or drag & drop';
    document.getElementById('dropzone-sub').textContent   = 'CSV, PDF, or photo of your statement';
    document.getElementById('step-upload').style.display  = '';
    document.getElementById('step-preview').style.display = 'none';
    document.getElementById('step-done').style.display    = 'none';
    setStepIndicator(1);
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
            imported:    '<span class="badge bg-success">Imported</span>',
            pending:     '<span class="badge bg-warning text-dark">Pending</span>',
            rolled_back: '<span class="badge bg-secondary">Rolled Back</span>',
        }[s.status] || s.status;

        return `<tr>
            <td class="small">${s.created_at?.substring(0,10)||''}</td>
            <td class="small">${esc(s.bank_name||'')} ${s.account_name?'<span class="text-muted">('+esc(s.account_name)+')</span>':''}</td>
            <td class="small">${s.row_count}</td>
            <td class="small text-success">${s.imported_count}</td>
            <td class="small text-warning">${s.duplicate_count}</td>
            <td>${badge}</td>
            <td>${s.status==='imported'?`<button class="btn btn-xs btn-outline-danger" onclick="rollbackSession(${s.id})">Undo</button>`:''}</td>
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
    mwToast(d.ok ? `Rolled back — ${d.deleted} transactions removed.` : 'Error: ' + d.error, d.ok ? 'success' : 'error');
    loadSessions();
}

function fmtMoney(v) {
    return '$' + parseFloat(v||0).toLocaleString('en-CA', {minimumFractionDigits:2, maximumFractionDigits:2});
}
function esc(s) {
    return String(s||'').replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;');
}

document.addEventListener('change', e => {
    if (e.target.id === 'skip-duplicates') updateSelectedCount();
});

// ── Debug OCR (admin only) ────────────────────────────────────────────────────
async function runDebugOcr() {
    const fileInput = document.getElementById('csv-file');
    if (!fileInput.files.length) { mwToast('Select a PDF file first', 'warning'); return; }
    const file = fileInput.files[0];
    if (!file.name.toLowerCase().endsWith('.pdf')) { mwToast('Debug OCR only works with PDF files', 'warning'); return; }

    document.getElementById('debug-ocr-modal').classList.add('active');
    const loading = document.getElementById('debug-ocr-loading');
    const content = document.getElementById('debug-ocr-content');
    loading.classList.remove('d-none');
    content.innerHTML = '';

    const fd = new FormData();
    fd.append('file', file);
    fd.append('action', 'debug_ocr');

    let data;
    try {
        const resp = await fetch('/app/Modules/Accounting/Api/bank-import.php', { method: 'POST', body: fd });
        data = await resp.json();
    } catch (err) {
        loading.classList.add('d-none');
        content.innerHTML = `<div class="alert alert-danger">Request failed: ${esc(err.message)}</div>`;
        return;
    }
    loading.classList.add('d-none');

    if (!data.ok) {
        content.innerHTML = `<div class="alert alert-danger">${esc(data.error)}</div>`;
        return;
    }

    const d = data.debug;
    const rejectCount = (d.reject_log || []).length;
    const rowCount    = (d.rows || []).length;
    const pageCount   = Object.keys(d.raw_pages || {}).length;

    let html = `<div class="alert alert-info mb-3">
        <strong>${rowCount}</strong> transactions parsed · <strong>${rejectCount}</strong> lines rejected · <strong>${pageCount}</strong> pages OCR'd ·
        noise dropped: <strong>${d.noise_lines_dropped || 0}</strong>
    </div>`;

    // Rejection log
    if (rejectCount > 0) {
        html += `<h6 class="mb-2">Rejected Lines (${rejectCount})</h6>
        <div class="table-responsive mb-4">
        <table class="table table-sm table-bordered" style="font-size:0.75rem;font-family:monospace">
        <thead class="table-danger"><tr><th>Reason</th><th>Line</th><th>Extra</th></tr></thead><tbody>`;
        for (const r of (d.reject_log || [])) {
            const extra = Object.entries(r).filter(([k]) => k !== 'line' && k !== 'reason').map(([k,v]) => `${k}=${v}`).join(', ');
            html += `<tr><td class="text-nowrap"><strong>${esc(r.reason)}</strong></td><td>${esc(r.line)}</td><td class="text-muted">${esc(extra)}</td></tr>`;
        }
        html += '</tbody></table></div>';
    } else {
        html += `<div class="alert alert-success mb-3">No lines rejected — all parsed lines are valid transactions. Missing rows are absent from OCR output.</div>`;
    }

    // Raw OCR per page (collapsible)
    html += `<h6 class="mb-2">Raw OCR Text per Page</h6>`;
    for (const [pageNum, rawText] of Object.entries(d.raw_pages || {})) {
        const lineCount = (rawText || '').split('\n').length;
        html += `<details class="mb-2">
            <summary class="fw-semibold" style="cursor:pointer">Page ${pageNum} — ${lineCount} lines</summary>
            <pre class="mt-2 p-2 border rounded" style="font-size:0.7rem;max-height:400px;overflow-y:auto;white-space:pre-wrap">${esc(rawText)}</pre>
        </details>`;
    }

    // Joined lines (after wrap-join step)
    html += `<h6 class="mb-2 mt-3">Lines After Wrap-Join (${(d.joined_lines||[]).length})</h6>
    <details><summary style="cursor:pointer">Expand</summary>
    <pre class="mt-2 p-2 border rounded" style="font-size:0.7rem;max-height:500px;overflow-y:auto;white-space:pre-wrap">${esc((d.joined_lines||[]).join('\n'))}</pre>
    </details>`;

    content.innerHTML = html;
}

function closeDebugOcr() {
    document.getElementById('debug-ocr-modal').classList.remove('active');
}
</script>

<?php include dirname(__DIR__) . '/includes/appstack_footer.php'; ?>

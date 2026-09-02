<?php
require_once dirname(__DIR__) . '/../loginAuth/auth.php';
require_once dirname(__DIR__) . '/includes/functions.php';
requireLogin();
requirePermission('expenses.view');

$pageTitle  = 'Financial Reports';
$activePage = 'accounting';
?>
<?php include dirname(__DIR__) . '/includes/appstack_head.php'; ?>

<div class="mw-page-header d-flex justify-content-between align-items-center flex-wrap gap-2 mb-3">
    <div>
        <h1 class="h3 mb-0">Financial Reports</h1>
        <p class="text-muted mb-0 small">Profit &amp; Loss · Cash Flow · GST Filing · Job Profitability · Budget vs Actual · Crew</p>
    </div>
    <a href="/crm/accounting_appstack.php" class="btn btn-sm btn-outline-secondary">← Dashboard</a>
</div>

<!-- ── Report Tabs ──────────────────────────────────────────────────────────── -->
<div class="mw-filter-tabs mb-3">
    <button class="mw-filter-tab active" id="tab-pl"       onclick="switchTab('pl')">Profit &amp; Loss</button>
    <button class="mw-filter-tab"        id="tab-cashflow" onclick="switchTab('cashflow')">Cash Flow</button>
    <button class="mw-filter-tab"        id="tab-tax"      onclick="switchTab('tax')">GST/HST Filing</button>
    <button class="mw-filter-tab"        id="tab-jobs"     onclick="switchTab('jobs')">Job Profitability</button>
    <button class="mw-filter-tab"        id="tab-expense"  onclick="switchTab('expense')">Expense Breakdown</button>
    <button class="mw-filter-tab"        id="tab-budget"   onclick="switchTab('budget')">Budget vs Actual</button>
    <button class="mw-filter-tab"        id="tab-crew"     onclick="switchTab('crew')">Crew Profitability</button>
</div>

<!-- ── Date Range Controls ──────────────────────────────────────────────────── -->
<div class="card mb-3" id="date-controls">
    <div class="card-body py-2">
        <div class="row g-2 align-items-end">
            <div class="col-auto">
                <div class="btn-group btn-group-sm" role="group">
                    <button class="btn btn-outline-secondary" onclick="setPreset('this-month')">This Month</button>
                    <button class="btn btn-outline-secondary" onclick="setPreset('last-month')">Last Month</button>
                    <button class="btn btn-outline-secondary" onclick="setPreset('this-quarter')">This Quarter</button>
                    <button class="btn btn-outline-secondary" onclick="setPreset('ytd')">YTD</button>
                    <button class="btn btn-outline-secondary" onclick="setPreset('last-year')">Last Year</button>
                </div>
            </div>
            <div class="col-auto">
                <button type="button" class="mw-datepicker-trigger" data-mw-dp-commit="input" data-mw-dp-target="#r-date-from"
                        data-mw-dp-range-group="r-report-range" data-mw-dp-range-role="start" aria-haspopup="true" aria-expanded="false">
                    <svg class="mw-datepicker-cal-icon" width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="3" y="4" width="18" height="18" rx="2" ry="2"/><line x1="16" y1="2" x2="16" y2="6"/><line x1="8" y1="2" x2="8" y2="6"/><line x1="3" y1="10" x2="21" y2="10"/></svg>
                    <span class="mw-datepicker-date" data-mw-dp-label></span>
                    <svg class="mw-datepicker-chevron" width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><polyline points="6 9 12 15 18 9"/></svg>
                </button>
                <input type="date" id="r-date-from" class="form-control form-control-sm" hidden value="<?= date('Y-m-01') ?>">
            </div>
            <div class="col-auto">
                <span class="text-muted">to</span>
            </div>
            <div class="col-auto">
                <button type="button" class="mw-datepicker-trigger" data-mw-dp-commit="input" data-mw-dp-target="#r-date-to"
                        data-mw-dp-range-group="r-report-range" data-mw-dp-range-role="end" aria-haspopup="true" aria-expanded="false">
                    <svg class="mw-datepicker-cal-icon" width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="3" y="4" width="18" height="18" rx="2" ry="2"/><line x1="16" y1="2" x2="16" y2="6"/><line x1="8" y1="2" x2="8" y2="6"/><line x1="3" y1="10" x2="21" y2="10"/></svg>
                    <span class="mw-datepicker-date" data-mw-dp-label></span>
                    <svg class="mw-datepicker-chevron" width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><polyline points="6 9 12 15 18 9"/></svg>
                </button>
                <input type="date" id="r-date-to" class="form-control form-control-sm" hidden value="<?= date('Y-m-d') ?>">
            </div>
            <div class="col-auto" id="quarter-controls" style="display:none">
                <select id="r-quarter" class="form-select form-select-sm" onchange="loadCurrentReport()">
                    <option value="1">Q1 (Jan–Mar)</option>
                    <option value="2">Q2 (Apr–Jun)</option>
                    <option value="3">Q3 (Jul–Sep)</option>
                    <option value="4">Q4 (Oct–Dec)</option>
                </select>
                <select id="r-year" class="form-select form-select-sm ms-1" onchange="loadCurrentReport()">
                    <?php for ($y = date('Y'); $y >= date('Y') - 5; $y--): ?>
                    <option value="<?= $y ?>" <?= $y == date('Y') ? 'selected' : '' ?>><?= $y ?></option>
                    <?php endfor; ?>
                </select>
            </div>
            <div class="col-auto">
                <button class="btn btn-primary btn-sm" onclick="loadCurrentReport()">
                    <i data-feather="search" class="mw-btn-icon"></i> Run
                </button>
                <button class="btn btn-outline-secondary btn-sm" onclick="exportCSV()">
                    <i data-feather="download" class="mw-btn-icon"></i> Export
                </button>
            </div>
        </div>
    </div>
</div>

<!-- ══════════════════════════════════════════════════════════════════════════ -->
<!-- REPORT PANELS                                                              -->
<!-- ══════════════════════════════════════════════════════════════════════════ -->
<div id="report-loading" class="text-center text-muted py-5 d-none">
    <i data-feather="loader" style="width:32px;height:32px"></i>
    <p class="mt-2">Loading report…</p>
</div>

<!-- ── P&L Report ──────────────────────────────────────────────────────────── -->
<div id="panel-pl">

    <!-- Summary bar -->
    <div class="row g-3 mb-3" id="pl-summary-row"></div>

    <div class="row g-3">

        <!-- Revenue -->
        <div class="col-md-6">
            <div class="card">
                <div class="card-header d-flex justify-content-between align-items-center py-2">
                    <h6 class="card-title mb-0 text-success">Revenue</h6>
                    <strong class="text-success" id="pl-total-rev">—</strong>
                </div>
                <div class="card-body p-0">
                    <table class="table table-sm mb-0">
                        <tbody id="pl-rev-rows"></tbody>
                    </table>
                </div>
            </div>
        </div>

        <!-- Expenses -->
        <div class="col-md-6">
            <div class="card">
                <div class="card-header d-flex justify-content-between align-items-center py-2">
                    <h6 class="card-title mb-0 text-danger">Expenses</h6>
                    <strong class="text-danger" id="pl-total-exp">—</strong>
                </div>
                <div class="card-body p-0">
                    <table class="table table-sm mb-0">
                        <tbody id="pl-exp-rows"></tbody>
                    </table>
                </div>
            </div>
        </div>

    </div>

    <!-- Net Profit row -->
    <div class="card mt-3" id="pl-net-card">
        <div class="card-body d-flex justify-content-between align-items-center py-3">
            <h5 class="mb-0">Net Profit</h5>
            <div class="text-end">
                <div class="h4 mb-0" id="pl-net-amount">—</div>
                <div class="small text-muted" id="pl-net-margin"></div>
            </div>
        </div>
    </div>

</div><!-- /panel-pl -->

<!-- ── Cash Flow Report ────────────────────────────────────────────────────── -->
<div id="panel-cashflow" style="display:none">
    <div class="card">
        <div class="card-header"><h5 class="card-title mb-0">Cash Flow Statement</h5></div>
        <div class="card-body">
            <table class="table">
                <tbody id="cf-rows">
                    <tr><td colspan="2" class="text-muted text-center">Run the report to see data.</td></tr>
                </tbody>
            </table>
        </div>
    </div>
</div>

<!-- ── GST/HST Tax Report ──────────────────────────────────────────────────── -->
<div id="panel-tax" style="display:none">
    <!-- CRA summary form -->
    <div class="row g-3 mb-3">
        <div class="col-md-8">
            <div class="card">
                <div class="card-header">
                    <h5 class="card-title mb-0">GST/HST Return — CRA Lines</h5>
                    <p class="text-muted small mb-0" id="tax-period-label">Select quarter and run report</p>
                </div>
                <div class="card-body">
                    <table class="table table-bordered mw-acct-tax-table">
                        <thead class="table-light">
                            <tr><th style="width:80px">Line</th><th>Description</th><th class="text-end" style="width:120px">Amount</th></tr>
                        </thead>
                        <tbody id="tax-cra-rows">
                            <tr><td colspan="3" class="text-muted text-center">Run the report to see data.</td></tr>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
        <div class="col-md-4">
            <div class="card h-100">
                <div class="card-header"><h6 class="card-title mb-0">ITC Detail (Expenses)</h6></div>
                <div class="card-body p-0">
                    <table class="table table-sm mb-0">
                        <thead><tr><th>Category</th><th class="text-end">GST Paid</th></tr></thead>
                        <tbody id="tax-itc-rows">
                            <tr><td colspan="2" class="text-muted text-center small py-3">—</td></tr>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </div>

    <!-- Year summary grid -->
    <div class="card">
        <div class="card-header"><h6 class="card-title mb-0">Annual GST Summary</h6></div>
        <div class="card-body p-0">
            <table class="table table-sm mb-0">
                <thead>
                    <tr>
                        <th>Quarter</th>
                        <th class="text-end">GST Collected</th>
                        <th class="text-end">ITCs</th>
                        <th class="text-end">Net Owing</th>
                        <th style="width:80px"></th>
                    </tr>
                </thead>
                <tbody id="tax-annual-rows">
                    <tr><td colspan="5" class="text-muted text-center py-3">—</td></tr>
                </tbody>
            </table>
        </div>
    </div>
</div>

<!-- ── Job Profitability ───────────────────────────────────────────────────── -->
<div id="panel-jobs" style="display:none">
    <div class="card">
        <div class="card-header d-flex justify-content-between">
            <h5 class="card-title mb-0">Job Profitability</h5>
            <span class="text-muted small">Revenue − direct expenses linked to each job</span>
        </div>
        <div class="card-body p-0">
            <div class="table-responsive">
                <table class="table table-hover mb-0">
                    <thead>
                        <tr>
                            <th>Job</th>
                            <th>Client</th>
                            <th class="text-end">Revenue</th>
                            <th class="text-end">Expenses</th>
                            <th class="text-end">Profit</th>
                            <th style="width:90px">Margin</th>
                        </tr>
                    </thead>
                    <tbody id="job-rows">
                        <tr><td colspan="6" class="text-center text-muted py-4">Run the report to see data.</td></tr>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
</div>

<!-- ── Expense Breakdown ───────────────────────────────────────────────────── -->
<div id="panel-expense" style="display:none">
    <div class="row g-3">
        <div class="col-md-7">
            <div class="card">
                <div class="card-header d-flex justify-content-between align-items-center">
                    <h5 class="card-title mb-0">Expenses by Category</h5>
                    <span class="text-muted small" id="exp-total-label"></span>
                </div>
                <div class="card-body p-0">
                    <table class="table table-sm mb-0">
                        <thead><tr><th>Account</th><th class="text-end">Amount</th><th style="width:70px" class="text-end">% of Total</th></tr></thead>
                        <tbody id="exp-rows">
                            <tr><td colspan="3" class="text-muted text-center py-4">Run the report to see data.</td></tr>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
        <div class="col-md-5">
            <div class="card">
                <div class="card-header"><h5 class="card-title mb-0">Breakdown</h5></div>
                <div class="card-body" id="exp-chart-wrap" style="min-height:240px;display:flex;align-items:center;justify-content:center;">
                    <span class="text-muted small">Run the report to see chart.</span>
                </div>
            </div>
        </div>
    </div>
</div>

<!-- ── Budget vs Actual ────────────────────────────────────────────────────── -->
<div id="panel-budget" style="display:none">
    <div class="card mb-3">
        <div class="card-header d-flex justify-content-between align-items-center">
            <h5 class="card-title mb-0">Budget vs Actual</h5>
            <div class="d-flex gap-2 align-items-center">
                <label class="form-label small mb-0">Month:</label>
                <input type="month" id="r-budget-month" class="form-control form-control-sm" value="<?= date('Y-m') ?>" style="width:150px" onchange="loadBudget()">
            </div>
        </div>
        <div class="card-body p-0">
            <table class="table table-hover mb-0">
                <thead>
                    <tr>
                        <th>Account / Category</th>
                        <th class="text-end" style="width:110px">Budget</th>
                        <th class="text-end" style="width:110px">Actual</th>
                        <th class="text-end" style="width:80px">Remaining</th>
                        <th style="width:140px">Progress</th>
                        <th style="width:70px"></th>
                    </tr>
                </thead>
                <tbody id="budget-rows">
                    <tr><td colspan="6" class="text-muted text-center py-4">Run the report to see budget data.<br><small>Set budgets in the <a href="/crm/expenses_appstack.php">Expenses</a> module.</small></td></tr>
                </tbody>
            </table>
        </div>
        <div class="card-footer">
            <div class="row g-3" id="budget-summary-row"></div>
        </div>
    </div>
</div>

<!-- ── Crew Profitability ───────────────────────────────────────────────────── -->
<div id="panel-crew" style="display:none">
    <div class="card">
        <div class="card-header d-flex justify-content-between align-items-center">
            <h5 class="card-title mb-0">Crew Profitability</h5>
            <span class="text-muted small">Revenue attributed to each crew member vs labour + direct costs</span>
        </div>
        <div class="card-body p-0">
            <table class="table table-hover mb-0">
                <thead>
                    <tr>
                        <th>Team Member</th>
                        <th class="text-end">Revenue</th>
                        <th class="text-end">Labour Cost</th>
                        <th class="text-end">Direct Expenses</th>
                        <th class="text-end">Net Contribution</th>
                        <th style="width:100px">Margin</th>
                    </tr>
                </thead>
                <tbody id="crew-rows">
                    <tr><td colspan="6" class="text-center text-muted py-4">Run the report to see crew data.<br><small>Crew revenue comes from job transactions linked to crew members.</small></td></tr>
                </tbody>
            </table>
        </div>
    </div>
</div>

<!-- ══════════════════════════════════════════════════════════════════════════ -->
<!-- JAVASCRIPT                                                                 -->
<!-- ══════════════════════════════════════════════════════════════════════════ -->
<script>
const API_RP  = '/crm/api/accounting-reports.php';
const API_BI  = '/crm/api/accounting-bank-import.php';
let activeTab   = 'pl';
let lastReportData = null;

// ── Presets ───────────────────────────────────────────────────────────────────
function setPreset(p) {
    const now   = new Date();
    const y     = now.getFullYear();
    const m     = now.getMonth(); // 0-indexed
    let from, to;
    const pad = n => String(n).padStart(2,'0');

    switch (p) {
        case 'this-month':
            from = `${y}-${pad(m+1)}-01`;
            to   = `${y}-${pad(m+1)}-${pad(new Date(y, m+1, 0).getDate())}`;
            break;
        case 'last-month':
            const lm = m === 0 ? 11 : m - 1;
            const ly = m === 0 ? y - 1 : y;
            from = `${ly}-${pad(lm+1)}-01`;
            to   = `${ly}-${pad(lm+1)}-${pad(new Date(ly, lm+1, 0).getDate())}`;
            break;
        case 'this-quarter':
            const q  = Math.floor(m / 3);
            from = `${y}-${pad(q*3+1)}-01`;
            to   = `${y}-${pad(q*3+3)}-${pad(new Date(y, q*3+3, 0).getDate())}`;
            break;
        case 'ytd':
            from = `${y}-01-01`;
            to   = `${y}-${pad(m+1)}-${pad(now.getDate())}`;
            break;
        case 'last-year':
            from = `${y-1}-01-01`;
            to   = `${y-1}-12-31`;
            break;
    }
    if (from) {
        const fromInput = document.getElementById('r-date-from');
        fromInput.value = from;
        fromInput.dispatchEvent(new Event('change', { bubbles: true }));
    }
    if (to) {
        const toInput = document.getElementById('r-date-to');
        toInput.value = to;
        toInput.dispatchEvent(new Event('change', { bubbles: true }));
    }
    loadCurrentReport();
}

// ── Tab Switching ─────────────────────────────────────────────────────────────
function switchTab(tab) {
    activeTab = tab;
    ['pl','cashflow','tax','jobs','expense','budget','crew'].forEach(t => {
        document.getElementById(`tab-${t}`)?.classList.toggle('active', t === tab);
        document.getElementById(`panel-${t}`).style.display = t === tab ? '' : 'none';
    });
    // GST needs different controls; budget/crew hide preset buttons
    const isTax    = tab === 'tax';
    const isBudget = tab === 'budget';
    const isCrew   = tab === 'crew';
    document.getElementById('date-controls').style.display = (isBudget) ? 'none' : '';
    document.getElementById('date-controls').querySelector('.btn-group').style.display = isTax ? 'none' : '';
    document.getElementById('quarter-controls').style.display = isTax ? '' : 'none';

    loadCurrentReport();
}

function loadCurrentReport() {
    switch (activeTab) {
        case 'pl':       loadPL();       break;
        case 'cashflow': loadCashFlow(); break;
        case 'tax':      loadTax();      break;
        case 'jobs':     loadJobs();     break;
        case 'expense':  loadExpenses(); break;
        case 'budget':   loadBudget();   break;
        case 'crew':     loadCrew();     break;
    }
}

function getDateRange() {
    return {
        from: document.getElementById('r-date-from').value,
        to:   document.getElementById('r-date-to').value,
    };
}

// ── P&L ───────────────────────────────────────────────────────────────────────
async function loadPL() {
    const { from, to } = getDateRange();
    const r = await fetch(`${API_RP}?action=pl&date_from=${from}&date_to=${to}`);
    const d = await r.json();
    if (!d.ok) { console.error(d.error); return; }

    lastReportData = d.report;
    const rpt = d.report;

    // Summary cards
    const profitColor = rpt.net_profit >= 0 ? '#2D8659' : '#e85d04';
    document.getElementById('pl-summary-row').innerHTML = `
        <div class="col-4"><div class="mw-acct-stat-card mw-acct-income"><div class="mw-acct-stat-label">Total Revenue</div><div class="mw-acct-stat-value">${fmtMoney(rpt.total_revenue)}</div></div></div>
        <div class="col-4"><div class="mw-acct-stat-card mw-acct-expense"><div class="mw-acct-stat-label">Total Expenses</div><div class="mw-acct-stat-value">${fmtMoney(rpt.total_expenses)}</div></div></div>
        <div class="col-4"><div class="mw-acct-stat-card" style="border-left:4px solid ${profitColor}"><div class="mw-acct-stat-label">Net Profit</div><div class="mw-acct-stat-value" style="color:${profitColor}">${fmtMoney(rpt.net_profit)}</div><div class="mw-acct-stat-sub">${rpt.margin_pct}% margin</div></div></div>
    `;

    // Revenue rows
    document.getElementById('pl-total-rev').textContent = fmtMoney(rpt.total_revenue);
    document.getElementById('pl-rev-rows').innerHTML = rpt.revenue.length
        ? rpt.revenue.map(r => `<tr><td>${esc(r.code)}</td><td>${esc(r.name)}</td><td class="text-end text-success">${fmtMoney(r.total)}</td></tr>`).join('')
        : '<tr><td colspan="3" class="text-muted text-center small">No revenue in this period.</td></tr>';

    // Expense rows
    document.getElementById('pl-total-exp').textContent = fmtMoney(rpt.total_expenses);
    document.getElementById('pl-exp-rows').innerHTML = rpt.expenses.length
        ? rpt.expenses.map(r => `<tr><td>${esc(r.code)}</td><td>${esc(r.name)}</td><td class="text-end text-danger">${fmtMoney(r.total)}</td></tr>`).join('')
        : '<tr><td colspan="3" class="text-muted text-center small">No expenses in this period.</td></tr>';

    // Net profit card
    const netEl  = document.getElementById('pl-net-amount');
    netEl.textContent = (rpt.net_profit < 0 ? '−' : '') + fmtMoney(Math.abs(rpt.net_profit));
    netEl.style.color = profitColor;
    document.getElementById('pl-net-margin').textContent = `${rpt.margin_pct}% profit margin`;
}

// ── Cash Flow ─────────────────────────────────────────────────────────────────
async function loadCashFlow() {
    const { from, to } = getDateRange();
    const r = await fetch(`${API_RP}?action=cashflow&date_from=${from}&date_to=${to}`);
    const d = await r.json();
    if (!d.ok) return;

    const rpt = d.report;
    const netColor = rpt.net_cash_flow >= 0 ? '#2D8659' : '#e85d04';
    document.getElementById('cf-rows').innerHTML = `
        <tr class="table-light"><td colspan="2"><strong>Operating Activities</strong></td></tr>
        <tr>
            <td class="ps-3">Cash inflows (invoices paid)</td>
            <td class="text-end text-success fw-bold">${fmtMoney(rpt.inflows)}</td>
        </tr>
        <tr>
            <td class="ps-3">Cash outflows (business expenses)</td>
            <td class="text-end text-danger fw-bold">−${fmtMoney(rpt.outflows)}</td>
        </tr>
        <tr class="table-light">
            <td><strong>Net Cash Flow</strong></td>
            <td class="text-end fw-bold" style="color:${netColor};font-size:1.1rem">${rpt.net_cash_flow < 0 ? '−' : ''}${fmtMoney(Math.abs(rpt.net_cash_flow))}</td>
        </tr>
        <tr><td colspan="2" class="text-muted small py-2">Period: ${rpt.date_from} to ${rpt.date_to} · Cash-basis reporting</td></tr>
    `;
}

// ── GST Report ────────────────────────────────────────────────────────────────
async function loadTax() {
    const year    = document.getElementById('r-year').value;
    const quarter = document.getElementById('r-quarter').value;

    // Set default quarter to current
    if (!quarter) {
        const q = Math.ceil(new Date().getMonth() / 3) + 1;
        document.getElementById('r-quarter').value = Math.min(q, 4);
    }

    const r = await fetch(`${API_RP}?action=tax&year=${year}&quarter=${quarter}`);
    const d = await r.json();
    if (!d.ok) return;

    const rpt = d.report;
    document.getElementById('tax-period-label').textContent = `Period: ${rpt.date_from} to ${rpt.date_to}`;

    const owingColor = rpt.line_109 < 0 ? '#2D8659' : '#e85d04';
    const owingLabel = rpt.is_refund ? 'Refund' : 'Remit to CRA';

    document.getElementById('tax-cra-rows').innerHTML = `
        <tr><td>101</td><td>Total sales (incl. GST)</td><td class="text-end">${fmtMoney(rpt.line_101)}</td></tr>
        <tr><td>103</td><td>GST/HST collected or collectible</td><td class="text-end text-danger">${fmtMoney(rpt.line_103)}</td></tr>
        <tr><td>106</td><td>Input Tax Credits (ITC) — GST paid on expenses</td><td class="text-end text-success">${fmtMoney(rpt.line_106)}</td></tr>
        <tr class="table-light fw-bold">
            <td>109</td>
            <td>Net Tax (${owingLabel})</td>
            <td class="text-end" style="color:${owingColor}">${rpt.is_refund ? '(' : ''}${fmtMoney(Math.abs(rpt.line_109))}${rpt.is_refund ? ') ← Refund' : ''}</td>
        </tr>
    `;

    document.getElementById('tax-itc-rows').innerHTML = rpt.itc_detail.length
        ? rpt.itc_detail.map(r => `<tr><td class="small">${esc(r.account_code)} ${esc(r.account_name)}</td><td class="text-end small">${fmtMoney(r.gst_amount)}</td></tr>`).join('')
        : '<tr><td colspan="2" class="text-muted text-center small py-2">No ITC expenses this quarter.</td></tr>';

    // Load annual summary
    const ra = await fetch(`${API_RP}?action=tax_year&year=${year}`);
    const da = await ra.json();
    if (da.ok) renderAnnualGst(da.quarters);
}

function renderAnnualGst(quarters) {
    const tbody = document.getElementById('tax-annual-rows');
    if (!quarters.length) { tbody.innerHTML = '<tr><td colspan="5" class="text-muted text-center">—</td></tr>'; return; }

    tbody.innerHTML = quarters.map(q => {
        const owing = q.line_109;
        const color = owing < 0 ? 'text-success' : 'text-danger';
        const label = owing < 0 ? `(${fmtMoney(Math.abs(owing))}) Refund` : fmtMoney(owing);
        return `<tr>
            <td>${esc(q.period_label)}</td>
            <td class="text-end">${fmtMoney(q.line_103)}</td>
            <td class="text-end text-success">${fmtMoney(q.line_106)}</td>
            <td class="text-end ${color} fw-bold">${label}</td>
            <td class="text-center"><span class="badge ${owing <= 0 ? 'bg-success' : 'bg-warning text-dark'}">${owing <= 0 ? 'OK' : 'Owing'}</span></td>
        </tr>`;
    }).join('');
}

// ── Job Profitability ─────────────────────────────────────────────────────────
async function loadJobs() {
    const { from, to } = getDateRange();
    const r = await fetch(`${API_RP}?action=job_profitability&date_from=${from}&date_to=${to}&limit=30`);
    const d = await r.json();
    if (!d.ok) return;

    const tbody = document.getElementById('job-rows');
    if (!d.jobs.length) {
        tbody.innerHTML = '<tr><td colspan="6" class="text-center text-muted py-4">No completed jobs with linked transactions in this period.<br><small>Tip: Use the Sync button on the Dashboard to link invoices/expenses to jobs.</small></td></tr>';
        return;
    }

    tbody.innerHTML = d.jobs.map(j => {
        const profitColor = j.profit >= 0 ? 'text-success' : 'text-danger';
        const margin      = Math.min(100, Math.max(0, j.margin));
        const barColor    = j.margin >= 30 ? '#2D8659' : (j.margin >= 0 ? '#e85d04' : '#dc3545');
        return `<tr>
            <td><a href="/crm/jobs/view.php?id=${j.job_id}" class="fw-bold">${esc(j.job_number)}</a></td>
            <td class="small text-muted">${esc(j.client_name || '—')}</td>
            <td class="text-end text-success">${fmtMoney(j.revenue)}</td>
            <td class="text-end text-danger">${fmtMoney(j.expenses)}</td>
            <td class="text-end ${profitColor} fw-bold">${fmtMoney(j.profit)}</td>
            <td>
                <div class="mw-acct-margin-bar" style="width:80px">
                    <div class="mw-acct-margin-fill" style="width:${margin}%;background:${barColor}"></div>
                </div>
                <span class="small ${profitColor}">${j.margin}%</span>
            </td>
        </tr>`;
    }).join('');
}

// ── Expense Breakdown ─────────────────────────────────────────────────────────
async function loadExpenses() {
    const { from, to } = getDateRange();
    const r = await fetch(`${API_RP}?action=expense_breakdown&date_from=${from}&date_to=${to}`);
    const d = await r.json();
    if (!d.ok) return;

    document.getElementById('exp-total-label').textContent = 'Total: ' + fmtMoney(d.total);

    const tbody = document.getElementById('exp-rows');
    if (!d.breakdown.length) {
        tbody.innerHTML = '<tr><td colspan="3" class="text-center text-muted py-4">No expense data.</td></tr>';
        document.getElementById('exp-chart-wrap').innerHTML = '<span class="text-muted small">No data to chart.</span>';
        return;
    }

    tbody.innerHTML = d.breakdown.map(row => `
        <tr>
            <td>
                <div class="small fw-bold">${esc(row.category)}</div>
                <div class="mw-acct-pct-bar"><div class="mw-acct-pct-fill" style="width:${row.pct}%"></div></div>
            </td>
            <td class="text-end small text-danger">${fmtMoney(row.total)}</td>
            <td class="text-end small text-muted">${row.pct}%</td>
        </tr>
    `).join('');

    renderDonut(d.breakdown);
}

function renderDonut(data) {
    const wrap = document.getElementById('exp-chart-wrap');
    const top  = data.slice(0, 8); // Show top 8 categories
    const total= top.reduce((s, r) => s + parseFloat(r.total), 0) || 1;

    const COLORS = ['#2D8659','#e85d04','#3B82F6','#8B5CF6','#14B8A6','#F59E0B','#EF4444','#6B7280'];

    const W = 200, H = 200, cx = 100, cy = 100, r = 70, inner = 40;
    let angle = -Math.PI / 2;
    let slices = '';
    let legend = '';

    top.forEach((item, i) => {
        const pct   = parseFloat(item.total) / total;
        const sweep = pct * 2 * Math.PI;
        const x1    = cx + r * Math.cos(angle);
        const y1    = cy + r * Math.sin(angle);
        const x2    = cx + r * Math.cos(angle + sweep);
        const y2    = cy + r * Math.sin(angle + sweep);
        const xi1   = cx + inner * Math.cos(angle);
        const yi1   = cy + inner * Math.sin(angle);
        const xi2   = cx + inner * Math.cos(angle + sweep);
        const yi2   = cy + inner * Math.sin(angle + sweep);
        const lf    = sweep > Math.PI ? 1 : 0;
        const color = COLORS[i % COLORS.length];

        slices += `<path d="M${xi1},${yi1} L${x1},${y1} A${r},${r},0,${lf},1,${x2},${y2} L${xi2},${yi2} A${inner},${inner},0,${lf},0,${xi1},${yi1} Z" fill="${color}" opacity="0.85">
            <title>${item.category}: ${fmtMoney(item.total)} (${item.pct}%)</title></path>`;

        legend += `<div style="display:flex;align-items:center;gap:4px;margin-bottom:4px;font-size:11px">
            <div style="width:10px;height:10px;border-radius:2px;background:${color};flex-shrink:0"></div>
            <span>${esc(item.category.substring(0, 20))}</span>
            <span style="margin-left:auto;color:#6b7280">${item.pct}%</span>
        </div>`;

        angle += sweep;
    });

    wrap.innerHTML = `
        <div style="display:flex;align-items:center;gap:16px;flex-wrap:wrap;justify-content:center">
            <svg viewBox="0 0 ${W} ${H}" style="width:${W}px;max-width:200px">
                ${slices}
                <circle cx="${cx}" cy="${cy}" r="${inner - 1}" fill="var(--bs-card-bg, #fff)"/>
            </svg>
            <div style="flex:1;min-width:140px;max-width:200px">${legend}</div>
        </div>
    `;
}

// ── CSV Export ────────────────────────────────────────────────────────────────
async function exportCSV() {
    const { from, to } = getDateRange();
    const r = await fetch(`${API_RP}?action=accountant_export&date_from=${from}&date_to=${to}`);
    const d = await r.json();
    if (!d.ok) { alert('Export failed: ' + d.error); return; }

    const rows  = d.transactions;
    if (!rows.length) { alert('No data to export.'); return; }

    const headers = ['Date','Type','Account Code','Account','Amount','GST','PST','Description','Reference','Vendor','Job #','Client','Status'];
    const lines   = [
        headers.join(','),
        ...rows.map(tx => [
            tx.transaction_date,
            tx.type,
            tx.account_code,
            `"${(tx.account_name || '').replace(/"/g,'""')}"`,
            tx.amount,
            tx.gst_amount,
            tx.pst_amount,
            `"${(tx.description || '').replace(/"/g,'""')}"`,
            tx.reference_type + (tx.reference_id ? '#' + tx.reference_id : ''),
            `"${(tx.vendor_name || '').replace(/"/g,'""')}"`,
            tx.job_number || '',
            `"${(tx.client_name || '').replace(/"/g,'""')}"`,
            tx.status,
        ].join(',')),
    ];

    const blob = new Blob([lines.join('\r\n')], { type: 'text/csv' });
    const url  = URL.createObjectURL(blob);
    const a    = document.createElement('a');
    a.href     = url;
    a.download = `mowology-transactions-${from}-to-${to}.csv`;
    a.click();
    URL.revokeObjectURL(url);
}

// ── Budget vs Actual ──────────────────────────────────────────────────────────
async function loadBudget() {
    const month = document.getElementById('r-budget-month').value || '<?= date('Y-m') ?>';
    const r = await fetch(`${API_BI}?action=budget_vs_actual&month=${month}`);
    const d = await r.json();
    if (!d.ok) return;

    const tbody = document.getElementById('budget-rows');
    if (!d.budget.length) {
        tbody.innerHTML = '<tr><td colspan="6" class="text-center text-muted py-4">No budgets configured.<br><small>Set up monthly budgets in the <a href="/crm/expenses_appstack.php">Expenses</a> module.</small></td></tr>';
        document.getElementById('budget-summary-row').innerHTML = '';
        return;
    }

    const STATUS_COLORS = {
        ok:          { bar: '#2D8659', badge: 'bg-success' },
        warning:     { bar: '#F59E0B', badge: 'bg-warning text-dark' },
        critical:    { bar: '#e85d04', badge: 'bg-danger' },
        over_budget: { bar: '#dc3545', badge: 'bg-danger' },
    };

    tbody.innerHTML = d.budget.map(b => {
        const color = STATUS_COLORS[b.status] || STATUS_COLORS.ok;
        const pct   = Math.min(100, b.pct_used);
        const statusBadge = {
            ok:          '<span class="badge bg-success">On Track</span>',
            warning:     '<span class="badge bg-warning text-dark">Warning</span>',
            critical:    '<span class="badge bg-danger">Critical</span>',
            over_budget: '<span class="badge bg-danger">Over Budget</span>',
        }[b.status] || '';

        return `<tr>
            <td>
                <div class="fw-bold small">${esc(b.account_name || b.category)}</div>
                ${b.account_code ? `<div class="text-muted" style="font-size:11px">${esc(b.account_code)}</div>` : ''}
            </td>
            <td class="text-end small">${fmtMoney(b.budget)}</td>
            <td class="text-end small fw-bold" style="color:${color.bar}">${fmtMoney(b.actual)}</td>
            <td class="text-end small ${b.remaining > 0 ? 'text-success' : 'text-danger'}">${fmtMoney(b.remaining)}</td>
            <td>
                <div class="mw-acct-margin-bar">
                    <div class="mw-acct-margin-fill" style="width:${pct}%;background:${color.bar}"></div>
                </div>
                <span style="font-size:10px;color:${color.bar}">${b.pct_used}%</span>
            </td>
            <td>${statusBadge}</td>
        </tr>`;
    }).join('');

    // Summary
    const totalBudget = d.budget.reduce((s, b) => s + parseFloat(b.budget), 0);
    const totalActual = d.budget.reduce((s, b) => s + parseFloat(b.actual), 0);
    const overCount   = d.budget.filter(b => b.status === 'over_budget' || b.status === 'critical').length;

    document.getElementById('budget-summary-row').innerHTML = `
        <div class="col-4 text-center"><div class="mw-acct-ytd-label">Total Budget</div><div class="mw-acct-ytd-val">${fmtMoney(totalBudget)}</div></div>
        <div class="col-4 text-center"><div class="mw-acct-ytd-label">Total Spent</div><div class="mw-acct-ytd-val mw-acct-color-expense">${fmtMoney(totalActual)}</div></div>
        <div class="col-4 text-center"><div class="mw-acct-ytd-label">Over Budget</div><div class="mw-acct-ytd-val" style="color:${overCount>0?'#dc3545':'#2D8659'}">${overCount} categor${overCount!==1?'ies':'y'}</div></div>
    `;
}

// ── Crew Profitability ─────────────────────────────────────────────────────────
async function loadCrew() {
    const { from, to } = getDateRange();
    const r = await fetch(`${API_BI}?action=crew_profitability&date_from=${from}&date_to=${to}`);
    const d = await r.json();
    if (!d.ok) return;

    const tbody = document.getElementById('crew-rows');
    if (!d.crew.length) {
        tbody.innerHTML = '<tr><td colspan="6" class="text-center text-muted py-4">No crew data for this period.<br><small>Crew data comes from jobs with assigned crew members and linked invoice/expense transactions.</small></td></tr>';
        return;
    }

    tbody.innerHTML = d.crew.map(m => {
        const profitColor = m.profit >= 0 ? '#2D8659' : '#dc3545';
        const margin      = Math.max(0, Math.min(100, m.margin));
        const barColor    = m.margin >= 30 ? '#2D8659' : (m.margin >= 0 ? '#F59E0B' : '#dc3545');

        return `<tr>
            <td class="fw-bold">${esc(m.name)}</td>
            <td class="text-end text-success">${fmtMoney(m.revenue)}</td>
            <td class="text-end text-muted small">${fmtMoney(m.labour_cost)}</td>
            <td class="text-end text-muted small">${fmtMoney(m.direct_cost)}</td>
            <td class="text-end fw-bold" style="color:${profitColor}">${fmtMoney(m.profit)}</td>
            <td>
                <div class="mw-acct-margin-bar" style="width:80px">
                    <div class="mw-acct-margin-fill" style="width:${margin}%;background:${barColor}"></div>
                </div>
                <span class="small" style="color:${profitColor}">${m.margin}%</span>
            </td>
        </tr>`;
    }).join('');
}

// ── Utilities ─────────────────────────────────────────────────────────────────
function fmtMoney(v) {
    return '$' + parseFloat(v || 0).toLocaleString('en-CA', {minimumFractionDigits:2, maximumFractionDigits:2});
}
function esc(s) {
    return String(s||'').replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;');
}

// ── Init ──────────────────────────────────────────────────────────────────────
document.addEventListener('DOMContentLoaded', () => {
    // Set quarter selector to current quarter
    const q = Math.ceil((new Date().getMonth() + 1) / 3);
    document.getElementById('r-quarter').value = q;

    // Check for ?report= param
    const urlReport = new URLSearchParams(location.search).get('report');
    if (urlReport) switchTab(urlReport);
    else loadPL();
});
</script>

<?php include dirname(__DIR__) . '/includes/appstack_footer.php'; ?>

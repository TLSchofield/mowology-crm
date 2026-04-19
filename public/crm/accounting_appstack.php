<?php
require_once __DIR__ . '/../loginAuth/auth.php';
require_once 'includes/functions.php';
requireLogin();
$user = getCurrentUser();
requirePermission('expenses.view');

$pageTitle  = 'Accounting';
$activePage = 'accounting';
?>
<?php include 'includes/appstack_head.php'; ?>

<div class="mw-page-header">
    <div class="mw-page-header-left">
        <h1 class="mw-page-title">Accounting</h1>
        <p class="mw-page-subtitle">Real-time financial intelligence</p>
    </div>
    <div class="mw-page-actions">
        <button class="btn btn-sm btn-outline-secondary" id="btn-run-analysis" onclick="runAnalysis()">
            <i data-feather="cpu" class="mw-btn-icon"></i> Run Analysis
        </button>
        <button class="btn btn-sm btn-outline-secondary" onclick="syncLedger()">
            <i data-feather="refresh-cw" class="mw-btn-icon"></i> Sync Ledger
        </button>
        <a href="/crm/accounting/transactions.php" class="btn btn-sm btn-outline-primary">
            <i data-feather="list" class="mw-btn-icon"></i> All Transactions
        </a>
        <a href="/crm/accounting/reports.php" class="btn btn-sm btn-primary">
            <i data-feather="bar-chart-2" class="mw-btn-icon"></i> Reports
        </a>
    </div>
</div>

<!-- ── Sync Banner ──────────────────────────────────────────────────────────── -->
<div id="mw-sync-banner" class="alert alert-info d-none mb-3" role="alert">
    <i data-feather="refresh-cw" class="mw-btn-icon spin"></i>
    <span id="mw-sync-msg">Syncing ledger…</span>
</div>

<!-- ══════════════════════════════════════════════════════════════════════════ -->
<!-- KPI STAT CARDS — Today / MTD / YTD                                        -->
<!-- ══════════════════════════════════════════════════════════════════════════ -->
<div class="row g-3 mb-4" id="mw-kpi-row">

    <!-- Revenue MTD -->
    <div class="col-6 col-xl-3">
        <div class="mw-acct-stat-card mw-acct-income">
            <div class="mw-acct-stat-label">Revenue (MTD)</div>
            <div class="mw-acct-stat-value" id="kpi-rev-mtd">—</div>
            <div class="mw-acct-stat-sub" id="kpi-rev-today">Today: —</div>
        </div>
    </div>

    <!-- Expenses MTD -->
    <div class="col-6 col-xl-3">
        <div class="mw-acct-stat-card mw-acct-expense">
            <div class="mw-acct-stat-label">Expenses (MTD)</div>
            <div class="mw-acct-stat-value" id="kpi-exp-mtd">—</div>
            <div class="mw-acct-stat-sub" id="kpi-exp-today">Today: —</div>
        </div>
    </div>

    <!-- Net Profit MTD -->
    <div class="col-6 col-xl-3">
        <div class="mw-acct-stat-card mw-acct-profit" id="kpi-profit-card">
            <div class="mw-acct-stat-label">Net Profit (MTD)</div>
            <div class="mw-acct-stat-value" id="kpi-profit-mtd">—</div>
            <div class="mw-acct-stat-sub" id="kpi-margin-mtd">Margin: —%</div>
        </div>
    </div>

    <!-- GST Owing This Quarter -->
    <div class="col-6 col-xl-3">
        <div class="mw-acct-stat-card mw-acct-tax">
            <div class="mw-acct-stat-label">GST Owing (QTD)</div>
            <div class="mw-acct-stat-value" id="kpi-gst-owing">—</div>
            <div class="mw-acct-stat-sub" id="kpi-gst-period">Loading…</div>
        </div>
    </div>

</div><!-- /row -->

<!-- ══════════════════════════════════════════════════════════════════════════ -->
<!-- HOUSEKEEPING ALERTS (uncategorized / needs-review)                        -->
<!-- ══════════════════════════════════════════════════════════════════════════ -->
<div id="mw-acct-alerts" class="mb-3"></div>

<!-- ══════════════════════════════════════════════════════════════════════════ -->
<!-- MAIN CONTENT — Monthly Trend + Recent Transactions                        -->
<!-- ══════════════════════════════════════════════════════════════════════════ -->
<div class="row g-3 mb-4">

    <!-- Monthly Trend Chart -->
    <div class="col-12 col-xl-7">
        <div class="card">
            <div class="card-header d-flex justify-content-between align-items-center">
                <h5 class="card-title mb-0">Revenue vs Expenses — Last 12 Months</h5>
                <a href="/crm/accounting/reports.php" class="btn btn-sm btn-outline-secondary">Full Report</a>
            </div>
            <div class="card-body">
                <div id="mw-trend-chart-wrap" style="min-height:240px; display:flex; align-items:center; justify-content:center;">
                    <span class="text-muted small">Loading…</span>
                </div>
            </div>
        </div>
    </div>

    <!-- Recent Transactions -->
    <div class="col-12 col-xl-5">
        <div class="card h-100">
            <div class="card-header d-flex justify-content-between align-items-center">
                <h5 class="card-title mb-0">Recent Transactions</h5>
                <a href="/crm/accounting/transactions.php" class="btn btn-sm btn-outline-secondary">View All</a>
            </div>
            <div class="card-body p-0">
                <div id="mw-recent-list" class="mw-acct-recent-list">
                    <div class="text-center text-muted py-4 small">Loading…</div>
                </div>
            </div>
        </div>
    </div>

</div><!-- /row -->

<!-- ══════════════════════════════════════════════════════════════════════════ -->
<!-- FINANCIAL INTELLIGENCE ALERTS                                              -->
<!-- ══════════════════════════════════════════════════════════════════════════ -->
<div class="row g-3 mb-4" id="mw-intelligence-wrap" style="display:none!important">
    <div class="col-12">
        <div class="card">
            <div class="card-header d-flex justify-content-between align-items-center">
                <div>
                    <h5 class="card-title mb-0">
                        <i data-feather="zap" class="mw-btn-icon" style="color:var(--mw-lime)"></i>
                        Financial Intelligence
                    </h5>
                    <p class="text-muted small mb-0 mt-1" id="mw-intel-subtitle">Automated insights from your financial data</p>
                </div>
                <div class="d-flex gap-2">
                    <button class="btn btn-sm btn-outline-secondary" id="btn-dismiss-all"
                            onclick="dismissAllAlerts()" style="display:none">
                        Dismiss All
                    </button>
                    <button class="btn btn-sm btn-outline-secondary" onclick="runAnalysis()">
                        <i data-feather="refresh-cw" class="mw-btn-icon" id="intel-spin"></i> Refresh
                    </button>
                </div>
            </div>
            <div class="card-body" id="mw-intel-body">
                <div class="text-center text-muted py-3 small">Running analysis…</div>
            </div>
        </div>
    </div>
</div>

<!-- ══════════════════════════════════════════════════════════════════════════ -->
<!-- QUICK ACTIONS                                                              -->
<!-- ══════════════════════════════════════════════════════════════════════════ -->
<div class="row g-3 mb-4">

    <div class="col-12">
        <div class="card">
            <div class="card-header">
                <h5 class="card-title mb-0">Quick Actions</h5>
            </div>
            <div class="card-body">
                <div class="mw-acct-quick-grid">

                    <a href="/crm/accounting/transactions.php?mode=add" class="mw-acct-quick-btn">
                        <i data-feather="plus-circle"></i>
                        <span>Add Transaction</span>
                    </a>

                    <a href="/crm/accounting/bank-import.php" class="mw-acct-quick-btn">
                        <i data-feather="upload"></i>
                        <span>Bank Import</span>
                    </a>

                    <a href="/crm/accounting/reports.php?report=pl" class="mw-acct-quick-btn">
                        <i data-feather="file-text"></i>
                        <span>P&amp;L Report</span>
                    </a>

                    <a href="/crm/accounting/reports.php?report=tax" class="mw-acct-quick-btn">
                        <i data-feather="percent"></i>
                        <span>GST Report</span>
                    </a>

                    <a href="/crm/accounting/reports.php?report=jobs" class="mw-acct-quick-btn">
                        <i data-feather="briefcase"></i>
                        <span>Job Profitability</span>
                    </a>

                    <a href="/crm/accounting/transactions.php?needs_review=1" class="mw-acct-quick-btn mw-acct-quick-btn--review">
                        <i data-feather="flag"></i>
                        <span>Needs Review</span>
                        <span class="badge bg-warning text-dark ms-1" id="review-badge" style="display:none"></span>
                    </a>

                </div>
            </div>
        </div>
    </div>

</div>

<!-- ══════════════════════════════════════════════════════════════════════════ -->
<!-- YTD SUMMARY                                                               -->
<!-- ══════════════════════════════════════════════════════════════════════════ -->
<div class="row g-3 mb-4">
    <div class="col-12">
        <div class="card">
            <div class="card-header d-flex justify-content-between align-items-center">
                <h5 class="card-title mb-0">Year to Date — <?= date('Y') ?></h5>
                <a href="/crm/accounting/reports.php?report=pl&period=ytd" class="btn btn-sm btn-outline-secondary">Export P&amp;L</a>
            </div>
            <div class="card-body">
                <div class="row g-3" id="mw-ytd-row">
                    <div class="col-12 text-center text-muted small">Loading…</div>
                </div>
            </div>
        </div>
    </div>
</div>

<!-- ══════════════════════════════════════════════════════════════════════════ -->
<!-- JAVASCRIPT                                                                 -->
<!-- ══════════════════════════════════════════════════════════════════════════ -->
<script>
const API_TX = '/crm/api/accounting-transactions.php';
const API_RP = '/crm/api/accounting-reports.php';
const API_BI = '/crm/api/accounting-bank-import.php';

// ── Formatters ────────────────────────────────────────────────────────────────
function fmtMoney(v) {
    return '$' + parseFloat(v || 0).toLocaleString('en-CA', {minimumFractionDigits: 2, maximumFractionDigits: 2});
}
function fmtDate(d) {
    if (!d) return '';
    const parts = d.split('-');
    if (parts.length < 3) return d;
    const months = ['Jan','Feb','Mar','Apr','May','Jun','Jul','Aug','Sep','Oct','Nov','Dec'];
    return `${months[parseInt(parts[1], 10) - 1]} ${parseInt(parts[2], 10)}`;
}
function escHtml(s) {
    return String(s || '').replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;');
}

// ── Load Dashboard ────────────────────────────────────────────────────────────
async function loadDashboard() {
    try {
        const r = await fetch(API_TX + '?action=dashboard');
        const d = await r.json();
        if (!d.ok) throw new Error(d.error);

        const m = d.metrics;

        // KPI cards
        document.getElementById('kpi-rev-mtd').textContent   = fmtMoney(m.mtd.revenue);
        document.getElementById('kpi-rev-today').textContent  = 'Today: ' + fmtMoney(m.today.revenue);
        document.getElementById('kpi-exp-mtd').textContent    = fmtMoney(m.mtd.expenses);
        document.getElementById('kpi-exp-today').textContent  = 'Today: ' + fmtMoney(m.today.expenses);
        document.getElementById('kpi-profit-mtd').textContent = fmtMoney(m.mtd.profit);
        document.getElementById('kpi-margin-mtd').textContent = 'Margin: ' + m.mtd.margin + '%';

        // Profit card colour
        const profitCard = document.getElementById('kpi-profit-card');
        if (m.mtd.profit < 0) {
            profitCard.classList.remove('mw-acct-profit');
            profitCard.classList.add('mw-acct-loss');
        }

        // Tax widget
        if (m.tax) {
            const owing = m.tax.net_gst_owing;
            document.getElementById('kpi-gst-owing').textContent =
                (owing < 0 ? '−' : '') + fmtMoney(Math.abs(owing));
            document.getElementById('kpi-gst-period').textContent =
                owing < 0 ? 'Refund expected' : 'Owing to CRA';
        }

        // Housekeeping alerts (uncategorized / needs-review)
        renderHousekeepingAlerts(m);

        // Review badge
        if (m.needs_review > 0) {
            const badge = document.getElementById('review-badge');
            badge.textContent = m.needs_review;
            badge.style.display = '';
        }

        // Recent transactions
        renderRecentList(m.recent || []);

        // YTD summary
        renderYtd(m.ytd);

    } catch (err) {
        console.error('Dashboard load failed:', err);
    }
}

// ── Housekeeping Alerts (top of page) ─────────────────────────────────────────
function renderHousekeepingAlerts(m) {
    const wrap = document.getElementById('mw-acct-alerts');
    const alerts = [];

    if (m.uncategorized > 0) {
        alerts.push({
            type: 'warning',
            icon: 'alert-triangle',
            msg: `<strong>${m.uncategorized} transaction${m.uncategorized > 1 ? 's' : ''}</strong> need categorization.`,
            link: '/crm/accounting/transactions.php',
            linkText: 'Review now',
        });
    }
    if (m.needs_review > 0) {
        alerts.push({
            type: 'info',
            icon: 'flag',
            msg: `<strong>${m.needs_review} transaction${m.needs_review > 1 ? 's' : ''}</strong> flagged for accountant review.`,
            link: '/crm/accounting/transactions.php?needs_review=1',
            linkText: 'View flagged',
        });
    }

    if (alerts.length === 0) { wrap.innerHTML = ''; return; }

    wrap.innerHTML = alerts.map(a => `
        <div class="alert alert-${a.type} d-flex align-items-center gap-2 py-2 mb-2">
            <i data-feather="${a.icon}" style="width:16px;height:16px;flex-shrink:0;"></i>
            <span>${a.msg}</span>
            <a href="${a.link}" class="ms-auto btn btn-sm btn-${a.type}">${a.linkText}</a>
        </div>
    `).join('');

    if (typeof feather !== 'undefined') feather.replace();
}

// ── Financial Intelligence Alerts ─────────────────────────────────────────────
async function loadAlerts(runDetection) {
    const wrap   = document.getElementById('mw-intelligence-wrap');
    const body   = document.getElementById('mw-intel-body');
    const spin   = document.getElementById('intel-spin');

    wrap.style.removeProperty('display');  // show the card
    if (spin) spin.classList.add('spin');

    const url = API_BI + '?action=alerts' + (runDetection ? '&run=1' : '');
    try {
        const r = await fetch(url);
        const d = await r.json();
        if (!d.ok) throw new Error(d.error || 'Unknown error');

        renderIntelligenceAlerts(d.alerts || [], d.summary || {});
    } catch (err) {
        body.innerHTML = `<div class="alert alert-danger py-2 small">Failed to load financial intelligence: ${escHtml(err.message)}</div>`;
    } finally {
        if (spin) spin.classList.remove('spin');
    }
}

function renderIntelligenceAlerts(alerts, summary) {
    const body        = document.getElementById('mw-intel-body');
    const subtitle    = document.getElementById('mw-intel-subtitle');
    const dismissAll  = document.getElementById('btn-dismiss-all');

    const active = alerts.filter(a => !a.is_dismissed);

    // Update subtitle
    if (subtitle) {
        const totalChecked = summary.total_detected || alerts.length;
        subtitle.textContent = active.length === 0
            ? `No active alerts — ${totalChecked} check${totalChecked !== 1 ? 's' : ''} passed`
            : `${active.length} active alert${active.length !== 1 ? 's' : ''} detected`;
    }

    // Show/hide dismiss-all
    if (dismissAll) dismissAll.style.display = active.length > 0 ? '' : 'none';

    if (active.length === 0) {
        body.innerHTML = `
            <div class="d-flex align-items-center gap-3 py-2">
                <i data-feather="check-circle" style="width:32px;height:32px;color:var(--mw-lime);flex-shrink:0;"></i>
                <div>
                    <div class="fw-semibold">All clear</div>
                    <div class="text-muted small">No cost spikes, revenue drops, or margin issues detected.</div>
                </div>
            </div>`;
        if (typeof feather !== 'undefined') feather.replace();
        return;
    }

    const CONFIG = {
        cost_spike:       { icon: 'trending-up',   color: '#e85d04', label: 'Cost Spike'       },
        revenue_drop:     { icon: 'trending-down',  color: '#dc3545', label: 'Revenue Drop'     },
        low_margin_job:   { icon: 'briefcase',      color: '#fd7e14', label: 'Low Margin Job'   },
        unusual_expense:  { icon: 'alert-circle',   color: '#ffc107', label: 'Unusual Expense'  },
        default:          { icon: 'info',            color: '#0d6efd', label: 'Alert'            },
    };
    const SEV = {
        critical: { bg: '#3d0a0a', border: '#dc3545', badge: 'danger'  },
        warning:  { bg: '#2d1a00', border: '#fd7e14', badge: 'warning' },
        info:     { bg: '#0a1928', border: '#0d6efd', badge: 'info'    },
    };

    body.innerHTML = `<div class="mw-intel-grid">${active.map(a => {
        const cfg = CONFIG[a.alert_type] || CONFIG.default;
        const sev = SEV[a.severity]      || SEV.info;
        return `
        <div class="mw-intel-card" id="intel-card-${a.id}"
             style="border-left:3px solid ${sev.border};background:${sev.bg};border-radius:6px;padding:12px 14px;">
            <div class="d-flex align-items-start gap-2">
                <i data-feather="${cfg.icon}"
                   style="width:18px;height:18px;flex-shrink:0;margin-top:2px;color:${cfg.color}"></i>
                <div class="flex-grow-1">
                    <div class="d-flex align-items-center gap-2 mb-1">
                        <span class="fw-semibold small">${escHtml(a.title)}</span>
                        <span class="badge bg-${sev.badge} text-${a.severity === 'warning' ? 'dark' : 'white'} ms-auto"
                              style="font-size:10px;font-weight:500;">${cfg.label}</span>
                    </div>
                    <div class="text-muted small lh-sm">${escHtml(a.body)}</div>
                    ${a.reference_type === 'job' && a.reference_id
                        ? `<a href="/crm/jobs/view.php?id=${a.reference_id}" class="small" style="color:var(--mw-lime)">View job →</a>`
                        : ''}
                </div>
                <button class="btn btn-sm p-0 ms-2" style="color:#6b7280;line-height:1;"
                        onclick="dismissAlert(${a.id})" title="Dismiss">
                    <i data-feather="x" style="width:14px;height:14px;"></i>
                </button>
            </div>
        </div>`;
    }).join('')}</div>`;

    if (typeof feather !== 'undefined') feather.replace();
}

async function dismissAlert(id) {
    try {
        const fd = new FormData();
        fd.append('id', id);
        const r = await fetch(API_BI + '?action=dismiss_alert', { method: 'POST', body: fd });
        const d = await r.json();
        if (!d.ok) throw new Error(d.error);

        // Fade out the card
        const card = document.getElementById('intel-card-' + id);
        if (card) {
            card.style.transition = 'opacity 0.3s, max-height 0.3s';
            card.style.opacity    = '0';
            card.style.maxHeight  = '0';
            card.style.overflow   = 'hidden';
            setTimeout(() => card.remove(), 320);
        }
        // Refresh counts
        setTimeout(() => loadAlerts(false), 400);
    } catch (err) {
        console.error('Dismiss failed:', err);
    }
}

async function dismissAllAlerts() {
    try {
        const r = await fetch(API_BI + '?action=dismiss_all_alerts', { method: 'POST' });
        const d = await r.json();
        if (!d.ok) throw new Error(d.error);
        loadAlerts(false);
    } catch (err) {
        console.error('Dismiss all failed:', err);
    }
}

async function runAnalysis() {
    const btn = document.getElementById('btn-run-analysis');
    if (btn) { btn.disabled = true; btn.textContent = 'Analysing…'; }
    await loadAlerts(true);
    if (btn) { btn.disabled = false; btn.innerHTML = '<i data-feather="cpu" class="mw-btn-icon"></i> Run Analysis'; if (typeof feather !== 'undefined') feather.replace(); }
}

// ── Recent Transactions ───────────────────────────────────────────────────────
function renderRecentList(rows) {
    const el = document.getElementById('mw-recent-list');
    if (!rows.length) {
        el.innerHTML = '<p class="text-muted text-center py-3 small">No transactions yet. Click "Sync Ledger" to import.</p>';
        return;
    }
    el.innerHTML = rows.map(tx => {
        const isIncome  = tx.type === 'income';
        const amtClass  = isIncome ? 'mw-acct-amt-income' : 'mw-acct-amt-expense';
        const amtSign   = isIncome ? '+' : '−';
        const label     = tx.vendor_name || (tx.description || '').substring(0, 40) || tx.account_name;
        const flagHtml  = tx.needs_review == 1
            ? '<span class="mw-acct-flag-dot" title="Needs review"></span>'
            : '';
        return `
        <div class="mw-acct-recent-item">
            <div class="mw-acct-recent-dot mw-acct-dot-${tx.type}"></div>
            <div class="mw-acct-recent-body">
                <div class="mw-acct-recent-label">${escHtml(label)} ${flagHtml}</div>
                <div class="mw-acct-recent-meta">${fmtDate(tx.transaction_date)} · ${escHtml(tx.account_name)}</div>
            </div>
            <div class="${amtClass}">${amtSign}${fmtMoney(tx.amount)}</div>
        </div>`;
    }).join('');
}

// ── YTD Summary Row ───────────────────────────────────────────────────────────
function renderYtd(ytd) {
    if (!ytd) return;
    const el  = document.getElementById('mw-ytd-row');
    const pct = ytd.margin;
    const profitColor = ytd.profit >= 0 ? '#2D8659' : '#e85d04';

    el.innerHTML = `
        <div class="col-6 col-md-3">
            <div class="mw-acct-ytd-box">
                <div class="mw-acct-ytd-label">Revenue</div>
                <div class="mw-acct-ytd-val mw-acct-color-income">${fmtMoney(ytd.revenue)}</div>
            </div>
        </div>
        <div class="col-6 col-md-3">
            <div class="mw-acct-ytd-box">
                <div class="mw-acct-ytd-label">Expenses</div>
                <div class="mw-acct-ytd-val mw-acct-color-expense">${fmtMoney(ytd.expenses)}</div>
            </div>
        </div>
        <div class="col-6 col-md-3">
            <div class="mw-acct-ytd-box">
                <div class="mw-acct-ytd-label">Net Profit</div>
                <div class="mw-acct-ytd-val" style="color:${profitColor}">${fmtMoney(ytd.profit)}</div>
            </div>
        </div>
        <div class="col-6 col-md-3">
            <div class="mw-acct-ytd-box">
                <div class="mw-acct-ytd-label">Margin</div>
                <div class="mw-acct-ytd-val" style="color:${profitColor}">${pct}%</div>
                <div class="mw-acct-margin-bar">
                    <div class="mw-acct-margin-fill" style="width:${Math.min(100, Math.abs(pct))}%;background:${profitColor}"></div>
                </div>
            </div>
        </div>
    `;
}

// ── Monthly Trend Chart (SVG Sparkline) ───────────────────────────────────────
async function loadTrendChart() {
    try {
        const r = await fetch(API_RP + '?action=monthly_trend&months=12');
        const d = await r.json();
        if (!d.ok || !d.trend.length) return;

        renderBarChart(d.trend);
    } catch (e) {
        console.warn('Trend chart load failed:', e);
    }
}

function renderBarChart(data) {
    const wrap = document.getElementById('mw-trend-chart-wrap');
    if (!data.length) return;

    const W  = 600, H = 220, padX = 40, padY = 20, barH = H - padY * 2 - 30;
    const n  = data.length;
    const barW    = Math.floor((W - padX * 2) / n);
    const barGap  = Math.max(2, Math.floor(barW * 0.15));
    const barFill = barW - barGap;

    const maxVal = Math.max(...data.map(d => Math.max(d.revenue, d.expenses))) || 1;
    const months = ['Jan','Feb','Mar','Apr','May','Jun','Jul','Aug','Sep','Oct','Nov','Dec'];

    let bars = '', labels = '', gridLines = '';
    for (let p = 0.25; p <= 1; p += 0.25) {
        const y = padY + barH - barH * p;
        gridLines += `<line x1="${padX}" y1="${y}" x2="${W - padX}" y2="${y}" stroke="#374151" stroke-width="1" stroke-dasharray="3"/>`;
        gridLines += `<text x="${padX - 4}" y="${y + 4}" text-anchor="end" font-size="9" fill="#6b7280">$${Math.round(maxVal * p / 1000)}k</text>`;
    }

    data.forEach((d, i) => {
        const x       = padX + i * barW;
        const revH    = barH * (d.revenue / maxVal);
        const expH    = barH * (d.expenses / maxVal);
        const revY    = padY + barH - revH;
        const expY    = padY + barH - expH;
        const halfBar = Math.floor(barFill / 2);

        bars += `<rect x="${x + barGap}" y="${revY}" width="${halfBar}" height="${revH}" fill="#2D8659" rx="2" opacity="0.9">
            <title>${d.month}: Revenue ${fmtMoney(d.revenue)}</title></rect>`;
        bars += `<rect x="${x + barGap + halfBar + 2}" y="${expY}" width="${halfBar - 2}" height="${expH}" fill="#e85d04" rx="2" opacity="0.75">
            <title>${d.month}: Expenses ${fmtMoney(d.expenses)}</title></rect>`;

        const monthNum = parseInt(d.month.split('-')[1], 10) - 1;
        labels += `<text x="${x + barW / 2}" y="${padY + barH + 16}" text-anchor="middle" font-size="9" fill="#9ca3af">${months[monthNum]}</text>`;
    });

    const legend = `
        <rect x="${padX}" y="${H - 12}" width="10" height="8" fill="#2D8659" rx="1"/>
        <text x="${padX + 14}" y="${H - 5}" font-size="9" fill="#9ca3af">Revenue</text>
        <rect x="${padX + 75}" y="${H - 12}" width="10" height="8" fill="#e85d04" rx="1"/>
        <text x="${padX + 89}" y="${H - 5}" font-size="9" fill="#9ca3af">Expenses</text>
    `;

    wrap.innerHTML = `<svg viewBox="0 0 ${W} ${H}" style="width:100%;height:auto;max-height:240px;">
        ${gridLines}${bars}${labels}${legend}
    </svg>`;
}

// ── Sync Ledger ───────────────────────────────────────────────────────────────
async function syncLedger() {
    const banner = document.getElementById('mw-sync-banner');
    const msg    = document.getElementById('mw-sync-msg');

    banner.classList.remove('d-none', 'alert-danger');
    banner.classList.add('alert-info');
    msg.textContent = 'Syncing ledger from invoices and expenses…';

    try {
        const r = await fetch(API_TX + '?action=sync');
        const d = await r.json();

        if (!d.ok) throw new Error(d.error);

        const res = d.result;
        msg.textContent = `✓ Sync complete — ${res.invoices_synced} invoices, ${res.expenses_synced} expenses imported. ${res.rules_applied} transactions auto-categorized.`;
        banner.classList.replace('alert-info', 'alert-success');

        await loadDashboard();
        await loadTrendChart();

    } catch (err) {
        msg.textContent = '✗ Sync failed: ' + err.message;
        banner.classList.replace('alert-info', 'alert-danger');
    }
}

// ── Init ──────────────────────────────────────────────────────────────────────
// Silently sync the ledger in the background every time the dashboard loads.
// Sync is idempotent (INSERT ... ON DUPLICATE KEY UPDATE) so it's safe to run
// on every visit. Without this, the dashboard showed all $0.00 until the user
// manually clicked "Sync Ledger".
async function autoSyncThenLoad() {
    try {
        await fetch(API_TX + '?action=sync');
    } catch (e) {
        console.warn('Auto-sync failed (continuing with cached data):', e);
    }
    await loadDashboard();
    await loadTrendChart();
    await loadAlerts(false);
}

document.addEventListener('DOMContentLoaded', () => {
    autoSyncThenLoad();
});
</script>

<?php include 'includes/appstack_footer.php'; ?>

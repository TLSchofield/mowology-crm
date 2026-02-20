<?php
/**
 * /public/crm/profitability_appstack.php
 * Estimating Accuracy & Job Profitability Dashboard
 *
 * Shows quoted vs actual material spend across all active jobs,
 * highlights over-budget jobs, and surfaces the estimating accuracy trend.
 */
declare(strict_types=1);
require_once __DIR__ . '/../loginAuth/auth.php';
require_once __DIR__ . '/includes/functions.php';
requireLogin();
$user = getCurrentUser();

$pageTitle  = 'Profitability';
$activePage = 'profitability';
?>
<?php include __DIR__ . '/includes/appstack_head.php'; ?>

<!-- ── Page Header ─────────────────────────────────────────────── -->
<div class="d-flex align-items-center justify-content-between mb-4">
    <div>
        <h1 class="h3 mb-0 fw-bold">Job Profitability</h1>
        <p class="text-muted mb-0 small">Quoted vs actual material spend · estimating accuracy</p>
    </div>
    <div class="d-flex gap-2">
        <a href="/crm/expenses_appstack.php" class="btn btn-outline-secondary btn-sm">
            <i data-feather="dollar-sign" style="width:14px;height:14px;"></i> Expenses
        </a>
        <button class="btn btn-sm mw-btn-green" onclick="loadAll()">
            <i data-feather="refresh-cw" style="width:14px;height:14px;"></i> Refresh
        </button>
    </div>
</div>

<!-- ── Summary KPI Cards ────────────────────────────────────────── -->
<div class="row g-3 mb-4" id="kpiCards">
    <div class="col-6 col-md-3">
        <div class="card mw-stat-card">
            <div class="card-body py-3">
                <p class="mw-stat-label">Jobs Tracked</p>
                <p class="mw-stat-value" id="kpi-jobs">—</p>
            </div>
        </div>
    </div>
    <div class="col-6 col-md-3">
        <div class="card mw-stat-card">
            <div class="card-body py-3">
                <p class="mw-stat-label">Materials Quoted</p>
                <p class="mw-stat-value" id="kpi-quoted">—</p>
            </div>
        </div>
    </div>
    <div class="col-6 col-md-3">
        <div class="card mw-stat-card">
            <div class="card-body py-3">
                <p class="mw-stat-label">Materials Spent</p>
                <p class="mw-stat-value" id="kpi-actual">—</p>
            </div>
        </div>
    </div>
    <div class="col-6 col-md-3">
        <div class="card mw-stat-card">
            <div class="card-body py-3">
                <p class="mw-stat-label">Margin</p>
                <p class="mw-stat-value" id="kpi-margin">—</p>
                <p class="small mb-0 text-muted" id="kpi-over-budget"></p>
            </div>
        </div>
    </div>
</div>

<!-- ── Jobs Table ──────────────────────────────────────────────── -->
<div class="card">
    <div class="card-header d-flex align-items-center justify-content-between">
        <h5 class="card-title mb-0">Active Jobs — Material Margin</h5>
        <div class="d-flex gap-2 align-items-center">
            <input type="text" id="profitSearch" class="form-control form-control-sm" placeholder="Search jobs…" style="width:180px;" oninput="filterTable()">
            <select id="profitFilter" class="form-select form-select-sm" style="width:140px;" onchange="filterTable()">
                <option value="">All jobs</option>
                <option value="over">Over budget</option>
                <option value="on_track">On track</option>
                <option value="no_quote">No quote data</option>
            </select>
        </div>
    </div>
    <div class="table-responsive">
        <table class="table table-hover mb-0" id="profitTable">
            <thead>
                <tr>
                    <th>Job / Plan</th>
                    <th>Quote</th>
                    <th class="text-end">Quoted Materials</th>
                    <th class="text-end">Actual Materials</th>
                    <th class="text-end">Total Expenses</th>
                    <th class="text-center">Margin</th>
                    <th class="text-center">Status</th>
                    <th></th>
                </tr>
            </thead>
            <tbody id="profitTableBody">
                <tr><td colspan="8" class="text-center py-5 text-muted">
                    <div class="spinner-border spinner-border-sm me-2" role="status"></div>Loading…
                </td></tr>
            </tbody>
        </table>
    </div>
    <div class="card-footer">
        <small class="text-muted" id="profitFooter">Loading…</small>
    </div>
</div>

<script>
(function() {
    var allJobs = [];

    async function loadAll() {
        try {
            var r = await fetch('/crm/api/expenses.php?action=margin_summary');
            var d = await r.json();
            if (!d.success) throw new Error('Failed to load');

            var s = d.summary;
            allJobs = s.details || [];

            // KPIs
            document.getElementById('kpi-jobs').textContent    = s.jobs_tracked || 0;
            document.getElementById('kpi-quoted').textContent  = '$' + fmtMoney(s.total_quoted || 0);
            document.getElementById('kpi-actual').textContent  = '$' + fmtMoney(s.total_actual || 0);
            var marginPct = s.total_margin_pct;
            var marginEl  = document.getElementById('kpi-margin');
            marginEl.textContent = marginPct !== null ? (marginPct >= 0 ? '+' : '') + marginPct.toFixed(1) + '%' : '—';
            marginEl.className   = 'mw-stat-value ' + (marginPct === null ? '' : marginPct >= 20 ? 'text-success' : marginPct >= 0 ? 'text-warning' : 'text-danger');
            if (s.over_budget_jobs > 0) {
                document.getElementById('kpi-over-budget').textContent = s.over_budget_jobs + ' job' + (s.over_budget_jobs === 1 ? '' : 's') + ' over budget';
                document.getElementById('kpi-over-budget').className   = 'small mb-0 text-danger';
            }

            renderTable(allJobs);
            document.getElementById('profitFooter').textContent =
                allJobs.length + ' job' + (allJobs.length === 1 ? '' : 's') + ' with expense data';
        } catch(e) {
            document.getElementById('profitTableBody').innerHTML =
                '<tr><td colspan="8" class="text-center py-4 text-danger">Error: ' + esc(e.message) + '</td></tr>';
        }
    }

    window.loadAll = loadAll;

    function renderTable(jobs) {
        var tbody = document.getElementById('profitTableBody');
        if (!jobs.length) {
            tbody.innerHTML = '<tr><td colspan="8" class="text-center py-5 text-muted">' +
                '<i data-feather="inbox" style="width:28px;height:28px;display:block;margin:0 auto 8px;"></i>' +
                'No active jobs with expense data yet.<br><small>Link expenses to jobs when snapping receipts in the field.</small></td></tr>';
            if (window.feather) feather.replace();
            return;
        }

        tbody.innerHTML = jobs.map(function(j) {
            var matPct    = j.material_margin_pct;
            var pctLabel  = matPct !== null ? (matPct >= 0 ? '+' : '') + parseFloat(matPct).toFixed(1) + '%' : '—';
            var pctClass  = matPct === null ? 'secondary' : matPct >= 20 ? 'success' : matPct >= 0 ? 'warning' : 'danger';
            var statusBadge = j.status === 'over_budget'
                ? '<span class="badge bg-danger">Over budget</span>'
                : matPct !== null
                    ? '<span class="badge bg-success">On track</span>'
                    : '<span class="badge bg-secondary">No quote</span>';

            // Progress bar for material spend vs quoted
            var barHtml = '';
            if (j.quoted_materials > 0) {
                var fillPct = Math.min((j.actual_materials / j.quoted_materials) * 100, 120);
                var barClass = fillPct > 100 ? 'bg-danger' : fillPct > 80 ? 'bg-warning' : 'bg-success';
                barHtml = '<div class="progress mt-1" style="height:4px;"><div class="progress-bar ' + barClass + '" style="width:' + Math.min(fillPct, 100) + '%;"></div></div>';
            }

            return '<tr data-status="' + (j.status || 'no_quote') + '" data-title="' + esc((j.plan_title || '').toLowerCase()) + '">' +
                '<td><strong>' + esc(j.plan_title || 'Job #' + j.plan_id) + '</strong>' + barHtml + '</td>' +
                '<td>' + (j.quote_number ? '<a href="/crm/quotes/view.php?id=' + j.plan_id + '" target="_blank">' + esc(j.quote_number) + '</a>' : '<span class="text-muted">—</span>') + '</td>' +
                '<td class="text-end">' + (j.quoted_materials > 0 ? '$' + fmtMoney(j.quoted_materials) : '<span class="text-muted">—</span>') + '</td>' +
                '<td class="text-end">$' + fmtMoney(j.actual_materials) + '</td>' +
                '<td class="text-end">$' + fmtMoney(j.actual_total) + '<br><small class="text-muted">' + j.expense_count + ' expense' + (j.expense_count === 1 ? '' : 's') + '</small></td>' +
                '<td class="text-center"><span class="badge bg-' + pctClass + '">' + pctLabel + '</span></td>' +
                '<td class="text-center">' + statusBadge + '</td>' +
                '<td><a href="/crm/jobs/view.php?id=' + j.plan_id + '" class="btn btn-sm btn-outline-secondary" title="View job"><i data-feather="eye" style="width:13px;height:13px;"></i></a></td>' +
            '</tr>';
        }).join('');

        if (window.feather) feather.replace();
    }

    window.filterTable = function() {
        var search  = document.getElementById('profitSearch').value.toLowerCase();
        var filter  = document.getElementById('profitFilter').value;
        var filtered = allJobs.filter(function(j) {
            var title  = (j.plan_title || '').toLowerCase();
            var qn     = (j.quote_number || '').toLowerCase();
            var matchSearch = !search || title.includes(search) || qn.includes(search);
            var matchFilter = !filter
                || (filter === 'over' && j.status === 'over_budget')
                || (filter === 'on_track' && j.status === 'on_track')
                || (filter === 'no_quote' && j.material_margin_pct === null);
            return matchSearch && matchFilter;
        });
        renderTable(filtered);
        document.getElementById('profitFooter').textContent =
            filtered.length + ' of ' + allJobs.length + ' job' + (allJobs.length === 1 ? '' : 's') + ' shown';
    };

    function fmtMoney(v) {
        return parseFloat(v || 0).toLocaleString('en-CA', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
    }

    function esc(s) {
        if (!s) return '';
        var d = document.createElement('div');
        d.textContent = s;
        return d.innerHTML;
    }

    // Auto-load
    loadAll();
})();
</script>

<?php include __DIR__ . '/includes/appstack_footer.php'; ?>

<?php
require_once dirname(__DIR__) . '/../loginAuth/auth.php';
require_once dirname(__DIR__) . '/includes/functions.php';
requireLogin();
$user = getCurrentUser();
requirePermission('expenses.view');

$pageTitle  = 'Revenue by Month';
$activePage = 'reports';
$extraHead  = '<script src="https://unpkg.com/chart.js@4.4.7/dist/chart.umd.js"></script>';
?>
<?php include dirname(__DIR__) . '/includes/appstack_head.php'; ?>

          <div class="d-flex justify-content-between align-items-center mb-3">
              <div>
                  <h1 class="h3 mb-0">Revenue by Month</h1>
                  <a href="/crm/reports_appstack.php" class="text-muted small">&larr; All Reports</a>
              </div>
              <div class="d-flex align-items-center" style="gap:8px;">
                  <button type="button" class="mw-datepicker-trigger" data-mw-dp-commit="input" data-mw-dp-target="#mwStartDate"
                          data-mw-dp-range-group="report-range" data-mw-dp-range-role="start" aria-haspopup="true" aria-expanded="false">
                      <svg class="mw-datepicker-cal-icon" width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="3" y="4" width="18" height="18" rx="2" ry="2"/><line x1="16" y1="2" x2="16" y2="6"/><line x1="8" y1="2" x2="8" y2="6"/><line x1="3" y1="10" x2="21" y2="10"/></svg>
                      <span class="mw-datepicker-date" data-mw-dp-label></span>
                      <svg class="mw-datepicker-chevron" width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><polyline points="6 9 12 15 18 9"/></svg>
                  </button>
                  <input type="date" id="mwStartDate" class="form-control form-control-sm" hidden value="<?= date('Y-01-01') ?>">
                  <span class="text-muted">to</span>
                  <button type="button" class="mw-datepicker-trigger" data-mw-dp-commit="input" data-mw-dp-target="#mwEndDate"
                          data-mw-dp-range-group="report-range" data-mw-dp-range-role="end" aria-haspopup="true" aria-expanded="false">
                      <svg class="mw-datepicker-cal-icon" width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="3" y="4" width="18" height="18" rx="2" ry="2"/><line x1="16" y1="2" x2="16" y2="6"/><line x1="8" y1="2" x2="8" y2="6"/><line x1="3" y1="10" x2="21" y2="10"/></svg>
                      <span class="mw-datepicker-date" data-mw-dp-label></span>
                      <svg class="mw-datepicker-chevron" width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><polyline points="6 9 12 15 18 9"/></svg>
                  </button>
                  <input type="date" id="mwEndDate" class="form-control form-control-sm" hidden value="<?= date('Y-12-31') ?>">
                  <button class="btn btn-primary btn-sm" onclick="mwLoadReport()">Apply</button>
                  <a id="mwExportLink" href="#" class="btn btn-outline-secondary btn-sm"><i data-feather="download" class="mr-1"></i>CSV</a>
              </div>
          </div>

          <!-- Summary Cards -->
          <div class="mw-report-summary" id="mwSummary"></div>

          <!-- Chart -->
          <div class="card mb-4">
              <div class="card-body">
                  <canvas id="mwChart" height="300"></canvas>
              </div>
          </div>

          <!-- Data Table -->
          <div class="card">
              <div class="card-body p-0">
                  <div class="table-responsive">
                      <table class="table table-striped table-sm mb-0" id="mwTable">
                          <thead><tr><th>Month</th><th class="text-right">Invoices</th><th class="text-right">Invoiced</th><th class="text-right">Collected</th><th class="text-right">Outstanding</th></tr></thead>
                          <tbody></tbody>
                      </table>
                  </div>
              </div>
          </div>

          <script>
          var _mwChart = null;
          function mwLoadReport() {
              var start = document.getElementById('mwStartDate').value;
              var end = document.getElementById('mwEndDate').value;
              document.getElementById('mwExportLink').href = '/crm/api/report-export.php?report=revenue-by-month&start=' + start + '&end=' + end;

              fetch('/crm/api/reports.php?report=revenue-by-month&start=' + start + '&end=' + end)
              .then(function(r){ return r.json(); })
              .then(function(data) {
                  if (!data.success) return;

                  // Summary
                  var s = data.summary;
                  document.getElementById('mwSummary').innerHTML =
                      '<div class="mw-report-summary-card"><div class="mw-report-summary-value">$' + Number(s.total_invoiced).toLocaleString(undefined,{minimumFractionDigits:2}) + '</div><div class="mw-report-summary-label">Total Invoiced</div></div>' +
                      '<div class="mw-report-summary-card"><div class="mw-report-summary-value text-success">$' + Number(s.total_collected).toLocaleString(undefined,{minimumFractionDigits:2}) + '</div><div class="mw-report-summary-label">Total Collected</div></div>' +
                      '<div class="mw-report-summary-card"><div class="mw-report-summary-value text-warning">$' + Number(s.total_outstanding).toLocaleString(undefined,{minimumFractionDigits:2}) + '</div><div class="mw-report-summary-label">Outstanding</div></div>';

                  // Chart
                  if (_mwChart) _mwChart.destroy();
                  _mwChart = new Chart(document.getElementById('mwChart'), {
                      type: 'bar',
                      data: { labels: data.labels, datasets: data.datasets },
                      options: {
                          responsive: true, maintainAspectRatio: false,
                          scales: { y: { beginAtZero: true, ticks: { callback: function(v){ return '$' + v.toLocaleString(); } } } },
                          plugins: { tooltip: { callbacks: { label: function(ctx){ return ctx.dataset.label + ': $' + ctx.parsed.y.toLocaleString(undefined,{minimumFractionDigits:2}); } } } }
                      }
                  });

                  // Table
                  var tbody = '';
                  data.table.forEach(function(r) {
                      tbody += '<tr><td>' + r.month_label + '</td><td class="text-right">' + r.invoice_count + '</td>' +
                          '<td class="text-right">$' + Number(r.total_revenue).toLocaleString(undefined,{minimumFractionDigits:2}) + '</td>' +
                          '<td class="text-right">$' + Number(r.total_collected).toLocaleString(undefined,{minimumFractionDigits:2}) + '</td>' +
                          '<td class="text-right">$' + Number(r.total_outstanding).toLocaleString(undefined,{minimumFractionDigits:2}) + '</td></tr>';
                  });
                  document.querySelector('#mwTable tbody').innerHTML = tbody || '<tr><td colspan="5" class="text-center text-muted py-4">No data for selected period</td></tr>';
              });
          }
          document.addEventListener('DOMContentLoaded', mwLoadReport);
          </script>

<?php include dirname(__DIR__) . '/includes/appstack_footer.php'; ?>

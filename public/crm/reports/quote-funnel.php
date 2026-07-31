<?php
require_once dirname(__DIR__) . '/../loginAuth/auth.php';
require_once dirname(__DIR__) . '/includes/functions.php';
requireLogin();
$user = getCurrentUser();
requirePermission('expenses.view');

$pageTitle  = 'Quote Funnel';
$activePage = 'reports';
$extraHead  = '<script src="https://unpkg.com/chart.js@4.4.7/dist/chart.umd.js"></script>';
?>
<?php include dirname(__DIR__) . '/includes/appstack_head.php'; ?>

          <div class="d-flex justify-content-between align-items-center mb-3">
              <div>
                  <h1 class="h3 mb-0">Quote Funnel</h1>
                  <a href="/crm/reports_appstack.php" class="text-muted small">&larr; All Reports</a>
              </div>
              <div class="d-flex align-items-center" style="gap:8px;">
                  <input type="date" id="mwStartDate" class="form-control form-control-sm" style="width:150px;" value="<?= date('Y-01-01') ?>">
                  <span class="text-muted">to</span>
                  <input type="date" id="mwEndDate" class="form-control form-control-sm" style="width:150px;" value="<?= date('Y-12-31') ?>">
                  <button class="btn btn-primary btn-sm" onclick="mwLoadReport()">Apply</button>
                  <a id="mwExportLink" href="#" class="btn btn-outline-secondary btn-sm"><i data-feather="download" class="mr-1"></i>CSV</a>
              </div>
          </div>

          <!-- Conversion Rate -->
          <div class="mw-report-summary" id="mwSummary"></div>

          <!-- Funnel Chart -->
          <div class="card mb-4">
              <div class="card-body">
                  <canvas id="mwChart" height="300"></canvas>
              </div>
          </div>

          <!-- Funnel Table -->
          <div class="card">
              <div class="card-body p-0">
                  <div class="table-responsive">
                      <table class="table table-striped table-sm mb-0" id="mwTable">
                          <thead><tr><th>Stage</th><th class="text-right">Count</th><th class="text-right">Drop-off</th></tr></thead>
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
              document.getElementById('mwExportLink').href = '/crm/api/report-export.php?report=quote-funnel&start=' + start + '&end=' + end;

              fetch('/crm/api/reports.php?report=quote-funnel&start=' + start + '&end=' + end)
              .then(function(r){ return r.json(); })
              .then(function(data) {
                  if (!data.success) return;

                  document.getElementById('mwSummary').innerHTML =
                      '<div class="mw-report-summary-card"><div class="mw-report-summary-value">' + data.conversion + '%</div><div class="mw-report-summary-label">Quote → Accepted Rate</div></div>';

                  if (_mwChart) _mwChart.destroy();
                  _mwChart = new Chart(document.getElementById('mwChart'), {
                      type: 'bar',
                      data: { labels: data.labels, datasets: data.datasets },
                      options: {
                          indexAxis: 'y',
                          responsive: true, maintainAspectRatio: false,
                          scales: { x: { beginAtZero: true } },
                          plugins: { legend: { display: false } }
                      }
                  });

                  var tbody = '';
                  data.table.forEach(function(r, i) {
                      var dropoff = '';
                      if (i > 0 && data.table[i-1].count > 0) {
                          var pct = ((1 - r.count / data.table[i-1].count) * 100).toFixed(1);
                          dropoff = pct + '%';
                      }
                      tbody += '<tr><td>' + r.stage + '</td><td class="text-right">' + r.count + '</td><td class="text-right text-muted">' + dropoff + '</td></tr>';
                  });
                  document.querySelector('#mwTable tbody').innerHTML = tbody || '<tr><td colspan="3" class="text-center text-muted py-4">No data for selected period</td></tr>';
              });
          }
          document.addEventListener('DOMContentLoaded', mwLoadReport);
          </script>

<?php include dirname(__DIR__) . '/includes/appstack_footer.php'; ?>

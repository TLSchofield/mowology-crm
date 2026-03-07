<?php
require_once dirname(__DIR__) . '/../loginAuth/auth.php';
require_once dirname(__DIR__) . '/includes/functions.php';
requireLogin();
$user = getCurrentUser();
requirePermission('expenses.view');

$pageTitle  = 'Revenue by Service';
$activePage = 'reports';
$extraHead  = '<script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.7/dist/chart.umd.min.js"></script>';
?>
<?php include dirname(__DIR__) . '/includes/appstack_head.php'; ?>

          <div class="d-flex justify-content-between align-items-center mb-3">
              <div>
                  <h1 class="h3 mb-0">Revenue by Service</h1>
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

          <div class="row">
              <div class="col-lg-5">
                  <div class="card mb-4">
                      <div class="card-body">
                          <canvas id="mwChart" height="350"></canvas>
                      </div>
                  </div>
              </div>
              <div class="col-lg-7">
                  <div class="card mb-4">
                      <div class="card-body p-0">
                          <div class="table-responsive">
                              <table class="table table-striped table-sm mb-0" id="mwTable">
                                  <thead><tr><th>Service</th><th class="text-right">Visits</th><th class="text-right">Revenue</th><th class="text-right">Margin</th><th class="text-right">Margin %</th></tr></thead>
                                  <tbody></tbody>
                              </table>
                          </div>
                      </div>
                  </div>
              </div>
          </div>

          <script>
          var _mwChart = null;
          function mwLoadReport() {
              var start = document.getElementById('mwStartDate').value;
              var end = document.getElementById('mwEndDate').value;
              document.getElementById('mwExportLink').href = '/crm/api/report-export.php?report=revenue-by-service&start=' + start + '&end=' + end;

              fetch('/crm/api/reports.php?report=revenue-by-service&start=' + start + '&end=' + end)
              .then(function(r){ return r.json(); })
              .then(function(data) {
                  if (!data.success) return;

                  if (_mwChart) _mwChart.destroy();
                  _mwChart = new Chart(document.getElementById('mwChart'), {
                      type: 'doughnut',
                      data: { labels: data.labels, datasets: data.datasets },
                      options: {
                          responsive: true, maintainAspectRatio: false,
                          plugins: {
                              legend: { position: 'bottom' },
                              tooltip: { callbacks: { label: function(ctx){ return ctx.label + ': $' + ctx.parsed.toLocaleString(undefined,{minimumFractionDigits:2}); } } }
                          }
                      }
                  });

                  var tbody = '';
                  data.table.forEach(function(r) {
                      var svc = r.service_type.replace(/_/g, ' ').replace(/\b\w/g, function(c){ return c.toUpperCase(); });
                      tbody += '<tr><td>' + svc + '</td><td class="text-right">' + r.visit_count + '</td>' +
                          '<td class="text-right">$' + Number(r.revenue).toLocaleString(undefined,{minimumFractionDigits:2}) + '</td>' +
                          '<td class="text-right">$' + Number(r.margin).toLocaleString(undefined,{minimumFractionDigits:2}) + '</td>' +
                          '<td class="text-right">' + (r.avg_margin_pct !== null ? Number(r.avg_margin_pct).toFixed(1) + '%' : '—') + '</td></tr>';
                  });
                  document.querySelector('#mwTable tbody').innerHTML = tbody || '<tr><td colspan="5" class="text-center text-muted py-4">No data for selected period</td></tr>';
              });
          }
          document.addEventListener('DOMContentLoaded', mwLoadReport);
          </script>

<?php include dirname(__DIR__) . '/includes/appstack_footer.php'; ?>

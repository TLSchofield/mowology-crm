<?php
require_once dirname(__DIR__) . '/../loginAuth/auth.php';
require_once dirname(__DIR__) . '/includes/functions.php';
requireLogin();
$user = getCurrentUser();
requirePermission('expenses.view');

$pageTitle  = 'Client Lifetime Value';
$activePage = 'reports';
$extraHead  = '<script src="https://unpkg.com/chart.js@4.4.7/dist/chart.umd.js"></script>';
?>
<?php include dirname(__DIR__) . '/includes/appstack_head.php'; ?>

          <div class="d-flex justify-content-between align-items-center mb-3">
              <div>
                  <h1 class="h3 mb-0">Client Lifetime Value</h1>
                  <a href="/crm/reports_appstack.php" class="text-muted small">&larr; All Reports</a>
              </div>
              <div class="d-flex align-items-center" style="gap:8px;">
                  <label class="mb-0 small text-muted">Top</label>
                  <select id="mwLimit" class="form-control form-control-sm" style="width:80px;" onchange="mwLoadReport()">
                      <option value="10">10</option>
                      <option value="20" selected>20</option>
                      <option value="50">50</option>
                  </select>
                  <a id="mwExportLink" href="#" class="btn btn-outline-secondary btn-sm"><i data-feather="download" class="mr-1"></i>CSV</a>
              </div>
          </div>

          <!-- Chart -->
          <div class="card mb-4">
              <div class="card-body">
                  <canvas id="mwChart" height="350"></canvas>
              </div>
          </div>

          <!-- Data Table -->
          <div class="card">
              <div class="card-body p-0">
                  <div class="table-responsive">
                      <table class="table table-striped table-sm mb-0" id="mwTable">
                          <thead><tr><th>#</th><th>Client</th><th>Email</th><th>Phone</th><th class="text-right">Invoices</th><th class="text-right">Total Paid</th><th>First Invoice</th><th>Last Invoice</th></tr></thead>
                          <tbody></tbody>
                      </table>
                  </div>
              </div>
          </div>

          <script>
          var _mwChart = null;
          function mwLoadReport() {
              var limit = document.getElementById('mwLimit').value;
              document.getElementById('mwExportLink').href = '/crm/api/report-export.php?report=client-lifetime-value&limit=' + limit;

              fetch('/crm/api/reports.php?report=client-lifetime-value&limit=' + limit)
              .then(function(r){ return r.json(); })
              .then(function(data) {
                  if (!data.success) return;

                  if (_mwChart) _mwChart.destroy();
                  _mwChart = new Chart(document.getElementById('mwChart'), {
                      type: 'bar',
                      data: { labels: data.labels, datasets: data.datasets },
                      options: {
                          indexAxis: 'y',
                          responsive: true, maintainAspectRatio: false,
                          scales: { x: { beginAtZero: true, ticks: { callback: function(v){ return '$' + v.toLocaleString(); } } } },
                          plugins: { legend: { display: false }, tooltip: { callbacks: { label: function(ctx){ return '$' + ctx.parsed.x.toLocaleString(undefined,{minimumFractionDigits:2}); } } } }
                      }
                  });

                  var tbody = '';
                  data.table.forEach(function(r, i) {
                      tbody += '<tr><td>' + (i+1) + '</td><td><a href="/crm/clients_appstack.php?id=' + r.id + '">' + r.client_name + '</a></td>' +
                          '<td>' + (r.email || '—') + '</td><td>' + (r.phone || '—') + '</td>' +
                          '<td class="text-right">' + r.invoice_count + '</td>' +
                          '<td class="text-right font-weight-bold">$' + Number(r.total_paid).toLocaleString(undefined,{minimumFractionDigits:2}) + '</td>' +
                          '<td>' + (r.first_invoice || '—') + '</td><td>' + (r.last_invoice || '—') + '</td></tr>';
                  });
                  document.querySelector('#mwTable tbody').innerHTML = tbody || '<tr><td colspan="8" class="text-center text-muted py-4">No invoice data found</td></tr>';
              });
          }
          document.addEventListener('DOMContentLoaded', mwLoadReport);
          </script>

<?php include dirname(__DIR__) . '/includes/appstack_footer.php'; ?>

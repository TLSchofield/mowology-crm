<?php
require_once dirname(__DIR__) . '/../loginAuth/auth.php';
require_once dirname(__DIR__) . '/includes/functions.php';
requireLogin();
$user = getCurrentUser();
requirePermission('expenses.view');

$pageTitle  = 'Overdue Invoices';
$activePage = 'reports';
?>
<?php include dirname(__DIR__) . '/includes/appstack_head.php'; ?>

          <div class="d-flex justify-content-between align-items-center mb-3">
              <div>
                  <h1 class="h3 mb-0">Overdue Invoices</h1>
                  <a href="/crm/reports_appstack.php" class="text-muted small">&larr; All Reports</a>
              </div>
              <a id="mwExportLink" href="/crm/api/report-export.php?report=overdue-invoices" class="btn btn-outline-secondary btn-sm"><i data-feather="download" class="mr-1"></i>CSV</a>
          </div>

          <!-- Summary -->
          <div class="mw-report-summary" id="mwSummary"></div>

          <!-- Data Table -->
          <div class="card">
              <div class="card-body p-0">
                  <div class="table-responsive">
                      <table class="table table-striped table-sm mb-0" id="mwTable">
                          <thead><tr><th>Invoice</th><th>Client</th><th>Issue Date</th><th>Due Date</th><th class="text-right">Total</th><th class="text-right">Paid</th><th class="text-right">Balance Due</th><th class="text-right">Days Overdue</th><th>Contact</th></tr></thead>
                          <tbody></tbody>
                      </table>
                  </div>
              </div>
          </div>

          <script>
          function mwLoadReport() {
              fetch('/crm/api/reports.php?report=overdue-invoices')
              .then(function(r){ return r.json(); })
              .then(function(data) {
                  if (!data.success) return;
                  var s = data.summary;

                  document.getElementById('mwSummary').innerHTML =
                      '<div class="mw-report-summary-card"><div class="mw-report-summary-value text-danger">' + s.count + '</div><div class="mw-report-summary-label">Overdue Invoices</div></div>' +
                      '<div class="mw-report-summary-card"><div class="mw-report-summary-value text-danger">$' + Number(s.total_overdue).toLocaleString(undefined,{minimumFractionDigits:2}) + '</div><div class="mw-report-summary-label">Total Overdue</div></div>' +
                      '<div class="mw-report-summary-card"><div class="mw-report-summary-value">' + s.avg_days + '</div><div class="mw-report-summary-label">Avg Days Overdue</div></div>';

                  var tbody = '';
                  data.table.forEach(function(r) {
                      var urgency = Number(r.days_overdue) > 30 ? 'text-danger font-weight-bold' : Number(r.days_overdue) > 14 ? 'text-warning' : '';
                      var contact = '';
                      if (r.email) contact += '<a href="mailto:' + r.email + '">' + r.email + '</a>';
                      if (r.phone) contact += (contact ? '<br>' : '') + r.phone;
                      if (!contact) contact = '—';

                      tbody += '<tr>' +
                          '<td><a href="/crm/invoices/view.php?id=' + r.id + '">' + r.invoice_number + '</a></td>' +
                          '<td>' + r.client_name + '</td>' +
                          '<td>' + r.issue_date + '</td>' +
                          '<td>' + r.due_date + '</td>' +
                          '<td class="text-right">$' + Number(r.total).toLocaleString(undefined,{minimumFractionDigits:2}) + '</td>' +
                          '<td class="text-right">$' + Number(r.amount_paid).toLocaleString(undefined,{minimumFractionDigits:2}) + '</td>' +
                          '<td class="text-right font-weight-bold">$' + Number(r.balance_due).toLocaleString(undefined,{minimumFractionDigits:2}) + '</td>' +
                          '<td class="text-right ' + urgency + '">' + r.days_overdue + ' days</td>' +
                          '<td class="small">' + contact + '</td></tr>';
                  });
                  document.querySelector('#mwTable tbody').innerHTML = tbody || '<tr><td colspan="9" class="text-center text-muted py-4">No overdue invoices</td></tr>';
              });
          }
          document.addEventListener('DOMContentLoaded', mwLoadReport);
          </script>

<?php include dirname(__DIR__) . '/includes/appstack_footer.php'; ?>

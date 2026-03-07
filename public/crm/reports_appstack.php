<?php
require_once __DIR__ . '/../loginAuth/auth.php';
require_once __DIR__ . '/includes/functions.php';
requireLogin();
$user = getCurrentUser();
requirePermission('expenses.view');

$pageTitle  = 'Reports';
$activePage = 'reports';
?>
<?php include 'includes/appstack_head.php'; ?>

          <div class="d-flex justify-content-between align-items-center mb-4">
              <div>
                  <h1 class="h3 mb-1">Reports</h1>
                  <p class="text-muted mb-0">Business intelligence and analytics</p>
              </div>
          </div>

          <div class="mw-report-grid">

              <a href="/crm/reports/revenue-by-month.php" class="mw-report-card">
                  <div class="mw-report-card-icon" style="background:var(--mw-green);">
                      <i data-feather="bar-chart-2"></i>
                  </div>
                  <h5>Revenue by Month</h5>
                  <p class="text-muted small mb-0">Monthly invoiced vs collected revenue trends</p>
              </a>

              <a href="/crm/reports/revenue-by-service.php" class="mw-report-card">
                  <div class="mw-report-card-icon" style="background:#3B82F6;">
                      <i data-feather="pie-chart"></i>
                  </div>
                  <h5>Revenue by Service</h5>
                  <p class="text-muted small mb-0">Revenue breakdown by service type</p>
              </a>

              <a href="/crm/reports/quote-funnel.php" class="mw-report-card">
                  <div class="mw-report-card-icon" style="background:#8B5CF6;">
                      <i data-feather="filter"></i>
                  </div>
                  <h5>Quote Funnel</h5>
                  <p class="text-muted small mb-0">Request to job conversion pipeline</p>
              </a>

              <a href="/crm/reports/crew-profitability.php" class="mw-report-card">
                  <div class="mw-report-card-icon" style="background:var(--mw-orange);">
                      <i data-feather="users"></i>
                  </div>
                  <h5>Crew Profitability</h5>
                  <p class="text-muted small mb-0">Revenue and margin by crew member</p>
              </a>

              <a href="/crm/reports/client-lifetime-value.php" class="mw-report-card">
                  <div class="mw-report-card-icon" style="background:#14B8A6;">
                      <i data-feather="award"></i>
                  </div>
                  <h5>Client Lifetime Value</h5>
                  <p class="text-muted small mb-0">Top clients ranked by total revenue</p>
              </a>

              <a href="/crm/reports/overdue-invoices.php" class="mw-report-card">
                  <div class="mw-report-card-icon" style="background:#EF4444;">
                      <i data-feather="alert-circle"></i>
                  </div>
                  <h5>Overdue Invoices</h5>
                  <p class="text-muted small mb-0">Unpaid invoices requiring follow-up</p>
              </a>

          </div>

<?php include 'includes/appstack_footer.php'; ?>

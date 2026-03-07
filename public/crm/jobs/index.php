<?php
/**
 * Job Plans Management - List View
 * Lists job_plans with visit counts, filters, and bulk delete.
 */
require_once dirname(__DIR__) . '/../loginAuth/auth.php';
require_once dirname(__DIR__) . '/includes/functions.php';
require_once dirname(__DIR__) . '/includes/plan-functions.php';
require_once dirname(__DIR__) . '/includes/error-handler.php';

requireLogin();
$user = getCurrentUser();
requirePermission('jobs.view');

// Initialize error handler
$errorHandler = new CRMErrorHandler('Job Plans', $_SERVER['REQUEST_METHOD']);
$GLOBALS['crm_error_handler'] = $errorHandler;

// Handle filters
$statusFilter = $_GET['status'] ?? '';
$serviceFilter = $_GET['service'] ?? '';
$crewFilter = $_GET['crew'] ?? '';
$searchQuery = trim($_GET['search'] ?? '');

// Build query
$db = getDB();
$plans = [];
$statusCounts = [];
$totalCount = 0;
$activePlansCount = 0;
$todayVisitsCount = 0;
$weekVisitsCount = 0;
$completedVisitsCount = 0;

// Financial KPI variables
$todayRevenue = 0;
$todayCompletedCount = 0;
$weekRevenue = 0;
$weekVisitCount = 0;
$monthRevenue = 0;
$monthCompletedCount = 0;
$totalOutstanding = 0;
$outstandingCount = 0;
$overdueCount = 0;
$planProfitability = [];

// Date ranges for financial queries
$weekStart = date('Y-m-d', strtotime('monday this week'));
$weekEnd = date('Y-m-d', strtotime('sunday this week'));
$monthStart = date('Y-m-01');

try {
    $params = [];
    $whereConditions = ['1=1'];

    if ($statusFilter) {
        $whereConditions[] = 'jp.status = ?';
        $params[] = $statusFilter;
    }

    if ($serviceFilter) {
        $whereConditions[] = 'jp.service_type = ?';
        $params[] = $serviceFilter;
    }

    if ($crewFilter) {
        if ($crewFilter === 'unassigned') {
            $whereConditions[] = 'jp.default_crew_id IS NULL';
        } else {
            $whereConditions[] = 'jp.default_crew_id = ?';
            $params[] = intval($crewFilter);
        }
    }

    if ($searchQuery) {
        $whereConditions[] = '(jp.plan_number LIKE ? OR c.company_name LIKE ? OR p.address LIKE ? OR jp.title LIKE ? OR CONCAT(ct.first_name, " ", ct.last_name) LIKE ?)';
        $searchParam = "%{$searchQuery}%";
        $params[] = $searchParam;
        $params[] = $searchParam;
        $params[] = $searchParam;
        $params[] = $searchParam;
        $params[] = $searchParam;
    }

    $whereClause = implode(' AND ', $whereConditions);

    $stmt = $db->prepare("
        SELECT
            jp.*,
            c.company_name,
            ct.first_name AS contact_first_name,
            ct.last_name AS contact_last_name,
            p.address,
            p.city,
            u.full_name AS crew_name,
            q.quote_number,
            (SELECT COUNT(*) FROM job_visits jv WHERE jv.plan_id = jp.id AND jv.status = 'completed') AS visits_completed,
            (SELECT COUNT(*) FROM job_visits jv WHERE jv.plan_id = jp.id AND jv.status = 'scheduled') AS visits_scheduled,
            (SELECT MIN(jv.scheduled_date) FROM job_visits jv WHERE jv.plan_id = jp.id AND jv.status = 'scheduled' AND jv.scheduled_date >= CURDATE()) AS next_visit
        FROM job_plans jp
        LEFT JOIN properties p ON jp.property_id = p.id
        LEFT JOIN contacts ct ON p.site_contact_id = ct.id
        LEFT JOIN companies c ON jp.company_id = c.id
        LEFT JOIN users u ON jp.default_crew_id = u.id
        LEFT JOIN quotes q ON jp.quote_id = q.id
        WHERE {$whereClause}
        ORDER BY jp.created_at DESC
        LIMIT 200
    ");
    $stmt->execute($params);
    $plans = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // Get counts for filter tabs
    $countStmt = $db->query("
        SELECT status, COUNT(*) AS count
        FROM job_plans
        GROUP BY status
    ");
    $statusCounts = [];
    while ($row = $countStmt->fetch()) {
        $statusCounts[$row['status']] = $row['count'];
    }
    $totalCount = array_sum($statusCounts);

    // Stat cards
    $activePlansCount = $statusCounts['active'] ?? 0;

    $todayVisitsCount = (int)$db->query("
        SELECT COUNT(*) FROM job_visits
        WHERE scheduled_date = CURDATE() AND status IN ('scheduled', 'in_progress')
    ")->fetchColumn();

    $weekVisitsCount = (int)$db->query("
        SELECT COUNT(*) FROM job_visits
        WHERE scheduled_date BETWEEN CURDATE() AND DATE_ADD(CURDATE(), INTERVAL 7 DAY)
        AND status IN ('scheduled', 'in_progress')
    ")->fetchColumn();

    $completedVisitsCount = (int)$db->query("
        SELECT COUNT(*) FROM job_visits WHERE status = 'completed'
    ")->fetchColumn();

    // Financial KPIs: single conditional-aggregate query for visit-based revenue
    $finStmt = $db->prepare("
        SELECT
            COALESCE(SUM(
                CASE WHEN jv.scheduled_date = CURDATE() AND jv.status = 'completed'
                     THEN COALESCE(jv.actual_amount, jp.price_per_visit, jp.monthly_flat_price, jp.seasonal_price, 0)
                     ELSE 0 END
            ), 0) AS today_revenue,
            SUM(CASE WHEN jv.scheduled_date = CURDATE() AND jv.status = 'completed'
                 THEN 1 ELSE 0 END) AS today_completed_count,

            COALESCE(SUM(
                CASE WHEN jv.scheduled_date BETWEEN ? AND ?
                          AND jv.status IN ('scheduled', 'in_progress', 'completed')
                     THEN COALESCE(jv.actual_amount, jp.price_per_visit, jp.monthly_flat_price, jp.seasonal_price, 0)
                     ELSE 0 END
            ), 0) AS week_revenue,
            SUM(CASE WHEN jv.scheduled_date BETWEEN ? AND ?
                          AND jv.status IN ('scheduled', 'in_progress', 'completed')
                 THEN 1 ELSE 0 END) AS week_visit_count,

            COALESCE(SUM(
                CASE WHEN jv.scheduled_date BETWEEN ? AND LAST_DAY(?)
                          AND jv.status = 'completed'
                     THEN COALESCE(jv.actual_amount, jp.price_per_visit, jp.monthly_flat_price, jp.seasonal_price, 0)
                     ELSE 0 END
            ), 0) AS month_revenue,
            SUM(CASE WHEN jv.scheduled_date BETWEEN ? AND LAST_DAY(?)
                          AND jv.status = 'completed'
                 THEN 1 ELSE 0 END) AS month_completed_count

        FROM job_visits jv
        JOIN job_plans jp ON jv.plan_id = jp.id
        WHERE jv.scheduled_date BETWEEN ? AND LAST_DAY(?)
    ");
    $finStmt->execute([
        $weekStart, $weekEnd,
        $weekStart, $weekEnd,
        $monthStart, $monthStart,
        $monthStart, $monthStart,
        $monthStart, $monthStart,
    ]);
    $fin = $finStmt->fetch(PDO::FETCH_ASSOC);
    if ($fin) {
        $todayRevenue = (float)$fin['today_revenue'];
        $todayCompletedCount = (int)$fin['today_completed_count'];
        $weekRevenue = (float)$fin['week_revenue'];
        $weekVisitCount = (int)$fin['week_visit_count'];
        $monthRevenue = (float)$fin['month_revenue'];
        $monthCompletedCount = (int)$fin['month_completed_count'];
    }

    // Accounts receivable
    $arStmt = $db->query("
        SELECT
            COALESCE(SUM(balance_due), 0) AS total_outstanding,
            COUNT(*) AS outstanding_count,
            SUM(CASE WHEN status = 'overdue' THEN 1 ELSE 0 END) AS overdue_count
        FROM invoices
        WHERE status IN ('sent', 'viewed', 'partial', 'overdue')
          AND balance_due > 0
    ");
    $ar = $arStmt->fetch(PDO::FETCH_ASSOC);
    if ($ar) {
        $totalOutstanding = (float)$ar['total_outstanding'];
        $outstandingCount = (int)$ar['outstanding_count'];
        $overdueCount = (int)$ar['overdue_count'];
    }

    // Per-row profitability (batch query for margin %)
    $planIds = array_map('intval', array_column($plans, 'id'));
    $planProfitability = !empty($planIds) ? getStopProfitabilityBatch($planIds) : [];

    // Get staff for crew filter
    $staff = getStaffMembers();

    // Get distinct service types for filter dropdown
    $serviceTypes = [];
    $svcStmt = $db->query("SELECT DISTINCT service_type FROM job_plans WHERE service_type IS NOT NULL AND service_type != '' ORDER BY service_type");
    while ($row = $svcStmt->fetch()) {
        $serviceTypes[] = $row['service_type'];
    }

} catch (PDOException $e) {
    $errorHandler->logDatabaseError($e, '', [], 'Unable to load job plans. Please refresh the page.');
    $staff = [];
    $serviceTypes = [];
}

// Service type display labels
$serviceLabels = [
    'maintenance' => 'Maintenance',
    'cleanup' => 'Cleanup',
    'hedge_trimming' => 'Hedge Trimming',
    'lawn_care' => 'Lawn Care',
    'snow_removal' => 'Snow Removal',
    'landscaping' => 'Landscaping',
    'garden_maintenance' => 'Garden Maintenance',
    'seasonal_cleanup' => 'Seasonal Cleanup',
];

$pageTitle = 'Job Plans';
$activePage = 'jobs';
?>
<?php include dirname(__DIR__) . '/includes/appstack_head.php'; ?>

          <!-- Session Alert Display -->
          <?php if (isset($_SESSION['alert'])):
              $alert = $_SESSION['alert'];
              $alertClass = [
                  'error' => 'alert-danger',
                  'warning' => 'alert-warning',
                  'success' => 'alert-success',
                  'info' => 'alert-info'
              ][$alert['type']] ?? 'alert-info';
          ?>
              <div class="alert <?php echo $alertClass; ?> alert-dismissible fade show" role="alert">
                  <strong><?php echo ucfirst($alert['type']); ?>:</strong> <?php echo h($alert['message']); ?>
                  <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
              </div>
              <?php unset($_SESSION['alert']); ?>
          <?php endif; ?>

          <div class="d-flex justify-content-between align-items-center mb-4">
              <div>
                  <h1 class="h3 mb-1">Job Plans</h1>
                  <p class="text-muted mb-0">Manage service agreements, schedules, and visit tracking</p>
              </div>
              <div class="d-flex" style="gap: 12px;">
                  <a href="/crm/api/export-jobs.php" class="btn btn-outline-secondary btn-sm" style="align-self:center;"><i data-feather="download" class="mr-1"></i> Export CSV</a>
                  <a href="schedule.php" class="btn btn-secondary"><i data-feather="calendar" class="mr-1"></i> Calendar View</a>
                  <a href="new-contract.php" class="btn btn-outline-success"><i data-feather="file-plus" class="mr-1"></i> New Contract</a>
                  <a href="create.php" class="btn btn-primary"><i data-feather="plus" class="mr-1"></i> Create Plan</a>
              </div>
          </div>

          <!-- Financial KPIs -->
          <div class="mw-stats-row mw-stats-financial">
              <div class="mw-stat-card revenue">
                  <h4>Today's Revenue</h4>
                  <div class="value currency"><?php echo formatCurrency($todayRevenue); ?></div>
                  <div class="mw-stat-sub"><?php echo $todayCompletedCount; ?> visit<?php echo $todayCompletedCount !== 1 ? 's' : ''; ?> completed</div>
              </div>
              <div class="mw-stat-card week-revenue">
                  <h4>This Week</h4>
                  <div class="value currency"><?php echo formatCurrency($weekRevenue); ?></div>
                  <div class="mw-stat-sub"><?php echo $weekVisitCount; ?> visit<?php echo $weekVisitCount !== 1 ? 's' : ''; ?> scheduled</div>
              </div>
              <div class="mw-stat-card monthly">
                  <h4>Month to Date</h4>
                  <div class="value currency"><?php echo formatCurrency($monthRevenue); ?></div>
                  <div class="mw-stat-sub"><?php echo $monthCompletedCount; ?> completed in <?php echo date('F'); ?></div>
              </div>
              <div class="mw-stat-card ar <?php echo $overdueCount > 0 ? 'has-overdue' : ''; ?>">
                  <h4>Outstanding</h4>
                  <div class="value currency"><?php echo formatCurrency($totalOutstanding); ?></div>
                  <div class="mw-stat-sub">
                      <?php echo $outstandingCount; ?> invoice<?php echo $outstandingCount !== 1 ? 's' : ''; ?>
                      <?php if ($overdueCount > 0): ?>
                          <span class="mw-stat-overdue-badge"><?php echo $overdueCount; ?> overdue</span>
                      <?php endif; ?>
                  </div>
              </div>
          </div>

          <!-- Activity KPIs -->
          <div class="mw-stats-row mw-stats-activity">
              <div class="mw-stat-card today">
                  <h4>Active Plans</h4>
                  <div class="value"><?php echo $activePlansCount; ?></div>
              </div>
              <div class="mw-stat-card scheduled">
                  <h4>Today's Visits</h4>
                  <div class="value"><?php echo $todayVisitsCount; ?></div>
              </div>
              <div class="mw-stat-card in-progress">
                  <h4>This Week</h4>
                  <div class="value"><?php echo $weekVisitsCount; ?></div>
              </div>
              <div class="mw-stat-card completed">
                  <h4>Completed Visits</h4>
                  <div class="value"><?php echo $completedVisitsCount; ?></div>
              </div>
          </div>

          <!-- Filters -->
          <div class="d-flex flex-wrap align-items-center mb-3" style="gap: 16px;">
              <div class="mw-filter-tabs">
                  <a href="?<?php echo $serviceFilter ? 'service=' . urlencode($serviceFilter) . '&' : ''; ?><?php echo $crewFilter ? 'crew=' . urlencode($crewFilter) . '&' : ''; ?>status=" class="mw-filter-tab <?php echo !$statusFilter ? 'active' : ''; ?>">
                      All <span class="count"><?php echo $totalCount; ?></span>
                  </a>
                  <a href="?<?php echo $serviceFilter ? 'service=' . urlencode($serviceFilter) . '&' : ''; ?><?php echo $crewFilter ? 'crew=' . urlencode($crewFilter) . '&' : ''; ?>status=active" class="mw-filter-tab <?php echo $statusFilter === 'active' ? 'active' : ''; ?>">
                      Active <span class="count"><?php echo $statusCounts['active'] ?? 0; ?></span>
                  </a>
                  <a href="?<?php echo $serviceFilter ? 'service=' . urlencode($serviceFilter) . '&' : ''; ?><?php echo $crewFilter ? 'crew=' . urlencode($crewFilter) . '&' : ''; ?>status=paused" class="mw-filter-tab <?php echo $statusFilter === 'paused' ? 'active' : ''; ?>">
                      Paused <span class="count"><?php echo $statusCounts['paused'] ?? 0; ?></span>
                  </a>
                  <a href="?<?php echo $serviceFilter ? 'service=' . urlencode($serviceFilter) . '&' : ''; ?><?php echo $crewFilter ? 'crew=' . urlencode($crewFilter) . '&' : ''; ?>status=completed" class="mw-filter-tab <?php echo $statusFilter === 'completed' ? 'active' : ''; ?>">
                      Completed <span class="count"><?php echo $statusCounts['completed'] ?? 0; ?></span>
                  </a>
                  <a href="?<?php echo $serviceFilter ? 'service=' . urlencode($serviceFilter) . '&' : ''; ?><?php echo $crewFilter ? 'crew=' . urlencode($crewFilter) . '&' : ''; ?>status=cancelled" class="mw-filter-tab <?php echo $statusFilter === 'cancelled' ? 'active' : ''; ?>">
                      Cancelled <span class="count"><?php echo $statusCounts['cancelled'] ?? 0; ?></span>
                  </a>
              </div>

              <select class="form-control" style="width: auto;" onchange="filterByService(this.value)">
                  <option value="">All Services</option>
                  <?php foreach ($serviceTypes as $svc): ?>
                      <option value="<?php echo htmlspecialchars($svc); ?>" <?php echo $serviceFilter === $svc ? 'selected' : ''; ?>>
                          <?php echo htmlspecialchars($serviceLabels[$svc] ?? ucwords(str_replace('_', ' ', $svc))); ?>
                      </option>
                  <?php endforeach; ?>
              </select>

              <select class="form-control" style="width: auto;" onchange="filterByCrew(this.value)">
                  <option value="">All Crews</option>
                  <option value="unassigned" <?php echo $crewFilter === 'unassigned' ? 'selected' : ''; ?>>Unassigned</option>
                  <?php foreach ($staff as $s): ?>
                      <option value="<?php echo $s['id']; ?>" <?php echo $crewFilter == $s['id'] ? 'selected' : ''; ?>>
                          <?php echo htmlspecialchars($s['full_name']); ?>
                      </option>
                  <?php endforeach; ?>
              </select>

              <form class="mw-search-box" method="GET">
                  <?php if ($statusFilter): ?><input type="hidden" name="status" value="<?php echo htmlspecialchars($statusFilter); ?>"><?php endif; ?>
                  <?php if ($serviceFilter): ?><input type="hidden" name="service" value="<?php echo htmlspecialchars($serviceFilter); ?>"><?php endif; ?>
                  <?php if ($crewFilter): ?><input type="hidden" name="crew" value="<?php echo htmlspecialchars($crewFilter); ?>"><?php endif; ?>
                  <input type="text" name="search" class="mw-search-input"
                         placeholder="Search plans..."
                         value="<?php echo htmlspecialchars($searchQuery); ?>">
              </form>
          </div>

          <!-- Table -->
          <div class="mw-table-card">
              <?php if (empty($plans)): ?>
                  <div class="mw-empty-state">
                      <span class="mw-empty-state-icon" data-feather="clipboard"></span>
                      <p>No job plans found. Create a plan or accept a quote to get started!</p>
                  </div>
              <?php else: ?>
                  <table class="mw-table">
                      <thead>
                          <tr>
                              <th class="mw-bulk-checkbox-cell">
                                  <input type="checkbox" class="mw-bulk-checkbox" id="mw-plans-select-all" title="Select all">
                              </th>
                              <th>Plan #</th>
                              <th>Title</th>
                              <th>Service</th>
                              <th>Property</th>
                              <th>Client</th>
                              <th>Crew</th>
                              <th>Pricing</th>
                              <th>Status</th>
                              <th>Visits</th>
                              <th>Next Visit</th>
                              <th>Actions</th>
                          </tr>
                      </thead>
                      <tbody>
                          <?php foreach ($plans as $plan): ?>
                              <tr data-href="view.php?id=<?php echo (int)$plan['id']; ?>">
                                  <td class="mw-bulk-checkbox-cell">
                                      <input type="checkbox" class="mw-bulk-checkbox mw-bulk-row-select" data-id="<?php echo (int)$plan['id']; ?>">
                                  </td>
                                  <td>
                                      <a href="view.php?id=<?php echo (int)$plan['id']; ?>" class="font-weight-bold">
                                          <?php echo htmlspecialchars($plan['plan_number']); ?>
                                      </a>
                                      <?php if (!empty($plan['quote_number'])): ?>
                                          <div class="text-muted small"><?php echo htmlspecialchars($plan['quote_number']); ?></div>
                                      <?php endif; ?>
                                  </td>
                                  <td>
                                      <div class="font-weight-medium"><?php echo htmlspecialchars($plan['title'] ?: '-'); ?></div>
                                  </td>
                                  <td>
                                      <?php
                                          $svcLabel = $serviceLabels[$plan['service_type'] ?? ''] ?? ucwords(str_replace('_', ' ', $plan['service_type'] ?? ''));
                                      ?>
                                      <span class="badge badge-light"><?php echo htmlspecialchars($svcLabel); ?></span>
                                  </td>
                                  <td>
                                      <div><?php echo htmlspecialchars($plan['address'] ?? '-'); ?></div>
                                      <?php if (!empty($plan['city'])): ?>
                                          <div class="text-muted small"><?php echo htmlspecialchars($plan['city']); ?></div>
                                      <?php endif; ?>
                                  </td>
                                  <td>
                                      <?php
                                          $clientName = $plan['company_name'] ?: trim(($plan['contact_first_name'] ?? '') . ' ' . ($plan['contact_last_name'] ?? ''));
                                      ?>
                                      <div><?php echo htmlspecialchars($clientName ?: 'N/A'); ?></div>
                                  </td>
                                  <td>
                                      <?php if (!empty($plan['crew_name'])): ?>
                                          <span class="badge badge-light"><?php echo htmlspecialchars($plan['crew_name']); ?></span>
                                      <?php else: ?>
                                          <span class="badge badge-warning">Unassigned</span>
                                      <?php endif; ?>
                                  </td>
                                  <td>
                                      <?php
                                          $pricingModel = $plan['pricing_model'] ?? 'per_visit';
                                          $priceDisplay = '-';
                                          if ($pricingModel === 'per_visit' && $plan['price_per_visit']) {
                                              $priceDisplay = formatCurrency($plan['price_per_visit']) . '/visit';
                                          } elseif ($pricingModel === 'monthly_flat' && $plan['monthly_flat_price']) {
                                              $priceDisplay = formatCurrency($plan['monthly_flat_price']) . '/mo';
                                          } elseif ($pricingModel === 'seasonal' && $plan['seasonal_price']) {
                                              $priceDisplay = formatCurrency($plan['seasonal_price']) . '/season';
                                          } elseif ($plan['estimated_amount']) {
                                              $priceDisplay = formatCurrency($plan['estimated_amount']);
                                          }
                                      ?>
                                      <div><?php echo htmlspecialchars($priceDisplay); ?></div>
                                      <div class="text-muted small"><?php echo htmlspecialchars(ucwords(str_replace('_', ' ', $pricingModel))); ?></div>
                                      <?php
                                          $planProfit = $planProfitability[(int)$plan['id']] ?? null;
                                          if ($planProfit && $planProfit['has_data']):
                                              $margin = (int)$planProfit['margin_pct'];
                                              $marginClass = $margin >= 40 ? 'mw-margin-green' : ($margin >= 20 ? 'mw-margin-amber' : 'mw-margin-red');
                                      ?>
                                          <div class="mw-row-margin <?php echo $marginClass; ?>" title="Profit margin: <?php echo $margin; ?>%">
                                              <div class="mw-row-margin-bar">
                                                  <div class="mw-row-margin-fill" style="width: <?php echo max(0, min(100, $margin)); ?>%"></div>
                                              </div>
                                              <span class="mw-row-margin-pct"><?php echo $margin; ?>%</span>
                                          </div>
                                      <?php endif; ?>
                                  </td>
                                  <td>
                                      <?php echo getStatusBadge($plan['status'], 'plan'); ?>
                                      <?php if ($plan['is_recurring']): ?>
                                          <div class="text-muted small mt-1">
                                              <i data-feather="repeat" style="width: 12px; height: 12px;"></i>
                                              <?php echo htmlspecialchars(ucfirst($plan['recurrence_pattern'] ?? 'recurring')); ?>
                                          </div>
                                      <?php endif; ?>
                                  </td>
                                  <td>
                                      <span class="font-weight-bold text-success"><?php echo (int)$plan['visits_completed']; ?></span>
                                      <span class="text-muted">/</span>
                                      <span class="text-primary"><?php echo (int)$plan['visits_scheduled']; ?></span>
                                      <div class="text-muted small">done / sched</div>
                                  </td>
                                  <td>
                                      <?php if ($plan['next_visit']): ?>
                                          <div class="font-weight-bold"><?php echo date('M j, Y', strtotime($plan['next_visit'])); ?></div>
                                          <?php
                                              $daysUntil = (int)((strtotime($plan['next_visit']) - strtotime('today')) / 86400);
                                              if ($daysUntil === 0): ?>
                                                  <span class="badge badge-success">Today</span>
                                              <?php elseif ($daysUntil === 1): ?>
                                                  <span class="badge badge-info">Tomorrow</span>
                                              <?php elseif ($daysUntil <= 7): ?>
                                                  <span class="text-muted small">in <?php echo $daysUntil; ?> days</span>
                                              <?php else: ?>
                                                  <span class="text-muted small"><?php echo date('D', strtotime($plan['next_visit'])); ?></span>
                                              <?php endif; ?>
                                      <?php else: ?>
                                          <span class="text-muted">-</span>
                                      <?php endif; ?>
                                  </td>
                                  <td>
                                      <div class="d-flex" style="gap: 8px;">
                                          <a href="view.php?id=<?php echo (int)$plan['id']; ?>" class="mw-action-btn mw-action-btn-view">View</a>
                                      </div>
                                  </td>
                              </tr>
                          <?php endforeach; ?>
                      </tbody>
                  </table>
              <?php endif; ?>
          </div>

          <!-- Bulk Action Bar -->
          <div class="mw-bulk-action-bar" id="mw-plans-bulk-bar">
              <div>
                  <span class="mw-bulk-count" id="mw-plans-bulk-count">0</span> plans selected
                  <button class="btn btn-sm mw-bulk-clear-btn ml-3" onclick="mwBulkClearPlans()">Clear Selection</button>
              </div>
              <button class="btn btn-sm btn-danger" onclick="mwBulkDeletePlans()">
                  <i data-feather="trash-2"></i> Delete Selected
              </button>
          </div>

          <script>
              function filterByService(value) {
                  var url = new URL(window.location);
                  if (value) {
                      url.searchParams.set('service', value);
                  } else {
                      url.searchParams.delete('service');
                  }
                  window.location = url;
              }

              function filterByCrew(value) {
                  var url = new URL(window.location);
                  if (value) {
                      url.searchParams.set('crew', value);
                  } else {
                      url.searchParams.delete('crew');
                  }
                  window.location = url;
              }

              // Bulk select/delete
              var mwPlansBulkSelected = new Set();

              var plansSelectAll = document.getElementById('mw-plans-select-all');
              if (plansSelectAll) plansSelectAll.addEventListener('change', function() {
                  var checked = this.checked;
                  document.querySelectorAll('.mw-bulk-row-select').forEach(function(cb) {
                      cb.checked = checked;
                      var id = parseInt(cb.dataset.id);
                      if (checked) {
                          mwPlansBulkSelected.add(id);
                      } else {
                          mwPlansBulkSelected.delete(id);
                      }
                  });
                  mwPlansBulkUpdateBar();
              });

              document.addEventListener('change', function(e) {
                  if (e.target.classList.contains('mw-bulk-row-select')) {
                      var id = parseInt(e.target.dataset.id);
                      if (e.target.checked) {
                          mwPlansBulkSelected.add(id);
                      } else {
                          mwPlansBulkSelected.delete(id);
                          if (plansSelectAll) plansSelectAll.checked = false;
                      }
                      mwPlansBulkUpdateBar();
                  }
              });

              function mwPlansBulkUpdateBar() {
                  var bar = document.getElementById('mw-plans-bulk-bar');
                  var count = document.getElementById('mw-plans-bulk-count');
                  count.textContent = mwPlansBulkSelected.size;
                  if (mwPlansBulkSelected.size > 0) {
                      bar.classList.add('mw-bulk-visible');
                  } else {
                      bar.classList.remove('mw-bulk-visible');
                  }
              }

              function mwBulkClearPlans() {
                  mwPlansBulkSelected.clear();
                  document.querySelectorAll('.mw-bulk-row-select').forEach(function(cb) { cb.checked = false; });
                  if (plansSelectAll) plansSelectAll.checked = false;
                  mwPlansBulkUpdateBar();
              }

              function mwBulkDeletePlans() {
                  var count = mwPlansBulkSelected.size;
                  if (count === 0) return;
                  if (!confirm('Permanently delete ' + count + ' plan(s)? This will also remove all associated visits. This cannot be undone.')) return;

                  fetch('api-jobs.php?action=bulk-delete-plans', {
                      method: 'POST',
                      headers: { 'Content-Type': 'application/json' },
                      body: JSON.stringify({ ids: Array.from(mwPlansBulkSelected) })
                  })
                  .then(function(r) { return r.json(); })
                  .then(function(data) {
                      if (data.success) {
                          alert(data.deleted_count + ' plan(s) deleted.');
                          location.reload();
                      } else {
                          alert('Error: ' + (data.error || 'Unknown error'));
                      }
                  })
                  .catch(function(err) { alert('Error: ' + err.message); });
              }
          </script>

<?php include dirname(__DIR__) . '/includes/appstack_footer.php'; ?>

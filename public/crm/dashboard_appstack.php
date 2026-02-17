<?php
require_once __DIR__ . '/../loginAuth/auth.php';
require_once 'includes/functions.php';
require_once 'includes/weather-service.php';
require_once 'includes/error-handler.php';

requireLogin();
$user = getCurrentUser();

// Initialize error handler
$errorHandler = new CRMErrorHandler('Dashboard', $_SERVER['REQUEST_METHOD']);
$GLOBALS['crm_error_handler'] = $errorHandler;

// Get 7-day weather forecast for dashboard (today + 6 days forward)
$weekWeather = getWeekForecast('Vancouver', 'BC');

// Get 7-day profitability data (3 past + today + 3 future, aligned with weather)
$profitStart = date('Y-m-d', strtotime('-3 days'));
$profitEnd = date('Y-m-d', strtotime('+3 days'));
$dailyProfit = getDailyProfitability($profitStart, $profitEnd);

// Get dashboard statistics
$db = getDB();

try {
    // Count quotes by status
    $stats = [];
    $quoteStats = $db->query("
        SELECT status, COUNT(*) as count
        FROM quotes
        GROUP BY status
    ");
    while ($row = $quoteStats->fetch()) {
        $stats[$row['status']] = $row['count'];
    }

    // Count plans by status (replaces jobs)
    try {
        $planStats = $db->query("SELECT status, COUNT(*) as count FROM job_plans GROUP BY status");
        while ($row = $planStats->fetch()) {
            $stats[$row['status']] = ($stats[$row['status']] ?? 0) + $row['count'];
        }
    } catch (Exception $e) { /* table may not exist yet */ }

    // Count today's visits
    try {
        $visitStats = $db->query("SELECT status, COUNT(*) as count FROM job_visits WHERE scheduled_date = CURDATE() GROUP BY status");
        while ($row = $visitStats->fetch()) {
            $stats[$row['status']] = ($stats[$row['status']] ?? 0) + $row['count'];
        }
    } catch (Exception $e) { /* table may not exist yet */ }

    // Calculate useful stats
    $newInquiries = $db->query("SELECT COUNT(*) as count FROM quote_requests WHERE status IN ('new', 'reviewing')")->fetch()['count'];
    $quotesSent = $stats['sent'] ?? 0;
    $jobsAccepted = $stats['accepted'] ?? 0;
    $jobsActive = ($stats['scheduled'] ?? 0) + ($stats['in_progress'] ?? 0);

    // Recent activity (COLLATE needed: quotes may use utf8mb4_unicode_ci, job_plans uses utf8mb4_general_ci)
    $recentActivity = $db->query("
        SELECT 'quote' as type, q.id, q.quote_number COLLATE utf8mb4_general_ci as name, q.created_at, u.full_name COLLATE utf8mb4_general_ci as full_name
        FROM quotes q
        LEFT JOIN users u ON q.created_by = u.id
        UNION ALL
        SELECT 'plan' as type, jp.id, jp.plan_number as name, jp.created_at, u.full_name COLLATE utf8mb4_general_ci as full_name
        FROM job_plans jp
        LEFT JOIN users u ON jp.created_by = u.id
        ORDER BY created_at DESC
        LIMIT 5
    ")->fetchAll();

    // Incoming quote requests for dashboard cards
    $quoteRequests = $db->query("
        SELECT
            qr.id, qr.service_types, qr.urgency, qr.status, qr.created_at,
            c.first_name, c.last_name,
            p.address, p.city
        FROM quote_requests qr
        LEFT JOIN contacts c ON qr.contact_id = c.id
        LEFT JOIN properties p ON qr.property_id = p.id
        WHERE qr.status IN ('new', 'reviewing')
        ORDER BY
            CASE qr.urgency WHEN 'asap' THEN 1 WHEN 'soon' THEN 2 ELSE 3 END,
            qr.created_at DESC
        LIMIT 6
    ")->fetchAll();

    // Work queue items
    $workQueueItems = getWorkQueueItems();

} catch(PDOException $e) {
    $errorHandler->logDatabaseError($e, '', [], 'Unable to load dashboard data. Please refresh the page.');
    $stats = [];
    $totalClients = 0;
    $recentActivity = [];
    $quoteRequests = [];
    $workQueueItems = [];
}

$pageTitle = 'Dashboard';
$activePage = 'dashboard';
?>
<?php include 'includes/appstack_head.php'; ?>

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

          <div class="row mb-2 mb-xl-3">
            <div class="col-auto d-none d-sm-block">
              <h3><strong>Dashboard</strong></h3>
            </div>
          </div>

          <!-- Daily Operations Strip: Weather + Profitability -->
          <?php
          $opsStartDate = new DateTime('-3 days');
          $todayStr = date('Y-m-d');
          // Calculate 7-day totals for summary
          $weekRevenue = 0; $weekCost = 0; $weekProfit = 0;
          $weekVisitsCompleted = 0; $weekVisitsScheduled = 0;
          for ($t = 0; $t < 7; $t++) {
              $td = (clone $opsStartDate)->modify("+{$t} days")->format('Y-m-d');
              $dp = $dailyProfit[$td] ?? [];
              $weekRevenue += ($dp['revenue'] ?? 0) + ($dp['est_revenue'] ?? 0);
              $weekCost += $dp['total_cost'] ?? 0;
              $weekProfit += ($dp['revenue'] ?? 0) - ($dp['total_cost'] ?? 0);
              $weekVisitsCompleted += $dp['visits_completed'] ?? 0;
              $weekVisitsScheduled += $dp['visits_scheduled'] ?? 0;
          }
          $weekMargin = $weekRevenue > 0 ? round(($weekProfit / $weekRevenue) * 100, 1) : 0;
          ?>
          <div class="mw-daily-ops">
              <div class="mw-daily-ops-header">
                  <div class="mw-daily-ops-title">7-Day Operations</div>
                  <div class="mw-daily-ops-summary">
                      <span class="mw-daily-ops-stat">
                          <span class="mw-daily-ops-stat-val">$<?php echo number_format($weekRevenue, 0); ?></span>
                          <span class="mw-daily-ops-stat-lbl">Revenue</span>
                      </span>
                      <span class="mw-daily-ops-stat">
                          <span class="mw-daily-ops-stat-val">$<?php echo number_format($weekProfit, 0); ?></span>
                          <span class="mw-daily-ops-stat-lbl">Profit</span>
                      </span>
                      <span class="mw-daily-ops-stat">
                          <span class="mw-daily-ops-stat-val <?php echo $weekMargin >= 30 ? 'mw-margin-good' : ($weekMargin >= 15 ? 'mw-margin-ok' : 'mw-margin-low'); ?>"><?php echo $weekMargin; ?>%</span>
                          <span class="mw-daily-ops-stat-lbl">Margin</span>
                      </span>
                      <span class="mw-daily-ops-stat">
                          <span class="mw-daily-ops-stat-val"><?php echo $weekVisitsCompleted + $weekVisitsScheduled; ?></span>
                          <span class="mw-daily-ops-stat-lbl">Visits</span>
                      </span>
                  </div>
              </div>
              <div class="mw-daily-ops-grid">
                  <?php
                  $opsDate = clone $opsStartDate;
                  for ($i = 0; $i < 7; $i++):
                      $dateStr = $opsDate->format('Y-m-d');
                      $isToday = ($dateStr === $todayStr);
                      $isPast = ($dateStr < $todayStr);
                      $isFuture = ($dateStr > $todayStr);

                      // Weather data
                      $weather = $weekWeather[$dateStr] ?? [];
                      $wIcon = getWeatherIcon($weather['condition'] ?? 'Clear');
                      $wHigh = (int)($weather['temp_high'] ?? 0);
                      $wLow = (int)($weather['temp_low'] ?? 0);
                      $wPrecip = (int)($weather['precipitation'] ?? 0);

                      // Profitability data
                      $dp = $dailyProfit[$dateStr] ?? [];
                      $revenue = $dp['revenue'] ?? 0;
                      $totalCost = $dp['total_cost'] ?? 0;
                      $profit = $dp['profit'] ?? 0;
                      $margin = $dp['margin_pct'] ?? 0;
                      $visitsCompleted = $dp['visits_completed'] ?? 0;
                      $visitsScheduled = $dp['visits_scheduled'] ?? 0;
                      $estRevenue = $dp['est_revenue'] ?? 0;
                      $hasCompleted = ($visitsCompleted > 0);
                      $hasScheduled = ($visitsScheduled > 0);
                      $hasExpensesOnly = (!$hasCompleted && !$hasScheduled && $totalCost > 0);

                      // Margin color class
                      $marginClass = 'mw-margin-none';
                      if ($hasCompleted && $revenue > 0) {
                          $marginClass = $margin >= 30 ? 'mw-margin-good' : ($margin >= 15 ? 'mw-margin-ok' : 'mw-margin-low');
                      }

                      $dayClass = 'mw-daily-ops-day';
                      if ($isToday) $dayClass .= ' mw-daily-ops-today';
                      if ($isPast) $dayClass .= ' mw-daily-ops-past';
                      if ($isFuture) $dayClass .= ' mw-daily-ops-future';
                  ?>
                      <div class="<?php echo $dayClass; ?>">
                          <!-- Date -->
                          <div class="mw-daily-ops-date">
                              <?php echo $opsDate->format('M j'); ?>
                              <span class="mw-daily-ops-dayname"><?php echo $isToday ? 'Today' : $opsDate->format('D'); ?></span>
                          </div>

                          <!-- Weather -->
                          <div class="mw-daily-ops-weather">
                              <span class="mw-daily-ops-wicon"><?php echo $wIcon; ?></span>
                              <span class="mw-daily-ops-wtemps"><?php echo $wHigh; ?>°/<?php echo $wLow; ?>°</span>
                              <?php if ($wPrecip > 0): ?>
                                  <span class="mw-daily-ops-wprecip"><?php echo $wPrecip; ?>%</span>
                              <?php endif; ?>
                          </div>

                          <!-- Divider -->
                          <div class="mw-daily-ops-divider"></div>

                          <!-- Profitability -->
                          <div class="mw-daily-ops-profit">
                              <?php if ($hasCompleted): ?>
                                  <div class="mw-daily-ops-visits"><?php echo $visitsCompleted; ?> visit<?php echo $visitsCompleted !== 1 ? 's' : ''; ?></div>
                                  <div class="mw-daily-ops-revenue">$<?php echo number_format($revenue, 0); ?></div>
                                  <div class="mw-daily-ops-cost">-$<?php echo number_format($totalCost, 0); ?></div>
                                  <div class="mw-daily-ops-net <?php echo $marginClass; ?>">$<?php echo number_format($profit, 0); ?></div>
                                  <div class="mw-daily-ops-margin <?php echo $marginClass; ?>"><?php echo $margin; ?>%</div>
                              <?php elseif ($hasScheduled): ?>
                                  <div class="mw-daily-ops-visits mw-daily-ops-scheduled"><?php echo $visitsScheduled; ?> sched.</div>
                                  <div class="mw-daily-ops-est">~$<?php echo number_format($estRevenue, 0); ?></div>
                                  <?php if ($totalCost > 0): ?>
                                      <div class="mw-daily-ops-cost">-$<?php echo number_format($totalCost, 0); ?> exp</div>
                                  <?php endif; ?>
                              <?php elseif ($hasExpensesOnly): ?>
                                  <div class="mw-daily-ops-visits">0 visits</div>
                                  <div class="mw-daily-ops-cost">-$<?php echo number_format($totalCost, 0); ?> exp</div>
                              <?php else: ?>
                                  <div class="mw-daily-ops-empty">&mdash;</div>
                              <?php endif; ?>
                          </div>
                      </div>
                  <?php
                      $opsDate->modify('+1 day');
                  endfor;
                  ?>
              </div>
          </div>

          <!-- Incoming Quote Requests -->
          <div class="row mb-4">
            <div class="col-12">
              <div class="card">
                <div class="card-header d-flex justify-content-between align-items-center">
                  <h5 class="card-title mb-0">
                    Incoming Quote Requests
                    <?php if (!empty($quoteRequests)): ?>
                      <span class="badge badge-primary ml-2"><?php echo count($quoteRequests); ?></span>
                    <?php endif; ?>
                  </h5>
                  <a href="products/quote-requests.php" class="btn btn-sm btn-outline-secondary">View All</a>
                </div>
                <div class="card-body">
                  <?php if (empty($quoteRequests)): ?>
                    <div class="text-center text-muted py-4">
                      <i data-feather="inbox" style="width: 36px; height: 36px;"></i>
                      <p class="mt-2 mb-0">No pending quote requests</p>
                    </div>
                  <?php else: ?>
                    <div class="row">
                      <?php foreach ($quoteRequests as $qr):
                        $qrName = trim(($qr['first_name'] ?? '') . ' ' . ($qr['last_name'] ?? ''));
                        if (empty($qrName)) $qrName = 'Unknown Contact';
                        $qrServices = formatServiceTypes($qr['service_types']);
                        $qrServicesStr = !empty($qrServices) ? implode(', ', $qrServices) : 'Not specified';
                      ?>
                        <div class="col-xl-4 col-md-6 mb-3">
                          <a href="quote-workflow.php?request_id=<?php echo (int)$qr['id']; ?>" class="mw-qr-card mw-status-<?php echo h($qr['status']); ?>">
                            <div class="mw-qr-card-name">
                              <?php echo h($qrName); ?>
                              <span class="mw-urgency-badge mw-urgency-<?php echo h($qr['urgency'] ?? 'inquiring'); ?>">
                                <?php echo h(ucfirst($qr['urgency'] ?? 'inquiring')); ?>
                              </span>
                            </div>
                            <div class="mw-qr-card-services"><?php echo h($qrServicesStr); ?></div>
                            <div class="mw-qr-card-meta">
                              <span><?php echo h(timeAgo($qr['created_at'])); ?></span>
                              <span class="mw-status-badge <?php echo h($qr['status']); ?>"><?php echo h(ucfirst($qr['status'])); ?></span>
                            </div>
                            <?php if ($qr['address']): ?>
                              <div class="mw-qr-card-services mt-1" style="font-size: 0.8rem;">
                                <?php echo h($qr['address']); ?><?php if ($qr['city']): ?>, <?php echo h($qr['city']); ?><?php endif; ?>
                              </div>
                            <?php endif; ?>
                          </a>
                        </div>
                      <?php endforeach; ?>
                    </div>
                  <?php endif; ?>
                </div>
              </div>
            </div>
          </div>

          <!-- Stats Cards -->
          <div class="row">
            <div class="col-xl-6 col-xxl-5 d-flex">
              <div class="w-100">
                <div class="row">
                  <div class="col-sm-6">
                    <div class="card stat-card new">
                      <div class="card-body">
                        <h5 class="card-title mb-4">New Inquiries</h5>
                        <h1 class="mt-1 mb-3"><?php echo $newInquiries; ?></h1>
                        <div class="mb-1">
                          <span class="text-muted">Leads waiting for quotes</span>
                        </div>
                      </div>
                    </div>
                    <div class="card stat-card won">
                      <div class="card-body">
                        <h5 class="card-title mb-4">Jobs Accepted</h5>
                        <h1 class="mt-1 mb-3"><?php echo $jobsAccepted; ?></h1>
                        <div class="mb-1">
                          <span class="text-muted">Converted quotes</span>
                        </div>
                      </div>
                    </div>
                  </div>
                  <div class="col-sm-6">
                    <div class="card stat-card quoted">
                      <div class="card-body">
                        <h5 class="card-title mb-4">Quotes Sent</h5>
                        <h1 class="mt-1 mb-3"><?php echo $quotesSent; ?></h1>
                        <div class="mb-1">
                          <span class="text-muted">Pending responses</span>
                        </div>
                      </div>
                    </div>
                    <div class="card stat-card active">
                      <div class="card-body">
                        <h5 class="card-title mb-4">Active Jobs</h5>
                        <h1 class="mt-1 mb-3"><?php echo $jobsActive; ?></h1>
                        <div class="mb-1">
                          <span class="text-muted">In progress or scheduled</span>
                        </div>
                      </div>
                    </div>
                  </div>
                </div>
              </div>
            </div>

            <div class="col-xl-6 col-xxl-7">
              <div class="card flex-fill w-100">
                <div class="card-header">
                  <h5 class="card-title mb-0">Recent Activity</h5>
                </div>
                <div class="card-body py-3">
                  <?php if (empty($recentActivity)): ?>
                    <div class="text-center text-muted py-5">
                      <i data-feather="inbox" style="width: 48px; height: 48px;"></i>
                      <p class="mt-3">No activity yet. Start by adding your first client!</p>
                    </div>
                  <?php else: ?>
                    <div class="timeline">
                      <?php foreach ($recentActivity as $activity): ?>
                        <div class="timeline-item">
                          <strong><?php echo htmlspecialchars($activity['full_name'] ?? 'System'); ?></strong>
                          <span class="float-right text-muted text-sm">
                            <?php echo formatDateTime($activity['created_at'], 'M j, g:i A'); ?>
                          </span>
                          <p><?php echo ucfirst($activity['type']); ?> created: <strong><?php echo htmlspecialchars($activity['name']); ?></strong></p>
                        </div>
                      <?php endforeach; ?>
                    </div>
                  <?php endif; ?>
                </div>
              </div>
            </div>
          </div>

          <!-- Quick Actions -->
          <div class="row">
            <div class="col-12 col-lg-6">
              <div class="card">
                <div class="card-header">
                  <h5 class="card-title mb-0">Quick Actions</h5>
                </div>
                <div class="card-body">
                  <div class="row">
                    <div class="col-6 mb-3">
                      <a href="clients_appstack.php?action=new" class="btn btn-mowology btn-block">
                        <i class="align-middle mr-2" data-feather="user-plus"></i>
                        Add Client
                      </a>
                    </div>
                    <div class="col-6 mb-3">
                      <a href="quotes/create.php" class="btn btn-outline-secondary btn-block">
                        <i class="align-middle mr-2" data-feather="file-text"></i>
                        Create Quote
                      </a>
                    </div>
                    <div class="col-6">
                      <a href="map_appstack.php" class="btn btn-outline-secondary btn-block">
                        <i class="align-middle mr-2" data-feather="map-pin"></i>
                        View Map
                      </a>
                    </div>
                    <div class="col-6">
                      <a href="clients_appstack.php" class="btn btn-outline-secondary btn-block">
                        <i class="align-middle mr-2" data-feather="list"></i>
                        View All Clients
                      </a>
                    </div>
                  </div>
                </div>
              </div>
            </div>

            <div class="col-12 col-lg-6">
              <div class="card mw-wq-card">
                <div class="card-header d-flex justify-content-between align-items-center">
                  <h5 class="card-title mb-0">Work Queue</h5>
                  <?php if (!empty($workQueueItems)): ?>
                    <span class="mw-wq-total-badge"><?php echo count($workQueueItems); ?> item<?php echo count($workQueueItems) > 1 ? 's' : ''; ?></span>
                  <?php endif; ?>
                </div>
                <div class="card-body">
                  <?php if (empty($workQueueItems)): ?>
                    <div class="text-center text-muted py-4">
                      <i data-feather="check-circle" style="width: 36px; height: 36px; color: var(--mw-green);"></i>
                      <p class="mt-2 mb-0">All clear — nothing needs attention</p>
                    </div>
                  <?php else: ?>
                    <?php
                      $categoryLabels = [
                          'critical' => 'Critical',
                          'data_quality' => 'Data Quality',
                          'operations' => 'Operations',
                          'marketing' => 'Marketing',
                      ];
                    ?>
                    <!-- Summary chips -->
                    <div class="mw-wq-chips" id="wqChips">
                      <?php
                        $categoryCounts = [];
                        foreach ($workQueueItems as $item) {
                            $cat = $item['category'];
                            $categoryCounts[$cat] = ($categoryCounts[$cat] ?? 0) + 1;
                        }
                        foreach ($categoryCounts as $cat => $cnt):
                      ?>
                        <button type="button" class="mw-wq-chip mw-wq-chip-<?php echo h($cat); ?>" onclick="wqFilterCategory('<?php echo h($cat); ?>')" title="Show <?php echo h($categoryLabels[$cat] ?? $cat); ?> items">
                          <?php echo h($categoryLabels[$cat] ?? $cat); ?> <span class="mw-wq-chip-count"><?php echo $cnt; ?></span>
                        </button>
                      <?php endforeach; ?>
                      <button type="button" class="mw-wq-chip mw-wq-chip-all active" onclick="wqFilterCategory('all')" title="Show all items">
                        All
                      </button>
                    </div>

                    <!-- Single card display with navigation -->
                    <div class="mw-wq-carousel" id="wqCarousel">
                      <?php foreach ($workQueueItems as $idx => $item): ?>
                        <div class="mw-wq-item mw-wq-cat-<?php echo h($item['category']); ?>" data-category="<?php echo h($item['category']); ?>" <?php if ($idx > 0) echo 'style="display:none;"'; ?>>
                          <div class="mw-wq-item-icon mw-wq-icon-<?php echo h($item['category']); ?>">
                            <i data-feather="<?php echo h($item['icon']); ?>"></i>
                          </div>
                          <div class="mw-wq-item-content">
                            <div class="mw-wq-item-title"><?php echo h($item['title']); ?></div>
                            <div class="mw-wq-item-desc"><?php echo h($item['description']); ?></div>
                          </div>
                          <a href="<?php echo h($item['link']); ?>" class="mw-wq-item-action" title="View">
                            <i data-feather="arrow-right"></i>
                          </a>
                        </div>
                      <?php endforeach; ?>
                    </div>

                    <!-- Navigation -->
                    <div class="mw-wq-nav">
                      <button type="button" class="mw-wq-nav-btn" onclick="wqNav(-1)" id="wqPrev" disabled>
                        <i data-feather="chevron-left"></i>
                      </button>
                      <span class="mw-wq-counter" id="wqCounter">1 / <?php echo count($workQueueItems); ?></span>
                      <button type="button" class="mw-wq-nav-btn" onclick="wqNav(1)" id="wqNext" <?php if (count($workQueueItems) <= 1) echo 'disabled'; ?>>
                        <i data-feather="chevron-right"></i>
                      </button>
                    </div>

                    <script>
                    (function() {
                      var allItems = <?php echo json_encode(array_map(function($i) { return $i['category']; }, $workQueueItems)); ?>;
                      var filteredIndices = allItems.map(function(_, i) { return i; });
                      var currentPos = 0;

                      function showItem() {
                        var els = document.querySelectorAll('#wqCarousel .mw-wq-item');
                        els.forEach(function(el) { el.style.display = 'none'; });
                        if (filteredIndices.length > 0) {
                          els[filteredIndices[currentPos]].style.display = '';
                        }
                        document.getElementById('wqCounter').textContent = (filteredIndices.length > 0 ? (currentPos + 1) : 0) + ' / ' + filteredIndices.length;
                        document.getElementById('wqPrev').disabled = currentPos <= 0;
                        document.getElementById('wqNext').disabled = currentPos >= filteredIndices.length - 1;
                        if (typeof feather !== 'undefined') feather.replace();
                      }

                      window.wqNav = function(dir) {
                        currentPos = Math.max(0, Math.min(filteredIndices.length - 1, currentPos + dir));
                        showItem();
                      };

                      window.wqFilterCategory = function(cat) {
                        // Update chip active state
                        document.querySelectorAll('.mw-wq-chip').forEach(function(c) { c.classList.remove('active'); });
                        if (cat === 'all') {
                          document.querySelector('.mw-wq-chip-all').classList.add('active');
                          filteredIndices = allItems.map(function(_, i) { return i; });
                        } else {
                          document.querySelector('.mw-wq-chip-' + cat).classList.add('active');
                          filteredIndices = [];
                          allItems.forEach(function(c, i) { if (c === cat) filteredIndices.push(i); });
                        }
                        currentPos = 0;
                        showItem();
                      };
                    })();
                    </script>
                  <?php endif; ?>
                </div>
              </div>
            </div>
          </div>

<?php include 'includes/appstack_footer.php'; ?>

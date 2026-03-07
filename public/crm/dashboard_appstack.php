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

    // Crew Today: clock-in status + app install status + active jobs
    $crewToday = [];
    try {
        $crewStmt = $db->query("
            SELECT
                u.id, u.full_name, u.role, u.install_link_sent_at,
                (SELECT COUNT(*) FROM time_clock_entries
                 WHERE user_id = u.id AND clock_out IS NULL AND status = 'active') AS is_clocked_in,
                (SELECT COUNT(*) FROM job_visits
                 WHERE assigned_crew_id = u.id
                   AND status IN ('scheduled', 'in_progress')) AS active_jobs
            FROM users u
            WHERE u.is_active = 1
            ORDER BY is_clocked_in DESC, u.full_name ASC
        ");
        $crewToday = $crewStmt->fetchAll(PDO::FETCH_ASSOC);
    } catch (Exception $e) { $crewToday = []; }

} catch(PDOException $e) {
    $errorHandler->logDatabaseError($e, '', [], 'Unable to load dashboard data. Please refresh the page.');
    $stats = [];
    $totalClients = 0;
    $recentActivity = [];
    $quoteRequests = [];
    $workQueueItems = [];
    $crewToday = [];
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

          <!-- Work Queue — 4-lane attention system -->
          <?php
          $wqLanes = [
              'critical'     => ['label' => 'Critical',     'icon' => 'alert-triangle', 'empty' => 'All systems clear'],
              'data_quality' => ['label' => 'Data Quality',  'icon' => 'database',       'empty' => 'Data looks clean'],
              'operations'   => ['label' => 'Operations',    'icon' => 'clipboard',      'empty' => 'Operations clear'],
              'marketing'    => ['label' => 'Marketing',     'icon' => 'trending-up',    'empty' => 'No marketing tasks'],
          ];
          $wqGrouped = array_fill_keys(array_keys($wqLanes), []);
          foreach ($workQueueItems as $item) {
              $cat = $item['category'];
              if (array_key_exists($cat, $wqGrouped)) $wqGrouped[$cat][] = $item;
          }
          $wqTotal  = count($workQueueItems);
          $wqCounts = array_map('count', $wqGrouped);
          ?>

          <?php if ($wqTotal > 0): ?>
          <div class="mw-wq-summary">
              <span class="mw-wq-summary-total">
                  <i data-feather="list"></i>
                  <strong><?php echo $wqTotal; ?></strong> item<?php echo $wqTotal !== 1 ? 's' : ''; ?> need attention
              </span>
              <div class="mw-wq-summary-cats">
                  <?php foreach ($wqLanes as $key => $lane): if ($wqCounts[$key] > 0): ?>
                      <a href="#wq-<?php echo $key; ?>" class="mw-wq-sum-cat mw-wq-sum-<?php echo $key; ?>">
                          <?php echo $wqCounts[$key]; ?> <?php echo h(strtolower($lane['label'])); ?>
                      </a>
                  <?php endif; endforeach; ?>
              </div>
          </div>
          <?php endif; ?>

          <div class="mw-wq-lanes">
          <?php foreach ($wqLanes as $key => $lane):
              $laneItems = $wqGrouped[$key];
              $laneCount = count($laneItems);
              $showItems = array_slice($laneItems, 0, 5);
              $overflow  = $laneCount - 5;
          ?>
              <div class="mw-wq-lane mw-wq-lane-<?php echo $key; ?>" id="wq-<?php echo $key; ?>">
                  <div class="mw-wq-lane-header" onclick="wqToggleLane('<?php echo $key; ?>')">
                      <div class="mw-wq-lane-header-left">
                          <div class="mw-wq-lane-icon-wrap">
                              <i data-feather="<?php echo h($lane['icon']); ?>"></i>
                          </div>
                          <span class="mw-wq-lane-title"><?php echo h($lane['label']); ?></span>
                      </div>
                      <div class="mw-wq-lane-header-right">
                          <?php if ($laneCount > 0): ?>
                              <span class="mw-wq-lane-badge"><?php echo $laneCount; ?></span>
                          <?php endif; ?>
                          <span class="mw-wq-lane-chevron" id="wqChev-<?php echo $key; ?>">
                              <i data-feather="chevron-down"></i>
                          </span>
                      </div>
                  </div>
                  <div class="mw-wq-lane-body" id="wqBody-<?php echo $key; ?>">
                      <?php if (empty($laneItems)): ?>
                          <div class="mw-wq-empty">
                              <i data-feather="check-circle"></i>
                              <span><?php echo h($lane['empty']); ?></span>
                          </div>
                      <?php else: ?>
                          <?php foreach ($showItems as $item): ?>
                              <a href="<?php echo h($item['link']); ?>" class="mw-wq-item mw-wq-cat-<?php echo h($item['category']); ?>">
                                  <div class="mw-wq-item-icon mw-wq-icon-<?php echo h($item['category']); ?>">
                                      <i data-feather="<?php echo h($item['icon']); ?>"></i>
                                  </div>
                                  <div class="mw-wq-item-content">
                                      <div class="mw-wq-item-title"><?php echo h($item['title']); ?></div>
                                      <div class="mw-wq-item-desc"><?php echo h($item['description']); ?></div>
                                  </div>
                                  <div class="mw-wq-item-arrow"><i data-feather="arrow-right"></i></div>
                              </a>
                          <?php endforeach; ?>
                          <?php if ($overflow > 0): ?>
                              <div class="mw-wq-overflow">
                                  +<?php echo $overflow; ?> more &mdash; <a href="<?php echo h($showItems[0]['link']); ?>">view all</a>
                              </div>
                          <?php endif; ?>
                      <?php endif; ?>
                  </div>
              </div>
          <?php endforeach; ?>
          </div>

          <script>
          (function() {
              var saved = {};
              try { saved = JSON.parse(localStorage.getItem('wqLaneState') || '{}'); } catch(e) {}
              ['critical','data_quality','operations','marketing'].forEach(function(key) {
                  if (saved[key] === 'collapsed') {
                      var el = document.getElementById('wq-' + key);
                      if (el) el.classList.add('mw-wq-collapsed');
                  }
              });
          })();
          window.wqToggleLane = function(key) {
              var lane = document.getElementById('wq-' + key);
              if (!lane) return;
              lane.classList.toggle('mw-wq-collapsed');
              var saved = {};
              try { saved = JSON.parse(localStorage.getItem('wqLaneState') || '{}'); } catch(e) {}
              saved[key] = lane.classList.contains('mw-wq-collapsed') ? 'collapsed' : 'open';
              localStorage.setItem('wqLaneState', JSON.stringify(saved));
          };
          </script>

          <!-- Incoming Quote Requests -->
          <div class="row mb-4" id="incoming-requests">
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
                    <a href="#incoming-requests" class="mw-stat-link">
                      <div class="card stat-card new">
                        <div class="card-body">
                          <h5 class="card-title mb-4">New Inquiries</h5>
                          <h1 class="mt-1 mb-3"><?php echo $newInquiries; ?></h1>
                          <div class="mb-1">
                            <span class="text-muted">Leads waiting for quotes</span>
                          </div>
                        </div>
                      </div>
                    </a>
                    <a href="/crm/quotes/?status=accepted" class="mw-stat-link">
                      <div class="card stat-card won">
                        <div class="card-body">
                          <h5 class="card-title mb-4">Jobs Accepted</h5>
                          <h1 class="mt-1 mb-3"><?php echo $jobsAccepted; ?></h1>
                          <div class="mb-1">
                            <span class="text-muted">Converted quotes</span>
                          </div>
                        </div>
                      </div>
                    </a>
                  </div>
                  <div class="col-sm-6">
                    <a href="/crm/quotes/?status=sent" class="mw-stat-link">
                      <div class="card stat-card quoted">
                        <div class="card-body">
                          <h5 class="card-title mb-4">Quotes Sent</h5>
                          <h1 class="mt-1 mb-3"><?php echo $quotesSent; ?></h1>
                          <div class="mb-1">
                            <span class="text-muted">Pending responses</span>
                          </div>
                        </div>
                      </div>
                    </a>
                    <a href="/crm/jobs/?status=active" class="mw-stat-link">
                      <div class="card stat-card active">
                        <div class="card-body">
                          <h5 class="card-title mb-4">Active Jobs</h5>
                          <h1 class="mt-1 mb-3"><?php echo $jobsActive; ?></h1>
                          <div class="mb-1">
                            <span class="text-muted">In progress or scheduled</span>
                          </div>
                        </div>
                      </div>
                    </a>
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
            <div class="col-12 col-md-6">
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
          </div>

          <!-- Crew Today -->
          <div class="row mt-2">
            <div class="col-12">
              <div class="card mw-crew-card">
                <div class="card-header d-flex justify-content-between align-items-center">
                  <h5 class="card-title mb-0">
                    <i data-feather="users" class="mw-crew-header-icon"></i>
                    Crew Today
                  </h5>
                  <a href="/crm/team/index.php" class="mw-crew-view-all">
                    View Team <i data-feather="arrow-right"></i>
                  </a>
                </div>
                <div class="card-body">
                  <?php if (empty($crewToday)): ?>
                    <div class="text-center text-muted py-3">
                      <i data-feather="user-x" style="width:32px;height:32px;"></i>
                      <p class="mt-2 mb-0">No active crew members</p>
                    </div>
                  <?php else: ?>
                    <div class="mw-crew-grid">
                      <?php foreach ($crewToday as $member): ?>
                        <a href="/crm/team/profile.php?id=<?php echo (int)$member['id']; ?>" class="mw-crew-tile">
                          <div class="mw-crew-status-dot <?php echo $member['is_clocked_in'] ? 'mw-crew-clocked-in' : 'mw-crew-clocked-out'; ?>"></div>
                          <div class="mw-crew-name"><?php echo h($member['full_name']); ?></div>
                          <div class="mw-crew-meta">
                            <?php if ((int)$member['active_jobs'] > 0): ?>
                              <span class="mw-crew-visits-badge"><?php echo (int)$member['active_jobs']; ?> job<?php echo (int)$member['active_jobs'] !== 1 ? 's' : ''; ?></span>
                            <?php endif; ?>
                            <?php if (!empty($member['install_link_sent_at'])): ?>
                              <span class="mw-crew-app-badge" title="App install link sent <?php echo date('M j', strtotime($member['install_link_sent_at'])); ?>">
                                <i data-feather="smartphone"></i>
                              </span>
                            <?php endif; ?>
                          </div>
                        </a>
                      <?php endforeach; ?>
                      <a href="/crm/team/index.php" class="mw-crew-tile mw-crew-tile-action">
                        <i data-feather="user-check" class="mw-crew-action-icon"></i>
                        <div class="mw-crew-name">Manage Team</div>
                      </a>
                    </div>
                  <?php endif; ?>
                </div>
              </div>
            </div>
          </div>

<?php include 'includes/appstack_footer.php'; ?>

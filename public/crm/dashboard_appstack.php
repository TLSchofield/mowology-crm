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

// Get 7-day weather forecast for dashboard
$weekWeather = getWeekForecast('Vancouver', 'BC');

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

} catch(PDOException $e) {
    $errorHandler->logDatabaseError($e, '', [], 'Unable to load dashboard data. Please refresh the page.');
    $stats = [];
    $totalClients = 0;
    $recentActivity = [];
    $quoteRequests = [];
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

          <!-- Weather Forecast Widget -->
          <div class="mw-dashboard-weather">
              <div class="mw-dashboard-weather-title">7-Day Weather Forecast</div>
              <div class="mw-dashboard-weather-grid">
                  <?php
                  $currentDate = new DateTime();
                  for ($i = 0; $i < 7; $i++):
                      $dateStr = $currentDate->format('Y-m-d');
                      $weather = $weekWeather[$dateStr] ?? [];
                      $icon = getWeatherIcon($weather['condition'] ?? 'Clear');
                      $high = (int)($weather['temp_high'] ?? 12);
                      $low = (int)($weather['temp_low'] ?? 8);
                      $precip = (int)($weather['precipitation'] ?? 0);
                  ?>
                      <div class="mw-dashboard-weather-day">
                          <div class="mw-dashboard-weather-dayname">
                              <?php echo $currentDate->format('M j'); ?><br>
                              <small><?php echo $currentDate->format('D'); ?></small>
                          </div>
                          <div class="mw-dashboard-weather-icon"><?php echo $icon; ?></div>
                          <div class="mw-dashboard-weather-temps"><?php echo $high; ?>°/<?php echo $low; ?>°</div>
                          <div class="mw-dashboard-weather-precip">💧 <?php echo $precip; ?>%</div>
                      </div>
                  <?php
                      $currentDate->modify('+1 day');
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
              <div class="card">
                <div class="card-header">
                  <h5 class="card-title mb-0">Build Progress</h5>
                </div>
                <div class="card-body">
                  <div class="mb-3">
                    <div class="d-flex justify-content-between mb-1">
                      <span>Phase 1: Secure Login System</span>
                      <span class="text-success">100%</span>
                    </div>
                    <div class="progress" style="height: 8px;">
                      <div class="progress-bar bg-success" role="progressbar" style="width: 100%"></div>
                    </div>
                  </div>
                  <div class="mb-3">
                    <div class="d-flex justify-content-between mb-1">
                      <span>Phase 2: Client Management</span>
                      <span class="text-muted">0%</span>
                    </div>
                    <div class="progress" style="height: 8px;">
                      <div class="progress-bar" role="progressbar" style="width: 0%"></div>
                    </div>
                  </div>
                  <div class="mb-3">
                    <div class="d-flex justify-content-between mb-1">
                      <span>Phase 3: Lead Capture Form</span>
                      <span class="text-muted">0%</span>
                    </div>
                    <div class="progress" style="height: 8px;">
                      <div class="progress-bar" role="progressbar" style="width: 0%"></div>
                    </div>
                  </div>
                  <div class="mb-3">
                    <div class="d-flex justify-content-between mb-1">
                      <span>Phase 4: Territory Map</span>
                      <span class="text-muted">0%</span>
                    </div>
                    <div class="progress" style="height: 8px;">
                      <div class="progress-bar" role="progressbar" style="width: 0%"></div>
                    </div>
                  </div>
                  <div>
                    <div class="d-flex justify-content-between mb-1">
                      <span>Phase 5: Property Measurement</span>
                      <span class="text-muted">0%</span>
                    </div>
                    <div class="progress" style="height: 8px;">
                      <div class="progress-bar" role="progressbar" style="width: 0%"></div>
                    </div>
                  </div>
                </div>
              </div>
            </div>
          </div>

<?php include 'includes/appstack_footer.php'; ?>

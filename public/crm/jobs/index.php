<?php
/**
 * Jobs Management - List View
 */
require_once dirname(__DIR__) . '/../loginAuth/auth.php';
require_once dirname(__DIR__) . '/includes/functions.php';

requireLogin();
$user = getCurrentUser();

// Handle filters
$statusFilter = $_GET['status'] ?? '';
$assignedFilter = $_GET['assigned'] ?? '';
$dateFilter = $_GET['date'] ?? '';
$searchQuery = trim($_GET['search'] ?? '');

// Build query
$db = getDB();
$params = [];
$whereConditions = ['1=1'];

if ($statusFilter) {
    $whereConditions[] = 'j.status = ?';
    $params[] = $statusFilter;
}

if ($assignedFilter) {
    if ($assignedFilter === 'unassigned') {
        $whereConditions[] = 'j.assigned_to IS NULL';
    } else {
        $whereConditions[] = 'j.assigned_to = ?';
        $params[] = intval($assignedFilter);
    }
}

if ($dateFilter) {
    $whereConditions[] = 'j.scheduled_date = ?';
    $params[] = $dateFilter;
}

if ($searchQuery) {
    $whereConditions[] = '(j.job_number LIKE ? OR c.company_name LIKE ? OR p.address LIKE ? OR j.title LIKE ?)';
    $searchParam = "%{$searchQuery}%";
    $params[] = $searchParam;
    $params[] = $searchParam;
    $params[] = $searchParam;
    $params[] = $searchParam;
}

$whereClause = implode(' AND ', $whereConditions);

$stmt = $db->prepare("
    SELECT
        j.*,
        c.company_name,
        p.address as property_address,
        p.city as property_city,
        u.full_name as assigned_to_name,
        q.quote_number
    FROM jobs j
    LEFT JOIN properties p ON j.property_id = p.id
    LEFT JOIN companies c ON j.company_id = c.id
    LEFT JOIN users u ON j.assigned_to = u.id
    LEFT JOIN quotes q ON j.quote_id = q.id
    WHERE {$whereClause}
    ORDER BY j.scheduled_date ASC, j.scheduled_time_start ASC
    LIMIT 100
");
$stmt->execute($params);
$jobs = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Get counts for filter tabs
$countStmt = $db->query("
    SELECT status, COUNT(*) as count
    FROM jobs
    GROUP BY status
");
$statusCounts = [];
while ($row = $countStmt->fetch()) {
    $statusCounts[$row['status']] = $row['count'];
}
$totalCount = array_sum($statusCounts);

// Get staff for filter
$staff = getStaffMembers();

// Count today's jobs
$todayCount = $db->query("SELECT COUNT(*) FROM jobs WHERE scheduled_date = CURDATE()")->fetchColumn();

$pageTitle = 'Jobs';
$activePage = 'jobs';
?>
<?php include dirname(__DIR__) . '/includes/appstack_head.php'; ?>

          <div class="d-flex justify-content-between align-items-center mb-4">
              <div>
                  <h1 class="h3 mb-1">Jobs</h1>
                  <p class="text-muted mb-0">Manage scheduled work and track completion</p>
              </div>
              <div class="d-flex" style="gap: 12px;">
                  <a href="schedule.php" class="btn btn-secondary"><i data-feather="calendar" class="mr-1"></i> Calendar View</a>
                  <a href="create.php" class="btn btn-primary"><i data-feather="plus" class="mr-1"></i> Create Job</a>
              </div>
          </div>

          <!-- Stats -->
          <div class="mw-stats-row">
              <div class="mw-stat-card today">
                  <h4>Today</h4>
                  <div class="value"><?php echo $todayCount; ?></div>
              </div>
              <div class="mw-stat-card scheduled">
                  <h4>Scheduled</h4>
                  <div class="value"><?php echo $statusCounts['scheduled'] ?? 0; ?></div>
              </div>
              <div class="mw-stat-card in-progress">
                  <h4>In Progress</h4>
                  <div class="value"><?php echo $statusCounts['in_progress'] ?? 0; ?></div>
              </div>
              <div class="mw-stat-card completed">
                  <h4>Completed</h4>
                  <div class="value"><?php echo $statusCounts['completed'] ?? 0; ?></div>
              </div>
          </div>

          <div class="d-flex flex-wrap align-items-center mb-3" style="gap: 16px;">
              <div class="mw-filter-tabs">
                  <a href="?status=" class="mw-filter-tab <?php echo !$statusFilter ? 'active' : ''; ?>">
                      All <span class="count"><?php echo $totalCount; ?></span>
                  </a>
                  <a href="?status=scheduled" class="mw-filter-tab <?php echo $statusFilter === 'scheduled' ? 'active' : ''; ?>">
                      Scheduled <span class="count"><?php echo $statusCounts['scheduled'] ?? 0; ?></span>
                  </a>
                  <a href="?status=in_progress" class="mw-filter-tab <?php echo $statusFilter === 'in_progress' ? 'active' : ''; ?>">
                      In Progress <span class="count"><?php echo $statusCounts['in_progress'] ?? 0; ?></span>
                  </a>
                  <a href="?status=completed" class="mw-filter-tab <?php echo $statusFilter === 'completed' ? 'active' : ''; ?>">
                      Completed <span class="count"><?php echo $statusCounts['completed'] ?? 0; ?></span>
                  </a>
              </div>

              <select class="form-control" style="width: auto;" onchange="filterByAssigned(this.value)">
                  <option value="">All Staff</option>
                  <option value="unassigned" <?php echo $assignedFilter === 'unassigned' ? 'selected' : ''; ?>>Unassigned</option>
                  <?php foreach ($staff as $s): ?>
                      <option value="<?php echo $s['id']; ?>" <?php echo $assignedFilter == $s['id'] ? 'selected' : ''; ?>>
                          <?php echo htmlspecialchars($s['full_name']); ?>
                      </option>
                  <?php endforeach; ?>
              </select>

              <input type="date" class="form-control" style="width: auto;" value="<?php echo htmlspecialchars($dateFilter); ?>"
                     onchange="filterByDate(this.value)">

              <form class="mw-search-box" method="GET">
                  <?php if ($statusFilter): ?><input type="hidden" name="status" value="<?php echo htmlspecialchars($statusFilter); ?>"><?php endif; ?>
                  <input type="text" name="search" class="mw-search-input"
                         placeholder="Search jobs..."
                         value="<?php echo htmlspecialchars($searchQuery); ?>">
              </form>
          </div>

          <div class="mw-table-card">
              <?php if (empty($jobs)): ?>
                  <div class="mw-empty-state">
                      <span class="mw-empty-state-icon" data-feather="tool"></span>
                      <p>No jobs found. Create a job or accept a quote to get started!</p>
                  </div>
              <?php else: ?>
                  <table class="mw-table">
                      <thead>
                          <tr>
                              <th>Job #</th>
                              <th>Client / Property</th>
                              <th>Service</th>
                              <th>Scheduled</th>
                              <th>Assigned To</th>
                              <th>Status</th>
                              <th>Actions</th>
                          </tr>
                      </thead>
                      <tbody>
                          <?php foreach ($jobs as $job): ?>
                              <tr>
                                  <td>
                                      <span class="font-weight-bold"><?php echo htmlspecialchars($job['job_number']); ?></span>
                                  </td>
                                  <td>
                                      <div class="font-weight-medium"><?php echo htmlspecialchars($job['company_name'] ?? 'N/A'); ?></div>
                                      <div class="text-muted small"><?php echo htmlspecialchars($job['property_address'] ?? ''); ?></div>
                                  </td>
                                  <td>
                                      <div><?php echo htmlspecialchars($job['title'] ?: ucfirst(str_replace('_', ' ', $job['service_type']))); ?></div>
                                  </td>
                                  <td>
                                      <?php if ($job['scheduled_date']): ?>
                                          <div class="font-weight-bold"><?php echo date('M j, Y', strtotime($job['scheduled_date'])); ?></div>
                                          <?php if ($job['scheduled_time_start']): ?>
                                              <div class="text-muted small"><?php echo date('g:i A', strtotime($job['scheduled_time_start'])); ?></div>
                                          <?php endif; ?>
                                      <?php else: ?>
                                          <span class="text-warning">Not scheduled</span>
                                      <?php endif; ?>
                                  </td>
                                  <td>
                                      <?php if ($job['assigned_to_name']): ?>
                                          <span class="badge badge-light"><?php echo htmlspecialchars($job['assigned_to_name']); ?></span>
                                      <?php else: ?>
                                          <span class="badge badge-warning">Unassigned</span>
                                      <?php endif; ?>
                                  </td>
                                  <td><?php echo getStatusBadge($job['status'], 'job'); ?></td>
                                  <td>
                                      <div class="d-flex" style="gap: 8px;">
                                          <a href="view.php?id=<?php echo $job['id']; ?>" class="mw-action-btn mw-action-btn-view">View</a>
                                          <?php if ($job['status'] === 'scheduled'): ?>
                                              <a href="view.php?id=<?php echo $job['id']; ?>&action=start" class="mw-action-btn mw-action-btn-start">Start</a>
                                          <?php elseif ($job['status'] === 'in_progress'): ?>
                                              <a href="view.php?id=<?php echo $job['id']; ?>&action=complete" class="mw-action-btn mw-action-btn-complete">Complete</a>
                                          <?php endif; ?>
                                      </div>
                                  </td>
                              </tr>
                          <?php endforeach; ?>
                      </tbody>
                  </table>
              <?php endif; ?>
          </div>

          <script>
              function filterByAssigned(value) {
                  const url = new URL(window.location);
                  if (value) {
                      url.searchParams.set('assigned', value);
                  } else {
                      url.searchParams.delete('assigned');
                  }
                  window.location = url;
              }

              function filterByDate(value) {
                  const url = new URL(window.location);
                  if (value) {
                      url.searchParams.set('date', value);
                  } else {
                      url.searchParams.delete('date');
                  }
                  window.location = url;
              }
          </script>

<?php include dirname(__DIR__) . '/includes/appstack_footer.php'; ?>

<?php
/**
 * Job Schedule - Calendar View
 */
require_once dirname(__DIR__) . '/../loginAuth/auth.php';
require_once dirname(__DIR__) . '/includes/functions.php';
require_once dirname(__DIR__) . '/includes/weather-service.php';

requireLogin();
$user = getCurrentUser();

$db = getDB();

// Get date range (current week by default)
$startDate = isset($_GET['start']) ? $_GET['start'] : date('Y-m-d', strtotime('monday this week'));
$endDate = date('Y-m-d', strtotime($startDate . ' +6 days'));

// Get jobs for the date range
$stmt = $db->prepare("
    SELECT
        j.*,
        c.company_name,
        p.address as property_address,
        p.city as property_city,
        u.full_name as assigned_to_name
    FROM jobs j
    LEFT JOIN properties p ON j.property_id = p.id
    LEFT JOIN companies c ON j.company_id = c.id
    LEFT JOIN users u ON j.assigned_to = u.id
    WHERE j.scheduled_date BETWEEN ? AND ?
    AND j.status IN ('scheduled', 'in_progress')
    ORDER BY j.scheduled_date, j.scheduled_time_start
");
$stmt->execute([$startDate, $endDate]);
$jobs = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Group jobs by date
$jobsByDate = [];
foreach ($jobs as $job) {
    $date = $job['scheduled_date'];
    if (!isset($jobsByDate[$date])) {
        $jobsByDate[$date] = [];
    }
    $jobsByDate[$date][] = $job;
}

// Get staff for filter
$staff = getStaffMembers();

// Service type colors
$serviceColors = [
    'landscaping' => '#2D8659',
    'lawn_care' => '#7FD858',
    'snow_removal' => '#3B82F6',
    'hedge_trimming' => '#8B5CF6',
    'garden_maintenance' => '#F59E0B',
    'seasonal_cleanup' => '#EC4899',
];

function getServiceColor($type) {
    global $serviceColors;
    return $serviceColors[$type] ?? '#6B7280';
}

$pageTitle = 'Schedule';
$activePage = 'schedule';
?>
<?php include dirname(__DIR__) . '/includes/appstack_head.php'; ?>

          <div class="mw-page-header">
              <div>
                  <h1 class="h3">Schedule</h1>
              </div>

              <div class="mw-header-nav">
                  <a href="?start=<?php echo date('Y-m-d', strtotime($startDate . ' -7 days')); ?>" class="mw-nav-btn">&larr;</a>
                  <div class="mw-date-display">
                      <?php echo date('M j', strtotime($startDate)); ?> - <?php echo date('M j, Y', strtotime($endDate)); ?>
                  </div>
                  <a href="?start=<?php echo date('Y-m-d', strtotime($startDate . ' +7 days')); ?>" class="mw-nav-btn">&rarr;</a>
                  <a href="?" class="mw-today-btn">Today</a>
              </div>

              <div>
                  <a href="index.php" class="btn btn-secondary">List View</a>
                  <a href="create.php" class="btn btn-primary">+ New Job</a>
              </div>
          </div>

          <!-- Legend -->
          <div class="mw-legend">
              <?php foreach ($serviceColors as $service => $color): ?>
                  <div class="mw-legend-item">
                      <div class="mw-legend-color" style="background: <?php echo $color; ?>"></div>
                      <span><?php echo ucfirst(str_replace('_', ' ', $service)); ?></span>
                  </div>
              <?php endforeach; ?>
          </div>

          <div class="mw-calendar-container">
              <div class="mw-calendar-header">
                  <div class="mw-calendar-header-cell">Monday</div>
                  <div class="mw-calendar-header-cell">Tuesday</div>
                  <div class="mw-calendar-header-cell">Wednesday</div>
                  <div class="mw-calendar-header-cell">Thursday</div>
                  <div class="mw-calendar-header-cell">Friday</div>
                  <div class="mw-calendar-header-cell">Saturday</div>
                  <div class="mw-calendar-header-cell">Sunday</div>
              </div>

              <div class="mw-calendar-grid">
                  <?php
                  $currentDate = new DateTime($startDate);
                  $today = date('Y-m-d');

                  for ($i = 0; $i < 7; $i++):
                      $dateStr = $currentDate->format('Y-m-d');
                      $dayJobs = $jobsByDate[$dateStr] ?? [];
                      $isToday = ($dateStr === $today);

                      // Get weather for this day
                      $weather = getWeatherForecast('Vancouver', 'BC', $dateStr);
                      $weatherIcon = getWeatherIcon($weather['condition'] ?? 'Clear');
                      $workSuitability = getWorkSuitability($weather);
                      $suitabilityClass = $workSuitability >= 70 ? 'good' : ($workSuitability >= 40 ? 'fair' : 'poor');
                  ?>
                      <div class="mw-calendar-day <?php echo $isToday ? 'today' : ''; ?>">
                          <div class="mw-day-header">
                              <div>
                                  <span class="mw-day-number"><?php echo $currentDate->format('j'); ?></span>
                                  <span class="mw-day-name"><?php echo $currentDate->format('D'); ?></span>
                              </div>
                              <?php if (count($dayJobs) > 0): ?>
                                  <span class="mw-job-count"><?php echo count($dayJobs); ?> job<?php echo count($dayJobs) > 1 ? 's' : ''; ?></span>
                              <?php endif; ?>
                          </div>

                          <!-- Weather Forecast -->
                          <div class="mw-day-weather weather-<?php echo $suitabilityClass; ?>">
                              <div class="mw-day-weather-header">
                                  <span class="mw-day-weather-icon"><?php echo $weatherIcon; ?></span>
                                  <span class="mw-day-weather-temp"><?php echo (int)$weather['temp_high']; ?>°/<?php echo (int)$weather['temp_low']; ?>°</span>
                              </div>
                              <div class="mw-day-weather-condition"><?php echo htmlspecialchars($weather['condition'] ?? 'Clear'); ?></div>
                              <div>
                                  <span class="mw-day-weather-precip">💧 <?php echo (int)$weather['precipitation']; ?>%</span>
                                  <span class="mw-day-weather-wind">💨 <?php echo (int)$weather['wind']; ?> km/h</span>
                              </div>
                              <div class="mw-suitability-badge mw-suitability-<?php echo $suitabilityClass; ?>">
                                  <?php if ($workSuitability >= 70): ?>
                                      ✓ Good for work
                                  <?php elseif ($workSuitability >= 40): ?>
                                      ⚠ Fair conditions
                                  <?php else: ?>
                                      ✗ Poor conditions
                                  <?php endif; ?>
                              </div>
                          </div>

                          <?php if (empty($dayJobs)): ?>
                              <div class="mw-empty-day">No jobs</div>
                          <?php else: ?>
                              <?php foreach ($dayJobs as $job): ?>
                                  <a href="view.php?id=<?php echo $job['id']; ?>" class="mw-job-card-sched <?php echo $job['status'] === 'in_progress' ? 'in-progress' : ''; ?>"
                                       style="border-left-color: <?php echo getServiceColor($job['service_type']); ?>">
                                      <?php if ($job['scheduled_time_start']): ?>
                                          <div class="mw-job-time"><?php echo date('g:i A', strtotime($job['scheduled_time_start'])); ?></div>
                                      <?php endif; ?>
                                      <div class="mw-job-title-sched"><?php echo htmlspecialchars($job['title'] ?: $job['job_number']); ?></div>
                                      <div class="mw-job-client-sched"><?php echo htmlspecialchars($job['company_name']); ?></div>
                                      <?php if ($job['assigned_to_name']): ?>
                                          <div class="mw-job-assigned-sched"><?php echo htmlspecialchars($job['assigned_to_name']); ?></div>
                                      <?php endif; ?>
                                  </a>
                              <?php endforeach; ?>
                          <?php endif; ?>
                      </div>
                  <?php
                      $currentDate->modify('+1 day');
                  endfor;
                  ?>
              </div>
          </div>

<?php include dirname(__DIR__) . '/includes/appstack_footer.php'; ?>

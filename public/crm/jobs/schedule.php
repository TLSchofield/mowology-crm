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

          <!-- Hourly Calendar with Time Grid -->
          <div class="mw-calendar-container">
              <div class="mw-calendar-header">
                  <div class="mw-calendar-time-label">TIME</div>
                  <div class="mw-calendar-header-cell">Monday</div>
                  <div class="mw-calendar-header-cell">Tuesday</div>
                  <div class="mw-calendar-header-cell">Wednesday</div>
                  <div class="mw-calendar-header-cell">Thursday</div>
                  <div class="mw-calendar-header-cell">Friday</div>
                  <div class="mw-calendar-header-cell">Saturday</div>
                  <div class="mw-calendar-header-cell">Sunday</div>
              </div>

              <div class="mw-calendar-dates-header">
                  <div class="mw-calendar-time-label"></div>
                  <?php
                  $currentDate = new DateTime($startDate);
                  for ($i = 0; $i < 7; $i++):
                      $dateStr = $currentDate->format('Y-m-d');
                      $isToday = ($dateStr === date('Y-m-d'));
                  ?>
                      <div class="mw-calendar-date-cell <?php echo $isToday ? 'today' : ''; ?>" data-date="<?php echo $dateStr; ?>" data-day-index="<?php echo $i; ?>">
                          <div class="mw-date-number"><?php echo $currentDate->format('j'); ?></div>
                          <div class="mw-weather-icon" title="<?php echo getWeatherForecast('Vancouver', 'BC', $dateStr)['condition'] ?? 'Clear'; ?>">
                              <?php
                              $weather = getWeatherForecast('Vancouver', 'BC', $dateStr);
                              echo getWeatherIcon($weather['condition'] ?? 'Clear');
                              ?>
                          </div>
                          <div class="mw-temp-range"><?php echo (int)$weather['temp_high']; ?>°/<?php echo (int)$weather['temp_low']; ?>°</div>
                      </div>
                  <?php
                      $currentDate->modify('+1 day');
                  endfor;
                  ?>
              </div>

              <!-- Time Grid -->
              <div class="mw-calendar-grid-hourly">
                  <?php
                  // Time slots from 6 AM to 6 PM
                  for ($hour = 6; $hour < 19; $hour++):
                      $timeLabel = date('g A', strtotime("$hour:00"));
                  ?>
                      <div class="mw-time-row">
                          <div class="mw-time-label"><?php echo $timeLabel; ?></div>

                          <?php
                          $currentDate = new DateTime($startDate);
                          for ($dayIdx = 0; $dayIdx < 7; $dayIdx++):
                              $dateStr = $currentDate->format('Y-m-d');
                              $dayJobs = $jobsByDate[$dateStr] ?? [];
                          ?>
                              <div class="mw-time-slot" data-date="<?php echo $dateStr; ?>" data-hour="<?php echo $hour; ?>">
                                  <?php foreach ($dayJobs as $job):
                                      if (!$job['scheduled_time_start']) continue;
                                      $jobStartHour = (int)date('H', strtotime($job['scheduled_time_start']));
                                      if ($jobStartHour === $hour):
                                  ?>
                                      <div class="mw-job-card-sched <?php echo $job['status'] === 'in_progress' ? 'in-progress' : ''; ?>"
                                           data-job-id="<?php echo (int)$job['id']; ?>"
                                           data-job-number="<?php echo htmlspecialchars($job['job_number']); ?>"
                                           data-scheduled-date="<?php echo $job['scheduled_date']; ?>"
                                           data-scheduled-time="<?php echo htmlspecialchars($job['scheduled_time_start'] ?? ''); ?>"
                                           draggable="true"
                                           style="border-left-color: <?php echo getServiceColor($job['service_type']); ?>">
                                          <div class="mw-job-time"><?php echo date('g:i A', strtotime($job['scheduled_time_start'])); ?></div>
                                          <div class="mw-job-title-sched"><?php echo htmlspecialchars($job['title'] ?: $job['job_number']); ?></div>
                                          <div class="mw-job-client-sched"><?php echo htmlspecialchars(($job['company_name'] ?? 'Unknown Client')); ?></div>
                                          <?php if ($job['assigned_to_name']): ?>
                                              <div class="mw-job-assigned-sched"><?php echo htmlspecialchars($job['assigned_to_name']); ?></div>
                                          <?php endif; ?>
                                          <a href="view.php?id=<?php echo (int)$job['id']; ?>" class="mw-job-card-view-link" title="View job details">View</a>
                                      </div>
                                  <?php
                                      endif;
                                  endforeach;
                                  ?>
                              </div>
                          <?php
                              $currentDate->modify('+1 day');
                          endfor;
                          ?>
                      </div>
                  <?php endfor; ?>
              </div>
          </div>

          <!-- Drag feedback toast -->
          <div id="dragFeedback" class="mw-drag-feedback" style="display: none;">
            <span id="dragMessage"></span>
          </div>

<script src="../js/schedule-drag-drop.js"></script>
<?php include dirname(__DIR__) . '/includes/appstack_footer.php'; ?>

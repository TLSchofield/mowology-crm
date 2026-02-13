<?php
/**
 * Schedule - Weekly Calendar View (Calendar Stops + Job Visits)
 *
 * Shows a weekly grid (Mon-Sun) with stop cards per property per day.
 * Each stop card contains visit pills for each service type.
 * Data comes from calendar_stops + job_visits via getCalendarStops().
 */
require_once dirname(__DIR__) . '/../loginAuth/auth.php';
require_once dirname(__DIR__) . '/includes/functions.php';
require_once dirname(__DIR__) . '/includes/plan-functions.php';
require_once dirname(__DIR__) . '/includes/weather-service.php';

requireLogin();
$user = getCurrentUser();
requirePermission('schedule.view');

$db = getDB();

// ─── Generate visits on-demand (6 weeks out) ────────────────────────
generateVisits(null, 42);

// ─── Date navigation ────────────────────────────────────────────────
$startDate = isset($_GET['start']) ? $_GET['start'] : date('Y-m-d', strtotime('monday this week'));
// Validate date format
if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $startDate)) {
    $startDate = date('Y-m-d', strtotime('monday this week'));
}
$endDate = date('Y-m-d', strtotime($startDate . ' +6 days'));

$prevWeek = date('Y-m-d', strtotime($startDate . ' -7 days'));
$nextWeek = date('Y-m-d', strtotime($startDate . ' +7 days'));

// ─── Staff / Crew filter ────────────────────────────────────────────
$crewFilter = isset($_GET['crew']) && $_GET['crew'] !== '' ? (int)$_GET['crew'] : null;
$staff = getStaffMembers();

// ─── Weather forecast ───────────────────────────────────────────────
$todaysForecast = getWeekForecast('Vancouver', 'BC');

$weekWeather = [];
$currentDate = new DateTime($startDate);
$todayIndex = 0;
for ($i = 0; $i < 7; $i++) {
    $dateStr = $currentDate->format('Y-m-d');
    $forecastDate = date('Y-m-d', strtotime("+{$todayIndex} days"));
    $weekWeather[$dateStr] = $todaysForecast[$forecastDate] ?? [
        'temp_high' => 12,
        'temp_low' => 8,
        'condition' => 'Unknown',
        'precipitation' => 0,
        'icon' => '&#9925;',
        'wind' => 0,
    ];
    $currentDate->modify('+1 day');
    $todayIndex++;
}

// ─── Calendar stop data ─────────────────────────────────────────────
$calendarData = getCalendarStops($startDate, $endDate, $crewFilter);

// ─── Service type config ────────────────────────────────────────────
$serviceColors = [
    'landscaping'          => '#2D8659',
    'lawn_care'            => '#7FD858',
    'snow_removal'         => '#3B82F6',
    'hedge_trimming'       => '#8B5CF6',
    'garden_maintenance'   => '#F59E0B',
    'seasonal_cleanup'     => '#EC4899',
];

$serviceLabels = [
    'landscaping'          => 'Landscape',
    'lawn_care'            => 'Lawn',
    'snow_removal'         => 'Snow',
    'hedge_trimming'       => 'Hedge',
    'garden_maintenance'   => 'Garden',
    'seasonal_cleanup'     => 'Cleanup',
];

function getServiceColorLocal(string $type): string {
    global $serviceColors;
    return $serviceColors[$type] ?? '#6B7280';
}

function getServiceLabelLocal(string $type): string {
    global $serviceLabels;
    return $serviceLabels[$type] ?? ucfirst(str_replace('_', ' ', $type));
}

// ─── Stop status CSS class ──────────────────────────────────────────
function stopStatusClass(string $status): string {
    switch ($status) {
        case 'in_progress': return 'mw-stop-status-progress';
        case 'completed':   return 'mw-stop-status-done';
        case 'skipped':     return 'mw-stop-status-skipped';
        default:            return '';
    }
}

// ─── Build crew filter query string ─────────────────────────────────
function buildCrewQuery(?int $crewId): string {
    return $crewId !== null ? '&crew=' . $crewId : '';
}

$crewQueryStr = buildCrewQuery($crewFilter);

// ─── Day names ──────────────────────────────────────────────────────
$dayNames = ['Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday', 'Saturday', 'Sunday'];

$pageTitle = 'Schedule';
$activePage = 'schedule';
?>
<?php include dirname(__DIR__) . '/includes/appstack_head.php'; ?>

          <div class="mw-page-header">
              <div>
                  <h1 class="h3">Schedule</h1>
              </div>

              <div class="mw-header-nav">
                  <a href="?start=<?php echo htmlspecialchars($prevWeek) . $crewQueryStr; ?>" class="mw-nav-btn">&larr;</a>
                  <div class="mw-date-display">
                      <?php echo date('M j', strtotime($startDate)); ?> &ndash; <?php echo date('M j, Y', strtotime($endDate)); ?>
                  </div>
                  <a href="?start=<?php echo htmlspecialchars($nextWeek) . $crewQueryStr; ?>" class="mw-nav-btn">&rarr;</a>
                  <a href="?<?php echo ltrim($crewQueryStr, '&'); ?>" class="mw-today-btn">Today</a>
              </div>

              <div class="d-flex align-items-center" style="gap: 8px;">
                  <!-- Crew filter -->
                  <select id="crewFilter" class="form-control form-control-sm" onchange="applyCrewFilter(this.value)">
                      <option value="">All Crews</option>
                      <?php foreach ($staff as $member): ?>
                          <option value="<?php echo (int)$member['id']; ?>"
                              <?php echo ($crewFilter === (int)$member['id']) ? 'selected' : ''; ?>>
                              <?php echo htmlspecialchars($member['full_name']); ?>
                          </option>
                      <?php endforeach; ?>
                  </select>
                  <a href="index.php" class="btn btn-secondary btn-sm">List View</a>
              </div>
          </div>

          <!-- Calendar container -->
          <div class="mw-calendar-container">

              <!-- Day name header row -->
              <div class="mw-calendar-header">
                  <div class="mw-calendar-time-label"></div>
                  <?php foreach ($dayNames as $name): ?>
                      <div class="mw-calendar-header-cell"><?php echo $name; ?></div>
                  <?php endforeach; ?>
              </div>

              <!-- Date + weather row -->
              <div class="mw-calendar-dates-header">
                  <div class="mw-calendar-time-label"></div>
                  <?php
                  $currentDate = new DateTime($startDate);
                  for ($i = 0; $i < 7; $i++):
                      $dateStr = $currentDate->format('Y-m-d');
                      $isToday = ($dateStr === date('Y-m-d'));
                      $weather = $weekWeather[$dateStr] ?? [];
                      $icon = getWeatherIcon($weather['condition'] ?? 'Clear');
                      $high = (int)($weather['temp_high'] ?? 12);
                      $low = (int)($weather['temp_low'] ?? 8);
                      $condition = strtolower($weather['condition'] ?? '');

                      $saltNeeded = $low <= 0 || strpos($condition, 'snow') !== false || strpos($condition, 'ice') !== false;
                  ?>
                      <div class="mw-calendar-date-cell <?php echo $isToday ? 'today' : ''; ?><?php echo $saltNeeded ? ' salt-needed' : ''; ?>"
                           data-date="<?php echo $dateStr; ?>">
                          <div class="mw-date-number"><?php echo $currentDate->format('j'); ?></div>
                          <div class="mw-weather-display">
                              <div class="mw-weather-icon" title="<?php echo htmlspecialchars($weather['condition'] ?? 'Clear'); ?>">
                                  <?php echo $icon; ?>
                              </div>
                              <div class="mw-weather-condition"><?php echo htmlspecialchars($weather['condition'] ?? 'Clear'); ?></div>
                          </div>
                          <div class="mw-temp-range"><?php echo $high; ?>&deg;/<?php echo $low; ?>&deg;</div>
                          <?php if ($saltNeeded): ?>
                              <div class="mw-salt-warning" title="Salting may be required">&#129474;</div>
                          <?php endif; ?>
                      </div>
                  <?php
                      $currentDate->modify('+1 day');
                  endfor;
                  ?>
              </div>

              <!-- Day columns with stop cards -->
              <div class="mw-stop-grid">
                  <div class="mw-stop-grid-label"></div>
                  <?php
                  $currentDate = new DateTime($startDate);
                  for ($dayIdx = 0; $dayIdx < 7; $dayIdx++):
                      $dateStr = $currentDate->format('Y-m-d');
                      $isToday = ($dateStr === date('Y-m-d'));
                      $dayStops = $calendarData[$dateStr] ?? [];

                      // Sort stops by route_order
                      uasort($dayStops, function ($a, $b) {
                          return ($a['route_order'] ?? 999) - ($b['route_order'] ?? 999);
                      });
                  ?>
                      <div class="mw-day-column <?php echo $isToday ? 'today' : ''; ?>"
                           data-date="<?php echo $dateStr; ?>">

                          <?php if (empty($dayStops)): ?>
                              <div class="mw-day-empty">No stops</div>
                          <?php else: ?>
                              <?php foreach ($dayStops as $stop): ?>
                                  <div class="mw-stop-card <?php echo stopStatusClass($stop['stop_status']); ?>"
                                       draggable="true"
                                       data-stop-id="<?php echo (int)$stop['stop_id']; ?>"
                                       data-stop-date="<?php echo htmlspecialchars($stop['stop_date']); ?>"
                                       data-route-order="<?php echo (int)$stop['route_order']; ?>">

                                      <?php
                                      // Determine arrival time display
                                      $arrivalDisplay = '';
                                      if (!empty($stop['estimated_arrival'])) {
                                          $arrivalDisplay = date('g:i A', strtotime($stop['estimated_arrival']));
                                      } elseif (!empty($stop['visits']) && !empty($stop['visits'][0]['scheduled_time_start'])) {
                                          $arrivalDisplay = date('g:i A', strtotime($stop['visits'][0]['scheduled_time_start']));
                                      }
                                      ?>

                                      <?php if ($arrivalDisplay): ?>
                                          <div class="mw-stop-time"><?php echo htmlspecialchars($arrivalDisplay); ?></div>
                                      <?php endif; ?>

                                      <div class="mw-stop-property"><?php echo htmlspecialchars($stop['property_address'] ?? 'Unknown'); ?></div>

                                      <?php if (!empty($stop['company_name'])): ?>
                                          <div class="mw-stop-client"><?php echo htmlspecialchars($stop['company_name']); ?></div>
                                      <?php endif; ?>

                                      <?php if (!empty($stop['visits'])): ?>
                                          <div class="mw-stop-visits">
                                              <?php foreach ($stop['visits'] as $visit): ?>
                                                  <span class="mw-visit-pill"
                                                        style="border-left-color: <?php echo getServiceColorLocal($visit['service_type'] ?? ''); ?>"
                                                        title="<?php echo htmlspecialchars($visit['plan_title'] ?? $visit['visit_number'] ?? ''); ?>">
                                                      <?php echo htmlspecialchars(getServiceLabelLocal($visit['service_type'] ?? '')); ?>
                                                  </span>
                                              <?php endforeach; ?>
                                          </div>
                                      <?php endif; ?>

                                      <?php if (!empty($stop['crew_name'])): ?>
                                          <div class="mw-stop-crew"><?php echo htmlspecialchars($stop['crew_name']); ?></div>
                                      <?php endif; ?>
                                  </div>
                              <?php endforeach; ?>
                          <?php endif; ?>
                      </div>
                  <?php
                      $currentDate->modify('+1 day');
                  endfor;
                  ?>
              </div>
          </div>

          <!-- Legend -->
          <div class="mw-legend mt-3">
              <?php foreach ($serviceColors as $service => $color): ?>
                  <div class="mw-legend-item">
                      <div class="mw-legend-color" style="background: <?php echo $color; ?>"></div>
                      <span><?php echo htmlspecialchars(getServiceLabelLocal($service)); ?></span>
                  </div>
              <?php endforeach; ?>
          </div>

          <!-- Drag feedback toast -->
          <div id="dragFeedback" class="mw-drag-feedback" style="display: none;">
              <span id="dragMessage"></span>
          </div>

<script>
/**
 * Crew filter: navigate with crew param
 */
function applyCrewFilter(crewId) {
    var params = new URLSearchParams(window.location.search);
    if (crewId) {
        params.set('crew', crewId);
    } else {
        params.delete('crew');
    }
    window.location.search = params.toString();
}
</script>
<script src="../js/schedule-drag-drop.js"></script>
<?php include dirname(__DIR__) . '/includes/appstack_footer.php'; ?>

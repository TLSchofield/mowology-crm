<?php
/**
 * Schedule - Weekly Calendar View (Calendar Stops + Job Visits)
 *
 * Desktop: Shows a weekly grid (Mon-Sun) with stop cards per property per day.
 * Mobile (<992px): Shows a card-based execution interface for today's stops.
 *
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

// ─── Mobile card view: today's stops with contact info ──────────────
$today = date('Y-m-d');
$todayDayName = date('l');  // e.g. "Thursday"
$todayDateDisplay = date('F j, Y'); // e.g. "February 13, 2026"

// Get today's weather
$todayWeather = $weekWeather[$today] ?? $todaysForecast[$today] ?? [
    'temp_high' => 12, 'temp_low' => 8, 'condition' => 'Clear'
];

// Build today's stops with contact names for mobile view
$mobileStops = [];
$todayStops = $calendarData[$today] ?? [];
if (!empty($todayStops)) {
    // Fetch contact names for all properties in today's stops
    $propertyIds = array_column($todayStops, 'property_id');
    $contactMap = [];
    if (!empty($propertyIds)) {
        $placeholders = implode(',', array_fill(0, count($propertyIds), '?'));
        $cStmt = $db->prepare("
            SELECT p.id AS property_id,
                   CONCAT(ct.first_name, ' ', ct.last_name) AS contact_name
            FROM properties p
            JOIN contacts ct ON p.site_contact_id = ct.id
            WHERE p.id IN ({$placeholders})
        ");
        $cStmt->execute($propertyIds);
        while ($row = $cStmt->fetch(PDO::FETCH_ASSOC)) {
            $contactMap[(int)$row['property_id']] = $row['contact_name'];
        }
    }

    // Sort by time, then route_order
    uasort($todayStops, function ($a, $b) {
        $aTime = $a['estimated_arrival'] ?? ($a['visits'][0]['scheduled_time_start'] ?? '23:59:59');
        $bTime = $b['estimated_arrival'] ?? ($b['visits'][0]['scheduled_time_start'] ?? '23:59:59');
        $timeCmp = strcmp($aTime, $bTime);
        if ($timeCmp !== 0) return $timeCmp;
        return ($a['route_order'] ?? 999) - ($b['route_order'] ?? 999);
    });

    foreach ($todayStops as $stop) {
        $stop['contact_name'] = $contactMap[(int)$stop['property_id']] ?? '';
        $stop['client_tier'] = null; // Future: pull from DB
        $mobileStops[] = $stop;
    }
}

// Determine which is the active stop (first non-completed stop, or first one)
$activeIndex = 0;
foreach ($mobileStops as $idx => $stop) {
    $status = $stop['stop_status'] ?? 'scheduled';
    if ($status !== 'completed' && $status !== 'skipped') {
        $activeIndex = $idx;
        break;
    }
}

// Get user permissions for button visibility
$userPermissions = function_exists('getUserPermissions')
    ? getUserPermissions((int)$user['id'])
    : [];

$totalStops = count($mobileStops);
$completedStops = 0;
foreach ($mobileStops as $s) {
    if (($s['stop_status'] ?? 'scheduled') === 'completed') {
        $completedStops++;
    }
}

$pageTitle = 'Schedule';
$activePage = 'schedule';
$extraHead = '<link href="/crm/css/mobile-cards.css?v=20260213b" rel="stylesheet">';
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

          <!-- ═══════════════════════════════════════════════
               DESKTOP: Calendar container (hidden on mobile)
               ═══════════════════════════════════════════════ -->
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

          <!-- ═══════════════════════════════════════════════
               MOBILE: Card Execution View (hidden on desktop)
               ═══════════════════════════════════════════════ -->
          <div class="mw-mc-container">

              <!-- Day header with weather -->
              <div class="mw-mc-day-header">
                  <div class="mw-mc-day-title"><?php echo htmlspecialchars($todayDayName); ?></div>
                  <div class="mw-mc-day-weather">
                      <span class="mw-mc-weather-icon"><?php echo getWeatherIcon($todayWeather['condition'] ?? 'Clear'); ?></span>
                      <span><?php echo htmlspecialchars($todayWeather['condition'] ?? 'Clear'); ?></span>
                      <span><?php echo (int)($todayWeather['temp_high'] ?? 12); ?>&deg;/<?php echo (int)($todayWeather['temp_low'] ?? 8); ?>&deg;</span>
                  </div>
                  <?php if ($totalStops > 0): ?>
                      <div class="mw-mc-stop-count">
                          <?php echo $completedStops; ?> of <?php echo $totalStops; ?> stops completed
                      </div>
                  <?php endif; ?>
              </div>

              <?php if (empty($mobileStops)): ?>
                  <!-- Empty state -->
                  <div class="mw-mc-empty">
                      <div class="mw-mc-empty-icon">&#127793;</div>
                      <div class="mw-mc-empty-text">No stops today</div>
                      <div class="mw-mc-empty-sub">Check the weekly view for upcoming work</div>
                  </div>
              <?php else: ?>

                  <?php
                  // Separate completed vs upcoming
                  $upcomingStops = [];
                  $completedStopsList = [];
                  foreach ($mobileStops as $idx => $stop) {
                      $status = $stop['stop_status'] ?? 'scheduled';
                      if ($status === 'completed' || $status === 'skipped') {
                          $completedStopsList[] = $stop;
                      } else {
                          $upcomingStops[] = ['stop' => $stop, 'originalIndex' => $idx];
                      }
                  }
                  ?>

                  <?php if (!empty($upcomingStops)): ?>
                      <!-- Active / current job -->
                      <?php
                      $activeStop = $upcomingStops[0]['stop'];
                      $isActive = true;
                      $permissions = $userPermissions;
                      $stop = $activeStop;
                      include dirname(__DIR__) . '/partials/job-card.php';
                      ?>

                      <!-- Upcoming jobs -->
                      <?php if (count($upcomingStops) > 1): ?>
                          <div class="mw-mc-section-label">Up Next</div>
                          <?php for ($i = 1; $i < count($upcomingStops); $i++):
                              $stop = $upcomingStops[$i]['stop'];
                              $isActive = false;
                              include dirname(__DIR__) . '/partials/job-card.php';
                          endfor; ?>
                      <?php endif; ?>
                  <?php endif; ?>

                  <?php if (!empty($completedStopsList)): ?>
                      <div class="mw-mc-section-label">Completed</div>
                      <?php foreach ($completedStopsList as $stop):
                          $isActive = false;
                          include dirname(__DIR__) . '/partials/job-card.php';
                      endforeach; ?>
                  <?php endif; ?>

              <?php endif; ?>
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

/**
 * Mobile card expand/collapse for compact cards
 */
(function() {
    document.querySelectorAll('.mw-mc-card-compact').forEach(function(card) {
        card.addEventListener('click', function(e) {
            // Don't toggle if clicking an action button or link
            if (e.target.closest('.mw-mc-action-btn') || e.target.closest('a')) return;

            var detail = card.querySelector('.mw-mc-expand-detail');
            if (!detail) return;

            var isExpanded = card.classList.contains('mw-mc-expanded');

            // Collapse all other expanded cards
            document.querySelectorAll('.mw-mc-card-compact.mw-mc-expanded').forEach(function(other) {
                if (other !== card) {
                    other.classList.remove('mw-mc-expanded');
                    var otherDetail = other.querySelector('.mw-mc-expand-detail');
                    if (otherDetail) otherDetail.style.display = 'none';
                }
            });

            // Toggle this card
            if (isExpanded) {
                card.classList.remove('mw-mc-expanded');
                detail.style.display = 'none';
            } else {
                card.classList.add('mw-mc-expanded');
                detail.style.display = 'block';
                // Scroll expanded card into comfortable view
                setTimeout(function() {
                    card.scrollIntoView({ behavior: 'smooth', block: 'center' });
                }, 50);
            }
        });
    });
})();

/**
 * Geolocation proximity — auto-expand & scroll to the nearest stop card
 *
 * On mobile, uses GPS to find which job site the user is physically at.
 * If within PROXIMITY_METERS, that card auto-expands and scrolls to center.
 * Runs once on page load; also adds a "Locate Me" floating button for re-check.
 */
(function() {
    // Only run on mobile-width screens where the card view is visible
    if (window.innerWidth > 991) return;
    if (!navigator.geolocation) return;

    var PROXIMITY_METERS = 150; // How close the user must be to auto-match

    /**
     * Haversine distance (meters) between two lat/lng points
     */
    function haversine(lat1, lng1, lat2, lng2) {
        var R = 6371000; // Earth radius in meters
        var toRad = Math.PI / 180;
        var dLat = (lat2 - lat1) * toRad;
        var dLng = (lng2 - lng1) * toRad;
        var a = Math.sin(dLat / 2) * Math.sin(dLat / 2) +
                Math.cos(lat1 * toRad) * Math.cos(lat2 * toRad) *
                Math.sin(dLng / 2) * Math.sin(dLng / 2);
        return R * 2 * Math.atan2(Math.sqrt(a), Math.sqrt(1 - a));
    }

    /**
     * Find nearest card and expand/scroll to it
     */
    function locateAndExpand(position) {
        var userLat = position.coords.latitude;
        var userLng = position.coords.longitude;

        var cards = document.querySelectorAll('.mw-mc-card[data-lat][data-lng]');
        var nearest = null;
        var nearestDist = Infinity;

        cards.forEach(function(card) {
            var lat = parseFloat(card.dataset.lat);
            var lng = parseFloat(card.dataset.lng);
            if (!lat || !lng || (lat === 0 && lng === 0)) return;

            var dist = haversine(userLat, userLng, lat, lng);
            if (dist < nearestDist) {
                nearestDist = dist;
                nearest = card;
            }
        });

        if (!nearest || nearestDist > PROXIMITY_METERS) return;

        // Remove previous proximity highlights
        document.querySelectorAll('.mw-mc-proximity-match').forEach(function(el) {
            el.classList.remove('mw-mc-proximity-match');
        });

        // If it's the already-expanded active card, just scroll to it
        if (nearest.classList.contains('mw-mc-card-active')) {
            nearest.classList.add('mw-mc-proximity-match');
            nearest.scrollIntoView({ behavior: 'smooth', block: 'center' });
            return;
        }

        // It's a compact card — expand it
        if (nearest.classList.contains('mw-mc-card-compact')) {
            // Collapse any other expanded cards first
            document.querySelectorAll('.mw-mc-card-compact.mw-mc-expanded').forEach(function(other) {
                other.classList.remove('mw-mc-expanded');
                var d = other.querySelector('.mw-mc-expand-detail');
                if (d) d.style.display = 'none';
            });

            // Expand this card
            nearest.classList.add('mw-mc-expanded');
            nearest.classList.add('mw-mc-proximity-match');
            var detail = nearest.querySelector('.mw-mc-expand-detail');
            if (detail) detail.style.display = 'block';

            // Scroll into center view after expansion renders
            setTimeout(function() {
                nearest.scrollIntoView({ behavior: 'smooth', block: 'center' });
            }, 100);
        }
    }

    // ── Auto-detect on page load ──
    navigator.geolocation.getCurrentPosition(
        locateAndExpand,
        function() { /* Permission denied or unavailable — silent fail */ },
        { enableHighAccuracy: true, timeout: 8000, maximumAge: 30000 }
    );

    // ── Floating "Locate Me" button for manual re-check ──
    var locBtn = document.createElement('button');
    locBtn.className = 'mw-mc-locate-btn';
    locBtn.innerHTML = '<svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="10"/><circle cx="12" cy="12" r="3"/><line x1="12" y1="2" x2="12" y2="6"/><line x1="12" y1="18" x2="12" y2="22"/><line x1="2" y1="12" x2="6" y2="12"/><line x1="18" y1="12" x2="22" y2="12"/></svg>';
    locBtn.title = 'Find my current stop';
    locBtn.addEventListener('click', function() {
        locBtn.style.opacity = '0.5';
        navigator.geolocation.getCurrentPosition(
            function(pos) {
                locBtn.style.opacity = '1';
                locateAndExpand(pos);
            },
            function() {
                locBtn.style.opacity = '1';
            },
            { enableHighAccuracy: true, timeout: 8000, maximumAge: 0 }
        );
    });

    var container = document.querySelector('.mw-mc-container');
    if (container) container.appendChild(locBtn);
})();
</script>
<script src="../js/schedule-drag-drop.js"></script>
<?php include dirname(__DIR__) . '/includes/appstack_footer.php'; ?>

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
require_once dirname(__DIR__) . '/includes/timeclock-functions.php';

requireLogin();
$user = getCurrentUser();
requirePermission('schedule.view');

$db = getDB();

// ─── CSRF token for JS API calls ─────────────────────────────────
$csrfToken = generateCSRFToken();

// ─── Active visit timer (for restoring in-progress state on load) ─
$activeTimer = getActiveVisitTimer($user['id']);
$activeTimerData = null;
if ($activeTimer) {
    $activeTimerData = [
        'visit_id'        => (int)$activeTimer['visit_id'],
        'start_time'      => $activeTimer['start_time'],
        'elapsed_seconds' => (int)($activeTimer['elapsed_seconds'] ?? 0),
    ];
}

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

// ─── Profitability batch data for all plans this week ───────────────
$allPlanIds = [];
foreach ($calendarData as $dateStops) {
    foreach ($dateStops as $stop) {
        foreach (($stop['visits'] ?? []) as $v) {
            if (!empty($v['plan_id'])) {
                $allPlanIds[] = (int)$v['plan_id'];
            }
        }
    }
}
$allPlanIds = array_values(array_unique($allPlanIds));
$profitabilityMap = !empty($allPlanIds) ? getStopProfitabilityBatch($allPlanIds) : [];

// ─── Holiday lookup for calendar display ────────────────────────────
$weekHolidays = [];
try {
    $weekHolidays = getActiveHolidays($startDate, $endDate);
} catch (Exception $e) {
    // Table may not exist yet — continue without holiday display
}

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
$dayNames = ['MON', 'TUE', 'WED', 'THU', 'FRI', 'SAT', 'SUN'];

// ─── Mobile card view: today's stops with contact info ──────────────
$today = date('Y-m-d');
$todayDayName = strtoupper(date('D'));  // e.g. "SAT"
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

// ─── Pre-load existing visit photo thumbnails ─────────────────────
$visitPhotoMap = []; // visitId => ['before' => thumbUrl, 'after' => thumbUrl]
$allVisitIds = [];
foreach ($mobileStops as $stop) {
    foreach (($stop['visits'] ?? []) as $v) {
        $allVisitIds[] = (int)$v['visit_id'];
    }
}
if (!empty($allVisitIds)) {
    $vPlaceholders = implode(',', array_fill(0, count($allVisitIds), '?'));
    $photoStmt = $db->prepare("
        SELECT ml.context_id AS visit_id, ml.category,
               mv.file_path AS thumb_url
        FROM media_links ml
        JOIN media_variants mv ON mv.media_id = ml.media_id
            AND mv.variant_type = 'thumb_square' AND mv.format = 'jpeg'
        WHERE ml.context_type = 'job_visit'
            AND ml.context_id IN ({$vPlaceholders})
            AND ml.category IN ('before', 'after')
        ORDER BY ml.context_id, ml.category, ml.created_at ASC
    ");
    $photoStmt->execute($allVisitIds);
    while ($pRow = $photoStmt->fetch(PDO::FETCH_ASSOC)) {
        $vid = (int)$pRow['visit_id'];
        $cat = $pRow['category']; // 'before' or 'after'
        if (!isset($visitPhotoMap[$vid])) {
            $visitPhotoMap[$vid] = [];
        }
        // Keep the first one per category (ASC order = oldest first)
        if (!isset($visitPhotoMap[$vid][$cat])) {
            $visitPhotoMap[$vid][$cat] = $pRow['thumb_url'];
        }
    }
}

// ─── Pre-load property tags for mobile cards ─────────────────────
$propertyTagMap = []; // property_id => [['tag_label'=>..., 'tag_value'=>..., 'tag_color'=>..., 'icon'=>...], ...]
$propIds = array_unique(array_filter(array_column($mobileStops, 'property_id')));
if (!empty($propIds)) {
    $tPlaceholders = implode(',', array_fill(0, count($propIds), '?'));
    $tagStmt = $db->prepare("
        SELECT et.entity_id AS property_id, t.tag_label, et.tag_value,
               t.tag_color, t.icon, t.has_value
        FROM entity_tags et
        JOIN tags t ON t.id = et.tag_id
        WHERE et.entity_type = 'property'
          AND et.entity_id IN ({$tPlaceholders})
          AND t.show_on_card = 1
          AND t.is_active = 1
        ORDER BY t.sort_order ASC, t.tag_label ASC
    ");
    $tagStmt->execute(array_values($propIds));
    while ($tRow = $tagStmt->fetch(PDO::FETCH_ASSOC)) {
        $pid = (int)$tRow['property_id'];
        if (!isset($propertyTagMap[$pid])) {
            $propertyTagMap[$pid] = [];
        }
        $propertyTagMap[$pid][] = [
            'label' => $tRow['tag_label'],
            'value' => $tRow['tag_value'],
            'color' => $tRow['tag_color'],
            'icon'  => $tRow['icon'],
            'has_value' => (int)$tRow['has_value'],
        ];
    }
}
// Merge tags into stops
foreach ($mobileStops as &$mStop) {
    $mStop['tags'] = $propertyTagMap[(int)$mStop['property_id']] ?? [];
}
unset($mStop);

// Merge profitability into mobile stops
foreach ($mobileStops as &$mStop) {
    $margins = [];
    foreach (($mStop['visits'] ?? []) as $v) {
        $pid = (int)($v['plan_id'] ?? 0);
        if ($pid && isset($profitabilityMap[$pid]) && $profitabilityMap[$pid]['has_data']) {
            $margins[] = $profitabilityMap[$pid]['margin_pct'];
        }
    }
    $mStop['profit_margin'] = !empty($margins) ? (int)round(array_sum($margins) / count($margins)) : null;
}
unset($mStop);

// ─── Resolve auto_clock_in per visit (plan override → product default) ──
$planAutoClockInCache = []; // planId => bool
foreach ($mobileStops as &$mStop) {
    foreach (($mStop['visits'] ?? []) as &$v) {
        $planId = (int)($v['plan_id'] ?? 0);
        if ($planId && !isset($planAutoClockInCache[$planId])) {
            $trackingReqs = resolveTrackingRequirementsForPlan($planId);
            $planAutoClockInCache[$planId] = !empty($trackingReqs['auto_clock_in']);
        }
        $v['auto_clock_in'] = $planId ? ($planAutoClockInCache[$planId] ?? false) : false;
    }
    unset($v);
}
unset($mStop);

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
$apiKey = defined('GOOGLE_MAPS_API_KEY') ? GOOGLE_MAPS_API_KEY : '';
$extraHead = '<link href="/crm/css/mobile-cards.css?v=20260216b" rel="stylesheet">';
if ($apiKey) {
    $extraHead .= '<script src="https://maps.googleapis.com/maps/api/js?key='
        . htmlspecialchars($apiKey, ENT_QUOTES, 'UTF-8')
        . '&libraries=geometry" defer></script>';
}
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
                      $holidayName = $weekHolidays[$dateStr] ?? null;
                  ?>
                      <div class="mw-calendar-date-cell <?php echo $isToday ? 'today' : ''; ?><?php echo $saltNeeded ? ' salt-needed' : ''; ?><?php echo $holidayName ? ' holiday' : ''; ?>"
                           data-date="<?php echo $dateStr; ?>">
                          <div class="mw-date-number"><?php echo $currentDate->format('j'); ?></div>
                          <?php if ($holidayName): ?>
                              <div class="mw-holiday-badge" title="<?php echo htmlspecialchars($holidayName); ?>">
                                  <?php echo htmlspecialchars($holidayName); ?>
                              </div>
                          <?php endif; ?>
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
                                       data-route-order="<?php echo (int)$stop['route_order']; ?>"
                                       data-crew-id="<?php echo (int)($stop['crew_id'] ?? 0); ?>"
                                       data-property-address="<?php echo htmlspecialchars($stop['property_address'] ?? 'Unknown'); ?>">

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

                                      <?php
                                      // Profitability bar
                                      $stopMargin = null;
                                      $stopHasProfit = false;
                                      if (!empty($stop['visits'])) {
                                          $margins = [];
                                          foreach ($stop['visits'] as $sv) {
                                              $pid = (int)($sv['plan_id'] ?? 0);
                                              if ($pid && isset($profitabilityMap[$pid]) && $profitabilityMap[$pid]['has_data']) {
                                                  $margins[] = $profitabilityMap[$pid]['margin_pct'];
                                                  $stopHasProfit = true;
                                              }
                                          }
                                          if (!empty($margins)) {
                                              $stopMargin = (int)round(array_sum($margins) / count($margins));
                                          }
                                      }
                                      ?>
                                      <?php if ($stopHasProfit && $stopMargin !== null): ?>
                                          <div class="mw-profit-bar" title="Est. margin: <?php echo $stopMargin; ?>%">
                                              <div class="mw-profit-bar-fill" style="width: <?php echo max(0, min(100, $stopMargin)); ?>%" data-margin="<?php echo $stopMargin; ?>"></div>
                                          </div>
                                      <?php elseif (!empty($stop['visits'])): ?>
                                          <div class="mw-profit-bar mw-profit-bar-empty" title="No profitability data yet"></div>
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

          <!-- ═══ Crew Assignment Modal ═══ -->
          <div class="modal fade" id="crewAssignModal" tabindex="-1" role="dialog" aria-labelledby="crewAssignModalLabel" aria-hidden="true">
              <div class="modal-dialog modal-dialog-centered" role="document">
                  <div class="modal-content">
                      <div class="modal-header">
                          <h5 class="modal-title" id="crewAssignModalLabel">Assign Crew</h5>
                          <button type="button" class="close" data-dismiss="modal" aria-label="Close">
                              <span aria-hidden="true">&times;</span>
                          </button>
                      </div>
                      <div class="modal-body">
                          <input type="hidden" id="crewAssignStopId">
                          <div class="mb-3">
                              <div class="mw-crew-modal-property" id="crewAssignProperty"></div>
                              <div class="mw-crew-modal-date" id="crewAssignDate"></div>
                          </div>
                          <div class="form-group mb-0">
                              <label for="crewAssignSelect">Assigned Crew</label>
                              <select class="form-control" id="crewAssignSelect">
                                  <option value="">Unassigned</option>
                                  <?php foreach ($staff as $member): ?>
                                      <option value="<?php echo (int)$member['id']; ?>">
                                          <?php echo htmlspecialchars($member['full_name']); ?>
                                      </option>
                                  <?php endforeach; ?>
                              </select>
                          </div>
                      </div>
                      <div class="modal-footer">
                          <button type="button" class="btn btn-secondary" data-dismiss="modal">Cancel</button>
                          <button type="button" class="btn btn-primary" id="crewAssignSave">Save</button>
                      </div>
                  </div>
              </div>
          </div>

          <!-- Drag feedback toast -->
          <div id="dragFeedback" class="mw-drag-feedback" style="display: none;">
              <span id="dragMessage"></span>
          </div>

          <!-- ═══════════════════════════════════════════════
               MOBILE: Card Execution View (hidden on desktop)
               ═══════════════════════════════════════════════ -->
          <div class="mw-mc-container" data-csrf="<?php echo htmlspecialchars($csrfToken); ?>">

              <!-- ── Fixed Top Bar ── -->
              <div class="mw-mc-topbar">
                  <div class="mw-mc-topbar-left">
                      <div class="mw-mc-topbar-day"><?php echo htmlspecialchars($todayDayName); ?></div>
                      <div class="mw-mc-topbar-date"><?php echo date('M j'); ?></div>
                  </div>
                  <div class="mw-mc-topbar-center">
                      <?php if ($totalStops > 0): ?>
                          <div class="mw-mc-topbar-progress">
                              <div class="mw-mc-topbar-progress-bar">
                                  <div class="mw-mc-topbar-progress-fill" style="width: <?php echo $totalStops > 0 ? round(($completedStops / $totalStops) * 100) : 0; ?>%"></div>
                              </div>
                              <span class="mw-mc-topbar-progress-text"><?php echo $completedStops; ?>/<?php echo $totalStops; ?></span>
                          </div>
                      <?php endif; ?>
                  </div>
                  <div class="mw-mc-topbar-right">
                      <button class="mw-mc-topbar-locate" id="mobileTrackingDot" title="Checking GPS...">
                          <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="10"/><circle cx="12" cy="12" r="3"/><line x1="12" y1="2" x2="12" y2="6"/><line x1="12" y1="18" x2="12" y2="22"/><line x1="2" y1="12" x2="6" y2="12"/><line x1="18" y1="12" x2="22" y2="12"/></svg>
                      </button>
                      <span class="mw-mc-topbar-weather">
                          <?php echo getWeatherIcon($todayWeather['condition'] ?? 'Clear'); ?>
                          <?php echo (int)($todayWeather['temp_high'] ?? 12); ?>&deg;
                      </span>
                  </div>
              </div>

              <!-- ── Scrollable Card Area ── -->
              <div class="mw-mc-scroll-area">

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
                      <!-- All upcoming stops rendered as compact cards; JS promotes the GPS-matched one -->
                      <?php foreach ($upcomingStops as $upIdx => $upEntry):
                          $stop = $upEntry['stop'];
                          $isActive = false;
                          $permissions = $userPermissions;
                          include dirname(__DIR__) . '/partials/job-card.php';
                      endforeach; ?>
                  <?php endif; ?>

                  <?php if (!empty($completedStopsList)): ?>
                      <div class="mw-mc-section-label">Completed</div>
                      <?php foreach ($completedStopsList as $stop):
                          $isActive = false;
                          include dirname(__DIR__) . '/partials/job-card.php';
                      endforeach; ?>
                  <?php endif; ?>

              <?php endif; ?>

              </div><!-- /.mw-mc-scroll-area -->

              <!-- ── Fixed Bottom Bar ── -->
              <div class="mw-mc-bottombar">
                  <a href="?<?php echo ltrim($crewQueryStr, '&'); ?>" class="mw-mc-bottombar-btn mw-mc-bottombar-btn-active">
                      <svg width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="3" y="4" width="18" height="18" rx="2" ry="2"/><line x1="16" y1="2" x2="16" y2="6"/><line x1="8" y1="2" x2="8" y2="6"/><line x1="3" y1="10" x2="21" y2="10"/></svg>
                      <span>Today</span>
                  </a>
                  <button type="button" class="mw-mc-bottombar-btn" id="mwRouteBtn" onclick="MwRouteMap.toggle()">
                      <svg width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polygon points="3 11 22 2 13 21 11 13 3 11"/></svg>
                      <span>Route</span>
                  </button>
                  <a href="index.php" class="mw-mc-bottombar-btn">
                      <svg width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><line x1="8" y1="6" x2="21" y2="6"/><line x1="8" y1="12" x2="21" y2="12"/><line x1="8" y1="18" x2="21" y2="18"/><line x1="3" y1="6" x2="3.01" y2="6"/><line x1="3" y1="12" x2="3.01" y2="12"/><line x1="3" y1="18" x2="3.01" y2="18"/></svg>
                      <span>List</span>
                  </a>
              </div>

          </div><!-- /.mw-mc-container -->

          <!-- ═══════════════════════════════════════════════
               MOBILE: Map View (full-screen overlay, hidden by default)
               ═══════════════════════════════════════════════ -->
          <div class="mw-mv" id="mwMapView">

              <!-- Map top bar -->
              <div class="mw-mv-topbar">
                  <button type="button" class="mw-mv-back" id="mwMapViewBack" aria-label="Back to schedule">
                      <svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><polyline points="15 18 9 12 15 6"/></svg>
                  </button>
                  <div class="mw-mv-topbar-title" id="mwMapViewTitle">Route</div>
                  <button type="button" class="mw-mv-external" id="mwMapViewExternal" title="Open in Google Maps" aria-label="Open in Google Maps">
                      <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M18 13v6a2 2 0 01-2 2H5a2 2 0 01-2-2V8a2 2 0 012-2h6"/><polyline points="15 3 21 3 21 9"/><line x1="10" y1="14" x2="21" y2="3"/></svg>
                  </button>
              </div>

              <!-- Google Map -->
              <div class="mw-mv-map" id="mwMapViewMap"></div>

              <!-- Swipeable card carousel at bottom -->
              <div class="mw-mv-card-tray" id="mwMapViewTray">
                  <div class="mw-mv-card-track" id="mwMapViewTrack">
                      <!-- Cards are cloned here by JS -->
                  </div>
                  <div class="mw-mv-card-dots" id="mwMapViewDots"></div>
              </div>

          </div><!-- /.mw-mv -->

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
 * Crew assignment modal — open on stop card click (desktop only)
 * Uses mousedown/mouseup distance to distinguish clicks from drags.
 */
(function() {
    // Only attach on desktop where the calendar grid is visible
    if (window.innerWidth <= 991) return;

    var downX = 0, downY = 0;

    document.querySelectorAll('.mw-stop-card').forEach(function(card) {
        card.addEventListener('mousedown', function(e) {
            downX = e.clientX;
            downY = e.clientY;
        });

        card.addEventListener('mouseup', function(e) {
            // If mouse moved more than 5px, it was a drag — don't open modal
            var dx = Math.abs(e.clientX - downX);
            var dy = Math.abs(e.clientY - downY);
            if (dx > 5 || dy > 5) return;

            var stopId = card.dataset.stopId;
            var crewId = card.dataset.crewId || '';
            var address = card.dataset.propertyAddress || 'Unknown';
            var stopDate = card.dataset.stopDate || '';

            // Format date for display
            var dateDisplay = stopDate;
            if (stopDate) {
                var d = new Date(stopDate + 'T12:00:00');
                dateDisplay = d.toLocaleDateString('en-US', {
                    weekday: 'long', month: 'short', day: 'numeric'
                });
            }

            document.getElementById('crewAssignStopId').value = stopId;
            document.getElementById('crewAssignProperty').textContent = address;
            document.getElementById('crewAssignDate').textContent = dateDisplay;
            document.getElementById('crewAssignSelect').value = (crewId && crewId !== '0') ? crewId : '';

            $('#crewAssignModal').modal('show');
        });
    });

    // Save button handler
    document.getElementById('crewAssignSave').addEventListener('click', function() {
        var btn = this;
        var stopId = document.getElementById('crewAssignStopId').value;
        var crewId = document.getElementById('crewAssignSelect').value;

        btn.disabled = true;
        btn.textContent = 'Saving...';

        fetch('/crm/api/assign-crew.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({
                stop_id: parseInt(stopId, 10),
                crew_id: crewId ? parseInt(crewId, 10) : null
            })
        })
        .then(function(resp) {
            if (!resp.ok) {
                return resp.json().then(function(d) {
                    throw new Error(d.error || 'Server error');
                });
            }
            return resp.json();
        })
        .then(function(data) {
            $('#crewAssignModal').modal('hide');
            // Update the card's crew display without full reload
            var card = document.querySelector('.mw-stop-card[data-stop-id="' + stopId + '"]');
            if (card) {
                card.dataset.crewId = crewId || '0';
                var crewEl = card.querySelector('.mw-stop-crew');
                if (data.crew_name && data.crew_name !== 'Unassigned') {
                    if (crewEl) {
                        crewEl.textContent = data.crew_name;
                    } else {
                        var newCrew = document.createElement('div');
                        newCrew.className = 'mw-stop-crew';
                        newCrew.textContent = data.crew_name;
                        card.appendChild(newCrew);
                    }
                } else {
                    if (crewEl) crewEl.remove();
                }
            }
            btn.disabled = false;
            btn.textContent = 'Save';
        })
        .catch(function(err) {
            alert('Error: ' + err.message);
            btn.disabled = false;
            btn.textContent = 'Save';
        });
    });
})();

/**
 * Mobile card expand/collapse for compact cards
 */
(function() {
    document.querySelectorAll('.mw-mc-card-compact').forEach(function(card) {
        card.addEventListener('click', function(e) {
            // Don't toggle if clicking an action button, link, pill, drawer, or photo strip
            if (e.target.closest('.mw-mc-action-btn') || e.target.closest('a') ||
                e.target.closest('.mw-mc-pill-interactive') || e.target.closest('.mw-mc-pill-drawer') ||
                e.target.closest('.mw-mc-drawer-btn') || e.target.closest('.mw-mc-drawer-camera-btn') ||
                e.target.closest('.mw-mc-drawer-skip') || e.target.closest('.mw-mc-photo-strips')) return;

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
                // Scroll expanded card into comfortable view within scroll area
                setTimeout(function() {
                    card.scrollIntoView({ behavior: 'smooth', block: 'nearest' });
                }, 50);
            }
        });
    });
})();

/**
 * Geolocation proximity — promote nearest stop to hero card
 *
 * On mobile, uses GPS to find which job site the user is physically at.
 * If within PROXIMITY_METERS, that card becomes a "hero" card (large,
 * with full details visible) and an "Up Next" divider is inserted
 * before the remaining compact cards.
 * Runs once on page load; also hooks the bottom-bar "Locate" button.
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
        var R = 6371000;
        var toRad = Math.PI / 180;
        var dLat = (lat2 - lat1) * toRad;
        var dLng = (lng2 - lng1) * toRad;
        var a = Math.sin(dLat / 2) * Math.sin(dLat / 2) +
                Math.cos(lat1 * toRad) * Math.cos(lat2 * toRad) *
                Math.sin(dLng / 2) * Math.sin(dLng / 2);
        return R * 2 * Math.atan2(Math.sqrt(a), Math.sqrt(1 - a));
    }

    /**
     * Promote the nearest card to hero status
     */
    function promoteToHero(position) {
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

        // Remove previous hero promotion
        document.querySelectorAll('.mw-mc-card-hero').forEach(function(el) {
            el.classList.remove('mw-mc-card-hero');
        });
        document.querySelectorAll('.mw-mc-hero-divider').forEach(function(el) {
            el.remove();
        });
        document.querySelectorAll('.mw-mc-proximity-match').forEach(function(el) {
            el.classList.remove('mw-mc-proximity-match');
        });

        // Collapse any expanded cards
        document.querySelectorAll('.mw-mc-card-compact.mw-mc-expanded').forEach(function(other) {
            other.classList.remove('mw-mc-expanded');
            var d = other.querySelector('.mw-mc-expand-detail');
            if (d) d.style.display = 'none';
        });

        // Promote this card to hero
        nearest.classList.add('mw-mc-card-hero');
        nearest.classList.add('mw-mc-proximity-match');

        // Move hero card to top of the scroll area (before other cards)
        var scrollArea = document.querySelector('.mw-mc-scroll-area');
        if (scrollArea && scrollArea.firstElementChild !== nearest) {
            scrollArea.insertBefore(nearest, scrollArea.firstElementChild);
        }

        // Insert "Up Next" divider after the hero card if there are more cards
        var nextSibling = nearest.nextElementSibling;
        if (nextSibling && !nextSibling.classList.contains('mw-mc-hero-divider') &&
            !nextSibling.classList.contains('mw-mc-section-label')) {
            var divider = document.createElement('div');
            divider.className = 'mw-mc-hero-divider';
            divider.textContent = 'Up Next';
            nearest.parentNode.insertBefore(divider, nextSibling);
        }

        // Scroll to top
        if (scrollArea) scrollArea.scrollTop = 0;

        // Notify pill workflow for auto-clock-in on single-visit cards
        document.dispatchEvent(new CustomEvent('mw-hero-promoted', {
            detail: { card: nearest, distance: nearestDist }
        }));
    }

    // ── Auto-detect on page load ──
    navigator.geolocation.getCurrentPosition(
        promoteToHero,
        function() { /* Permission denied or unavailable — silent fail */ },
        { enableHighAccuracy: true, timeout: 8000, maximumAge: 30000 }
    );

    // ── Hook up the bottom bar "Locate" button for manual re-check ──
    var locBtn = document.getElementById('mobileTrackingDot');
    if (locBtn) {
        locBtn.addEventListener('click', function() {
            locBtn.style.opacity = '0.5';
            navigator.geolocation.getCurrentPosition(
                function(pos) {
                    locBtn.style.opacity = '1';
                    promoteToHero(pos);
                },
                function() {
                    locBtn.style.opacity = '1';
                },
                { enableHighAccuracy: true, timeout: 8000, maximumAge: 0 }
            );
        });
    }
})();
</script>
<script>
/**
 * Mobile schedule state — embedded by PHP for JS state machine.
 * Used by schedule-pill-workflow.js to restore in-progress timers.
 */
var MW_SCHEDULE_STATE = {
    csrf: <?php echo json_encode($csrfToken); ?>,
    userId: <?php echo (int)$user['id']; ?>,
    activeTimer: <?php echo json_encode($activeTimerData); ?>,
    visitPhotos: <?php echo json_encode($visitPhotoMap, JSON_FORCE_OBJECT); ?>,
    autoArrivalEnabled: <?php echo json_encode((bool)(int)getTimeClockSetting('auto_arrival_enabled', '1')); ?>,
    autoArrivalServiceTypes: <?php echo json_encode(array_filter(array_map('trim', explode(',', getTimeClockSetting('auto_arrival_service_types', ''))))); ?>
};

/**
 * Route stop data for Map View — non-completed stops with coordinates.
 */
var MW_ROUTE_STOPS = <?php
    $routeStopsJson = [];
    foreach ($mobileStops as $idx => $rs) {
        $status = $rs['stop_status'] ?? 'scheduled';
        if ($status === 'completed' || $status === 'skipped') continue;
        $routeStopsJson[] = [
            'stopId'     => (int)$rs['stop_id'],
            'lat'        => $rs['latitude'] ? (float)$rs['latitude'] : null,
            'lng'        => $rs['longitude'] ? (float)$rs['longitude'] : null,
            'address'    => trim(($rs['property_address'] ?? '') . ', ' . ($rs['property_city'] ?? 'Vancouver') . ', BC, Canada'),
            'routeOrder' => (int)($rs['route_order'] ?? 999),
            'contactName'=> $rs['contact_name'] ?? '',
            'serviceType'=> !empty($rs['visits']) ? ($rs['visits'][0]['service_type'] ?? '') : '',
            'planTitle'  => !empty($rs['visits']) ? ($rs['visits'][0]['plan_title'] ?? '') : '',
            'time'       => !empty($rs['estimated_arrival']) ? date('g:i A', strtotime($rs['estimated_arrival'])) : (!empty($rs['visits'][0]['scheduled_time_start']) ? date('g:i A', strtotime($rs['visits'][0]['scheduled_time_start'])) : ''),
            'duration'   => !empty($rs['visits']) ? (int)($rs['visits'][0]['estimated_duration'] ?? 0) : 0,
        ];
    }
    echo json_encode($routeStopsJson);
?>;
</script>
<script src="../js/schedule-route-map.js?v=20260216a"></script>
<script src="../js/schedule-pill-workflow.js?v=20260214h"></script>
<script src="../js/schedule-drag-drop.js"></script>
<?php include dirname(__DIR__) . '/includes/appstack_footer.php'; ?>

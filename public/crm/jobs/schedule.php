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
require_once dirname(__DIR__) . '/modules/weather/weather-rules.php';

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

// ─── Visit generation: handled by cron (generate_visits.php every 6h) ───
// generateVisits() was removed from page load in Phase 1 to prevent
// synchronous DB writes blocking rendering. The cron keeps the 42-day
// horizon fresh. If the horizon is stale (cron hasn't run), log a warning
// so the issue appears in error logs without degrading the user experience.
if (!isVisitHorizonCurrent()) {
    error_log('[schedule.php] Visit horizon stale — ensure generate_visits cron is scheduled: 0 */6 * * * php /home/mowology/app/Modules/Jobs/Cron/generate_visits.php');
}

// ─── View mode (week or day) ─────────────────────────────────────────
$view = (isset($_GET['view']) && $_GET['view'] === 'day') ? 'day' : 'week';

// ─── Date navigation ────────────────────────────────────────────────
if ($view === 'day') {
    $dayDate = isset($_GET['date']) ? $_GET['date'] : date('Y-m-d');
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $dayDate)) {
        $dayDate = date('Y-m-d');
    }
    $startDate = $dayDate;
    $endDate = $dayDate;
    $prevDay = date('Y-m-d', strtotime($dayDate . ' -1 day'));
    $nextDay = date('Y-m-d', strtotime($dayDate . ' +1 day'));
    $weekStart = date('Y-m-d', strtotime('monday this week', strtotime($dayDate)));
    // Still compute week nav for the toggle
    $prevWeek = date('Y-m-d', strtotime($weekStart . ' -7 days'));
    $nextWeek = date('Y-m-d', strtotime($weekStart . ' +7 days'));
} else {
    $startDate = isset($_GET['start']) ? $_GET['start'] : date('Y-m-d', strtotime('monday this week'));
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $startDate)) {
        $startDate = date('Y-m-d', strtotime('monday this week'));
    }
    $endDate = date('Y-m-d', strtotime($startDate . ' +6 days'));
    $prevWeek = date('Y-m-d', strtotime($startDate . ' -7 days'));
    $nextWeek = date('Y-m-d', strtotime($startDate . ' +7 days'));
    $dayDate = null;
    $weekStart = $startDate;
}

// ─── Staff / Crew filter ────────────────────────────────────────────
$crewFilter = isset($_GET['crew']) && $_GET['crew'] !== '' ? (int)$_GET['crew'] : null;
$staff = getStaffMembers();

// ─── Service type filter ─────────────────────────────────────────────
// Load from DB with fallback to hardcoded list
$validServiceTypes = ['landscaping', 'lawn_care', 'snow_removal', 'hedge_trimming', 'garden_maintenance', 'seasonal_cleanup'];
$serviceTypeLabels = []; // slug => label for the filter dropdown
try {
    $stRows = $db->query("SELECT slug, label FROM service_types WHERE is_active = 1 ORDER BY sort_order ASC, label ASC")->fetchAll(PDO::FETCH_ASSOC);
    if ($stRows) {
        $validServiceTypes = array_column($stRows, 'slug');
        foreach ($stRows as $stRow) { $serviceTypeLabels[$stRow['slug']] = $stRow['label']; }
    }
} catch (Exception $e) { /* use fallback */ }
$serviceFilter = (isset($_GET['service']) && in_array($_GET['service'], $validServiceTypes, true))
    ? $_GET['service']
    : null;

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
$calendarData = getCalendarStops($startDate, $endDate, $crewFilter, $serviceFilter);

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

// ─── Unscheduled jobs tray ───────────────────────────────────────────
// Visits with no calendar_stop yet — displayed in the collapsible left tray.
$unscheduledVisits = getUnscheduledVisits();
$unscheduledCount  = count($unscheduledVisits);

// Crew flag: 'user' role = mobile crew (auto-assign on drop); admin/manager = desktop
$isCrew = ($user['role'] === 'user');

// ─── Placement Intelligence: per-visit × per-day fit scores ──────────
// Scored 0–100 per day for each unscheduled visit. Used client-side to
// light up the calendar columns when a tray card is selected/hovered.
//
// Score components (max 100):
//   Capacity Fit   0–40  How much headroom remains after adding this visit
//   Cycle Match    0–40  For 14-day plans: is this the correct week in rotation?
//                        For weekly: does DOW match preferred day?
//   Weather        0–20  Low risk=20, medium=10, high=0
//   Density Bonus  0–10  Slight preference for days with existing stops (cluster)
//
// The 14-day cycle check: from last_completed_at, count how many days to each
// candidate date. If (days % 14) < 4 → on-cycle (score 40). If 4–7 → ok (20).
// If 7–14 → off-cycle (0). For weekly plans, match preferred recurrence_day_of_week.
$placementScores = []; // [visitId => [dateStr => ['score'=>int, 'cap'=>int, 'cycle'=>int, 'weather'=>int, 'density'=>int, 'label'=>string, 'cycle_note'=>string]]]

// Build a fast lookup: dateStr => capacity used minutes (from already-scheduled stops)
$dayCapUsed = []; // dateStr => total scheduled minutes
$currentDatePl = new DateTime($startDate);
for ($pi = 0; $pi < 7; $pi++) {
    $ds = $currentDatePl->format('Y-m-d');
    $used = 0;
    foreach (($calendarData[$ds] ?? []) as $stop) {
        foreach (($stop['visits'] ?? []) as $v) {
            $used += (int)($v['estimated_duration'] ?? 0);
        }
    }
    // Add drive time estimate to used capacity
    $nStops = count($calendarData[$ds] ?? []);
    $used += $nStops > 0 ? (max(0, $nStops - 1) * 8 + 10) : 0;
    $dayCapUsed[$ds] = $used;
    $currentDatePl->modify('+1 day');
}

foreach ($unscheduledVisits as $uv) {
    $visitId  = (int)$uv['visit_id'];
    $duration = (int)($uv['estimated_duration'] ?? 45);
    $pattern  = $uv['recurrence_pattern'] ?? 'weekly';
    $interval = max(1, (int)($uv['recurrence_interval'] ?? 1));
    $prefDow  = $uv['recurrence_day_of_week'] !== null ? (int)$uv['recurrence_day_of_week'] : null;
    $lastDone = !empty($uv['last_completed_at']) ? strtotime($uv['last_completed_at']) : null;
    $planStart= !empty($uv['plan_start_date'])   ? strtotime($uv['plan_start_date'])  : null;
    $isBiweekly = ($pattern === 'biweekly') || ($pattern === 'weekly' && $interval >= 2)
                  || ($pattern === 'custom' && strtolower($uv['recurrence_interval_unit'] ?? 'weeks') === 'weeks' && $interval >= 2);

    $scores = [];
    $currentDatePl2 = new DateTime($startDate);
    for ($pi = 0; $pi < 7; $pi++) {
        $ds         = $currentDatePl2->format('Y-m-d');
        $dayTs      = strtotime($ds);
        $dayDow     = (int)date('w', $dayTs); // 0=Sun..6=Sat
        $usedMin    = $dayCapUsed[$ds] ?? 0;
        $bcard      = $mcBattleCards[$ds] ?? [];

        // ── Capacity Fit (0–40) ──────────────────────────────────────────
        $afterMin = $usedMin + $duration;
        if ($afterMin <= 480) {
            $headroom = max(0, 480 - $usedMin);
            $capScore = (int)round(min(40, ($headroom / 480) * 40));
        } elseif ($afterMin <= 540) {
            $capScore = 10; // slight overload — still placeable
        } else {
            $capScore = 0;  // over capacity
        }

        // ── Cycle Match (0–40) ───────────────────────────────────────────
        $cycleScore = 20; // default for non-recurrence-specific
        $cycleNote  = '';
        if ($isBiweekly) {
            // Determine effective interval in days
            $intervalDays = $interval * 7;
            $refTs = $lastDone ?? $planStart ?? $dayTs;
            $daysDiff = abs((int)(($dayTs - $refTs) / 86400));
            $cycleMod = $daysDiff % $intervalDays;
            // Window: within ±2 days of perfect alignment = on-cycle
            if ($cycleMod <= 2 || $cycleMod >= ($intervalDays - 2)) {
                $cycleScore = 40;
                $cycleNote = 'On cycle ✓';
            } elseif ($cycleMod <= 5 || $cycleMod >= ($intervalDays - 5)) {
                $cycleScore = 25;
                $cycleNote = 'Near cycle';
            } else {
                $cycleScore = 0;
                $cycleNote = 'Off cycle';
            }
        } elseif ($pattern === 'weekly' || ($pattern === 'custom' && $interval === 1)) {
            if ($prefDow !== null) {
                if ($dayDow === $prefDow) {
                    $cycleScore = 40;
                    $cycleNote = 'Preferred day ✓';
                } else {
                    $cycleScore = 15;
                    $cycleNote = 'Not preferred day';
                }
            } else {
                $cycleScore = 35; // no preference — most days work
                $cycleNote = 'Any day OK';
            }
        } else {
            // Monthly/custom with large interval — any day is reasonable
            $cycleScore = 30;
            $cycleNote = '';
        }

        // ── Weather (0–20) ───────────────────────────────────────────────
        $risk = $bcard['weather_risk'] ?? 'low';
        $weatherScore = $risk === 'low' ? 20 : ($risk === 'medium' ? 10 : 0);

        // ── Density Bonus (0–10) ─────────────────────────────────────────
        $existingStops = $bcard['stops'] ?? 0;
        $densityScore  = $existingStops > 0 ? min(10, $existingStops * 2) : 0;

        // ── Total ────────────────────────────────────────────────────────
        $total = $capScore + $cycleScore + $weatherScore + $densityScore;
        $total = max(0, min(100, $total));

        // ── Label ────────────────────────────────────────────────────────
        if ($total >= 80)       $label = 'Best';
        elseif ($total >= 60)   $label = 'Good';
        elseif ($total >= 40)   $label = 'OK';
        elseif ($capScore === 0) $label = 'Full';
        elseif ($cycleScore === 0 && $isBiweekly) $label = 'Off cycle';
        else                    $label = 'Poor';

        $scores[$ds] = [
            'score'      => $total,
            'cap'        => $capScore,
            'cycle'      => $cycleScore,
            'weather'    => $weatherScore,
            'density'    => $densityScore,
            'label'      => $label,
            'cycle_note' => $cycleNote,
        ];
        $currentDatePl2->modify('+1 day');
    }
    $placementScores[$visitId] = $scores;
}

// ─── Mission Control: Weekly aggregate calculations ──────────────────
// Computed once here, used both in the Mission Control header and per-day
// Battle Cards. All numbers are estimates derived from plan prices and
// the default crew hourly rate ($25/hr).
$MC_DAILY_TARGET   = 1200.00; // Default daily revenue target (can be config-driven later)
$MC_WEEKLY_TARGET  = $MC_DAILY_TARGET * 5;
$MC_LABOR_RATE_DEFAULT = 25.00; // $/hr fallback when user hourly_rate is unknown

// Per-day aggregates: [dateStr => ['revenue'=>float, 'labor'=>float, 'stops'=>int, 'duration_min'=>int, 'coords'=>[[lat,lng],...]]]
$mcDayStats = [];
$mcWeekRevenue  = 0.0;
$mcWeekLabor    = 0.0;
$mcWeekDuration = 0;  // minutes

$currentDate2 = new DateTime($startDate);
for ($di = 0; $di < 7; $di++) {
    $ds = $currentDate2->format('Y-m-d');
    $dayRev = 0.0;
    $dayLabor = 0.0;
    $dayDuration = 0;
    $dayCoords = [];
    $dayStopsArr = $calendarData[$ds] ?? [];

    foreach ($dayStopsArr as $stop) {
        if (!empty($stop['latitude']) && !empty($stop['longitude'])) {
            $dayCoords[] = [(float)$stop['latitude'], (float)$stop['longitude']];
        }
        foreach (($stop['visits'] ?? []) as $v) {
            $price = (float)($v['price_per_visit'] ?? 0);
            $dur   = (int)($v['estimated_duration'] ?? 0);
            $dayRev      += $price;
            $dayDuration += $dur;
            $dayLabor    += ($dur / 60.0) * $MC_LABOR_RATE_DEFAULT;
        }
    }

    $mcDayStats[$ds] = [
        'revenue'      => $dayRev,
        'labor'        => $dayLabor,
        'stops'        => count($dayStopsArr),
        'duration_min' => $dayDuration,
        'coords'       => $dayCoords,
    ];
    $mcWeekRevenue  += $dayRev;
    $mcWeekLabor    += $dayLabor;
    $mcWeekDuration += $dayDuration;
    $currentDate2->modify('+1 day');
}

// Drive-time estimate: assume 8 min average between each pair of stops within a day.
// Each day with N stops has (N - 1) inter-stop gaps. We also add 10 min per active
// day for the garage → first stop leg (not counted in stop-to-stop gaps).
// In future: replace with actual drive times from the route engine.
$mcWeekStops    = array_sum(array_column($mcDayStats, 'stops'));
$mcDriveTimeMin = 0;
foreach ($mcDayStats as $dStats) {
    $n = (int)$dStats['stops'];
    if ($n > 0) {
        $mcDriveTimeMin += max(0, $n - 1) * 8; // inter-stop gaps
        $mcDriveTimeMin += 10;                  // garage → first stop
    }
}

// Route efficiency score (0–100): ratio of actual work time vs total day duration
// 100 = all time is billable, 0 = all time is driving
$mcTotalTimeMin  = $mcWeekDuration + $mcDriveTimeMin;
$mcEfficiency    = $mcTotalTimeMin > 0
    ? (int)round(min(100, ($mcWeekDuration / $mcTotalTimeMin) * 100))
    : 0;

// Density score (0–100): average stops per active day vs an "ideal" 6 stops/day
$mcActiveDays = 0;
foreach ($mcDayStats as $dStats) {
    if ($dStats['stops'] > 0) $mcActiveDays++;
}
$mcAvgStopsPerDay = $mcActiveDays > 0 ? ($mcWeekStops / $mcActiveDays) : 0;
$mcDensity = (int)round(min(100, ($mcAvgStopsPerDay / 6.0) * 100));

// Week margin %
$mcWeekOverhead   = $mcWeekLabor * 0.30; // 30% overhead on labor
$mcWeekTotalCost  = $mcWeekLabor + $mcWeekOverhead;
$mcWeekMargin     = $mcWeekRevenue > 0
    ? (int)round((($mcWeekRevenue - $mcWeekTotalCost) / $mcWeekRevenue) * 100)
    : 0;

// Stretch target: +15% above current projected revenue
$mcStretchTarget = $mcWeekRevenue * 1.15;

// Completed stops this week (for progress bar)
$mcCompletedStops = 0;
foreach ($calendarData as $dateStops) {
    foreach ($dateStops as $stop) {
        if (($stop['stop_status'] ?? '') === 'completed') $mcCompletedStops++;
    }
}
$mcProgressPct = $mcWeekStops > 0 ? (int)round(($mcCompletedStops / $mcWeekStops) * 100) : 0;

// Revenue progress vs weekly target
$mcRevenueProgressPct = $MC_WEEKLY_TARGET > 0 ? (int)round(min(100, ($mcWeekRevenue / $MC_WEEKLY_TARGET) * 100)) : 0;

// Per-day battle card data
$mcBattleCards = [];
$currentDate2 = new DateTime($startDate);
for ($di = 0; $di < 7; $di++) {
    $ds  = $currentDate2->format('Y-m-d');
    $dSt = $mcDayStats[$ds];
    $dRev = $dSt['revenue'];
    $dLab = $dSt['labor'];
    $dOh  = $dLab * 0.30;
    $dCost = $dLab + $dOh;
    $dMargin = $dRev > 0 ? (int)round((($dRev - $dCost) / $dRev) * 100) : null;
    $dDensity = (int)round(min(100, ($dSt['stops'] / 6.0) * 100));
    $dDriveMin = $dSt['stops'] > 0 ? (max(0, $dSt['stops'] - 1) * 8 + 10) : 0;

    // Weather risk: rain/storm/snow → high; wind > 30 → medium
    $dWeather = $weekWeather[$ds] ?? [];
    $dCondLow = strtolower($dWeather['condition'] ?? '');
    $dPrecip  = (float)($dWeather['precipitation'] ?? 0);
    $dWind    = (float)($dWeather['wind'] ?? 0);
    if (strpos($dCondLow, 'storm') !== false || strpos($dCondLow, 'snow') !== false || $dPrecip > 10) {
        $dWeatherRisk = 'high';
    } elseif ($dPrecip > 3 || $dWind > 30) {
        $dWeatherRisk = 'medium';
    } else {
        $dWeatherRisk = 'low';
    }

    // Outlier: any stop with < 20% margin or missing profitability data
    $dOutlier = false;
    foreach (($calendarData[$ds] ?? []) as $stop) {
        foreach (($stop['visits'] ?? []) as $v) {
            $pid = (int)($v['plan_id'] ?? 0);
            if ($pid && isset($profitabilityMap[$pid])) {
                if ($profitabilityMap[$pid]['has_data'] && $profitabilityMap[$pid]['margin_pct'] < 20) {
                    $dOutlier = true;
                }
            }
        }
    }

    $mcBattleCards[$ds] = [
        'revenue'      => $dRev,
        'margin'       => $dMargin,
        'density'      => $dDensity,
        'weather_risk' => $dWeatherRisk,
        'outlier'      => $dOutlier,
        'drive_min'    => $dDriveMin,
        'stops'        => $dSt['stops'],
    ];
    $currentDate2->modify('+1 day');
}

// ─── Gamification: streak + badges ──────────────────────────────────
// Streak = consecutive days this week where all stops were completed
// Simple heuristic: count from today backwards
$mcStreak = 0;
$streakDate = new DateTime(date('Y-m-d'));
for ($si = 0; $si < 30; $si++) {
    $sd = $streakDate->format('Y-m-d');
    $dayData = $calendarData[$sd] ?? [];
    if (empty($dayData)) { $streakDate->modify('-1 day'); continue; } // skip empty days
    $allDone = true;
    foreach ($dayData as $stop) {
        if (($stop['stop_status'] ?? '') !== 'completed' && ($stop['stop_status'] ?? '') !== 'skipped') {
            $allDone = false;
            break;
        }
    }
    if (!$allDone) break;
    $mcStreak++;
    $streakDate->modify('-1 day');
}

// Efficiency badge tier
if ($mcEfficiency >= 80)      $mcBadgeTier = 'gold';
elseif ($mcEfficiency >= 60)  $mcBadgeTier = 'silver';
elseif ($mcEfficiency >= 40)  $mcBadgeTier = 'bronze';
else                          $mcBadgeTier = null;

// ─── Route Feasibility: per-day verdict ─────────────────────────────
// Combines 4 real signals into one green/amber/red verdict per day.
// Signal 1 — Crew:    any stop with no assigned crew → not viable
// Signal 2 — Load:    total job minutes > 480 min (8h window) → over capacity
// Signal 3 — Weather: precipitation/wind/temp vs ops constraints → weather risk
// Signal 4 — Weather block: any visit has weather_ok = 0 → blocked by weather cron

// Load ops weather constraints once
$opsConstraints = [];
try {
    $opsConstraints = getWeatherOpsConstraints();
} catch (Throwable $e) {
    $opsConstraints = getDefaultOpsConstraints();
}
$opsMaxPrecip   = (float)($opsConstraints['default_max_precip_chance_pct'] ?? 50);
$opsMaxWind     = (float)($opsConstraints['default_max_wind_kph'] ?? 50);
$opsMinTemp     = (float)($opsConstraints['default_min_temp_c'] ?? -5);
$opsMaxTemp     = (float)($opsConstraints['default_max_temp_c'] ?? 40);
$opsBorderlineL = (float)($opsConstraints['borderline_precip_chance_low'] ?? 30);

// Batch load weather_ok flag from job_visits for all stop IDs this week
$allStopIds = [];
foreach ($calendarData as $dateStops) {
    foreach ($dateStops as $stop) {
        $allStopIds[] = (int)$stop['stop_id'];
    }
}
$weatherBlockedStops = []; // stopId => bool
if (!empty($allStopIds)) {
    $wph = implode(',', array_fill(0, count($allStopIds), '?'));
    try {
        $wStmt = $db->prepare("
            SELECT stop_id, MIN(COALESCE(weather_ok, 1)) AS any_blocked
            FROM job_visits
            WHERE stop_id IN ({$wph})
              AND status NOT IN ('cancelled', 'skipped')
            GROUP BY stop_id
        ");
        $wStmt->execute(array_values($allStopIds));
        while ($wRow = $wStmt->fetch(PDO::FETCH_ASSOC)) {
            $weatherBlockedStops[(int)$wRow['stop_id']] = ((int)$wRow['any_blocked'] === 0);
        }
    } catch (Throwable $e) {
        // weather_ok column may not exist on older installs — continue
    }
}

// Compute per-day feasibility verdict
$mcFeasibility = []; // dateStr => ['verdict'=>'go'|'caution'|'no-go', 'issues'=>[...], 'signals'=>[...]]
$currentDate2b = new DateTime($startDate);
for ($di = 0; $di < 7; $di++) {
    $ds       = $currentDate2b->format('Y-m-d');
    $dayStops = $calendarData[$ds] ?? [];
    $weather  = $weekWeather[$ds] ?? [];

    $issues  = [];
    $signals = [];

    // — Signal 1: Unassigned stops —
    $unassignedCount = 0;
    foreach ($dayStops as $stop) {
        $hasCrew = !empty($stop['crew_ids']) || !empty($stop['crew_id']);
        if (!$hasCrew) $unassignedCount++;
    }
    if ($unassignedCount > 0) {
        $issues[] = "{$unassignedCount} stop" . ($unassignedCount > 1 ? 's' : '') . " unassigned";
        $signals['crew'] = 'red';
    } else {
        $signals['crew'] = count($dayStops) > 0 ? 'green' : 'grey';
    }

    // — Signal 2: Capacity load —
    $totalJobMin = (int)($mcDayStats[$ds]['duration_min'] ?? 0);
    $estDriveMin = (int)($mcBattleCards[$ds]['drive_min'] ?? 0);
    $totalDayMin = $totalJobMin + $estDriveMin;
    $loadPct     = $totalDayMin > 0 ? round(($totalDayMin / 480) * 100) : 0;
    if ($loadPct > 100) {
        $issues[] = "Over capacity ({$loadPct}% of 8h)";
        $signals['load'] = 'red';
    } elseif ($loadPct > 85) {
        $issues[] = "Near capacity ({$loadPct}% of 8h)";
        $signals['load'] = 'amber';
    } else {
        $signals['load'] = count($dayStops) > 0 ? 'green' : 'grey';
    }

    // — Signal 3: Forecast weather vs ops constraints —
    $precip  = (float)($weather['precipitation'] ?? 0);
    $wind    = (float)($weather['wind'] ?? 0);
    $tempHi  = (float)($weather['temp_high'] ?? 15);
    $tempLo  = (float)($weather['temp_low'] ?? 5);
    $cond    = strtolower($weather['condition'] ?? '');

    $weatherIssues = [];
    if ($precip >= $opsMaxPrecip || strpos($cond, 'storm') !== false || strpos($cond, 'thunder') !== false) {
        $weatherIssues[] = "Heavy precip ({$precip}%)";
    } elseif ($precip >= $opsBorderlineL) {
        $weatherIssues[] = "Rain likely ({$precip}%)";
    }
    if ($wind >= $opsMaxWind) {
        $weatherIssues[] = "High wind ({$wind} km/h)";
    }
    if ($tempLo <= $opsMinTemp) {
        $weatherIssues[] = "Freezing ({$tempLo}°C)";
    }
    if ($tempHi >= $opsMaxTemp) {
        $weatherIssues[] = "Extreme heat ({$tempHi}°C)";
    }
    if (!empty($weatherIssues)) {
        $issues = array_merge($issues, $weatherIssues);
        $signals['weather'] = ($precip >= $opsMaxPrecip || $wind >= $opsMaxWind) ? 'red' : 'amber';
    } else {
        $signals['weather'] = count($dayStops) > 0 ? 'green' : 'grey';
    }

    // — Signal 4: Weather-blocked visits (from weather cron) —
    $blockedCount = 0;
    foreach ($dayStops as $stop) {
        if (!empty($weatherBlockedStops[(int)$stop['stop_id']])) $blockedCount++;
    }
    if ($blockedCount > 0) {
        $issues[] = "{$blockedCount} visit" . ($blockedCount > 1 ? 's' : '') . " weather-blocked";
        $signals['blocked'] = 'red';
    } else {
        $signals['blocked'] = 'green';
    }

    // — Overall verdict —
    if (empty($dayStops)) {
        $verdict = 'empty';
    } elseif (in_array('red', $signals, true)) {
        $verdict = 'no-go';
    } elseif (in_array('amber', $signals, true)) {
        $verdict = 'caution';
    } else {
        $verdict = 'go';
    }

    $mcFeasibility[$ds] = [
        'verdict' => $verdict,
        'issues'  => $issues,
        'signals' => $signals,
        'load_pct'=> $loadPct,
    ];

    $currentDate2b->modify('+1 day');
}

// ─── Holiday lookup for calendar display ────────────────────────────
$weekHolidays = [];
try {
    $weekHolidays = getActiveHolidays($startDate, $endDate);
} catch (Exception $e) {
    // Table may not exist yet — continue without holiday display
}

// ─── Day view: separate assigned vs unassigned stops ─────────────────
$assignedStops = [];
$unassignedStops = [];
$dayContactMap = [];
$dayViewMapStops = [];

if ($view === 'day') {
    $dayStops = $calendarData[$dayDate] ?? [];

    // Sort by estimated arrival then route order
    uasort($dayStops, function ($a, $b) {
        $aTime = $a['estimated_arrival'] ?? ($a['visits'][0]['scheduled_time_start'] ?? '23:59:59');
        $bTime = $b['estimated_arrival'] ?? ($b['visits'][0]['scheduled_time_start'] ?? '23:59:59');
        $cmp = strcmp($aTime, $bTime);
        if ($cmp !== 0) return $cmp;
        return ($a['route_order'] ?? 999) - ($b['route_order'] ?? 999);
    });

    foreach ($dayStops as $stop) {
        $crewIds = !empty($stop['crew_ids']) ? $stop['crew_ids'] : ($stop['crew_id'] ? [(int)$stop['crew_id']] : []);
        if (!empty($crewIds)) {
            $assignedStops[] = $stop;
        } else {
            $unassignedStops[] = $stop;
        }
    }

    // Fetch contact names for day stops
    $dayPropertyIds = array_unique(array_column($dayStops, 'property_id'));
    if (!empty($dayPropertyIds)) {
        $ph = implode(',', array_fill(0, count($dayPropertyIds), '?'));
        $cStmt = $db->prepare("
            SELECT p.id AS property_id,
                   CONCAT(ct.first_name, ' ', ct.last_name) AS contact_name
            FROM properties p
            LEFT JOIN contacts ct ON p.site_contact_id = ct.id
            WHERE p.id IN ({$ph})
        ");
        $cStmt->execute(array_values($dayPropertyIds));
        while ($row = $cStmt->fetch(PDO::FETCH_ASSOC)) {
            $dayContactMap[(int)$row['property_id']] = $row['contact_name'];
        }
    }

    // Build JS data for the day view map
    foreach (array_merge($assignedStops, $unassignedStops) as $stop) {
        $isAssigned = !empty($stop['crew_ids']) || !empty($stop['crew_id']);
        $dayViewMapStops[] = [
            'stopId'      => (int)$stop['stop_id'],
            'lat'         => $stop['latitude'] ? (float)$stop['latitude'] : null,
            'lng'         => $stop['longitude'] ? (float)$stop['longitude'] : null,
            'address'     => !empty($stop['property_address'])
                                ? trim($stop['property_address'] . ', ' . ($stop['property_city'] ?? 'Vancouver') . ', BC, Canada')
                                : null,
            'routeOrder'  => (int)($stop['route_order'] ?? 999),
            'contactName' => $dayContactMap[(int)$stop['property_id']] ?? ($stop['contact_name'] ?? ''),
            'serviceType' => !empty($stop['visits']) ? ($stop['visits'][0]['service_type'] ?? '') : '',
            'planTitle'   => !empty($stop['visits']) ? ($stop['visits'][0]['plan_title'] ?? '') : '',
            'time'        => !empty($stop['estimated_arrival']) ? date('g:i A', strtotime($stop['estimated_arrival'])) : '',
            'duration'    => !empty($stop['visits']) ? (int)($stop['visits'][0]['estimated_duration'] ?? 0) : 0,
            'assigned'    => $isAssigned,
            'crewNames'   => !empty($stop['crew_names']) ? $stop['crew_names'] : ($stop['crew_name'] ? [$stop['crew_name']] : []),
        ];
    }
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

// ─── Profit bar color helper ────────────────────────────────────────
function profitBarColor(int $margin): string {
    if ($margin < 20) return '#DC2626';      // red
    if ($margin < 40) return '#F59E0B';      // gold/amber
    return '#2D8659';                         // green
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

// ─── Build filter query string (crew + service) ─────────────────────
function buildFilterQuery(?int $crewId, ?string $serviceType): string {
    $qs = '';
    if ($crewId !== null) $qs .= '&crew=' . $crewId;
    if ($serviceType !== null) $qs .= '&service=' . urlencode($serviceType);
    return $qs;
}

$filterQueryStr = buildFilterQuery($crewFilter, $serviceFilter);

// ─── Day names ──────────────────────────────────────────────────────
$dayNames = ['MON', 'TUE', 'WED', 'THU', 'FRI', 'SAT', 'SUN'];

// ─── Mobile card view: date-aware (defaults to today, respects ?view=day&date=X) ──
$today = date('Y-m-d');

// Mobile view uses the day-view date if set, otherwise today
$mobileDate = ($view === 'day' && !empty($dayDate)) ? $dayDate : $today;

// Validate $mobileDate is a real date
if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $mobileDate)) {
    $mobileDate = $today;
}

$todayDayName    = strtoupper(date('D', strtotime($mobileDate)));  // e.g. "THU"
$todayDateDisplay = date('M j', strtotime($mobileDate));             // e.g. "Feb 20"

// Prev/next day for mobile date nav arrows
$mobilePrevDay = date('Y-m-d', strtotime($mobileDate . ' -1 day'));
$mobileNextDay = date('Y-m-d', strtotime($mobileDate . ' +1 day'));
$mobileIsToday = ($mobileDate === $today);

// Get weather for the selected mobile date
$todayWeather = $weekWeather[$mobileDate] ?? $todaysForecast[$mobileDate] ?? [
    'temp_high' => 12, 'temp_low' => 8, 'condition' => 'Clear'
];

// Build mobile stops for the selected date (mobileDate)
$mobileStops = [];
$todayStops = $calendarData[$mobileDate] ?? [];
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
$bodyClass  = 'mw-page-schedule'; // Hides global mobile nav bars — schedule has its own
$apiKey = defined('GOOGLE_MAPS_API_KEY') ? GOOGLE_MAPS_API_KEY : '';
$extraHead = '<link href="/crm/css/mobile-cards.css?v=20260226e" rel="stylesheet">';
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
                  <?php if ($view === 'day'): ?>
                      <a href="?view=day&date=<?php echo htmlspecialchars($prevDay) . $filterQueryStr; ?>" class="mw-nav-btn" aria-label="Previous day">
                          <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><polyline points="15 18 9 12 15 6"/></svg>
                      </a>
                      <button type="button" class="mw-datepicker-trigger" id="mwDpTrigger2"
                              data-current="<?php echo htmlspecialchars($dayDate); ?>"
                              data-view="day"
                              aria-haspopup="true" aria-expanded="false">
                          <svg class="mw-dp-cal-icon" width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="3" y="4" width="18" height="18" rx="2" ry="2"/><line x1="16" y1="2" x2="16" y2="6"/><line x1="8" y1="2" x2="8" y2="6"/><line x1="3" y1="10" x2="21" y2="10"/></svg>
                          <span class="mw-dp-weekday"><?php echo date('D', strtotime($dayDate)); ?></span>
                          <span class="mw-dp-date"><?php echo date('M j, Y', strtotime($dayDate)); ?></span>
                          <svg class="mw-dp-chevron" width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><polyline points="6 9 12 15 18 9"/></svg>
                      </button>
                      <a href="?view=day&date=<?php echo htmlspecialchars($nextDay) . $filterQueryStr; ?>" class="mw-nav-btn" aria-label="Next day">
                          <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><polyline points="9 18 15 12 9 6"/></svg>
                      </a>
                      <a href="?view=day<?php echo $filterQueryStr; ?>" class="mw-today-btn">Today</a>
                  <?php else: ?>
                      <a href="?start=<?php echo htmlspecialchars($prevWeek) . $filterQueryStr; ?>" class="mw-nav-btn" aria-label="Previous week">
                          <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><polyline points="15 18 9 12 15 6"/></svg>
                      </a>
                      <button type="button" class="mw-datepicker-trigger" id="mwDpTrigger2"
                              data-current="<?php echo htmlspecialchars($startDate); ?>"
                              data-view="week"
                              aria-haspopup="true" aria-expanded="false">
                          <svg class="mw-dp-cal-icon" width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="3" y="4" width="18" height="18" rx="2" ry="2"/><line x1="16" y1="2" x2="16" y2="6"/><line x1="8" y1="2" x2="8" y2="6"/><line x1="3" y1="10" x2="21" y2="10"/></svg>
                          <span class="mw-dp-date"><?php echo date('M j', strtotime($startDate)); ?> – <?php echo date('M j, Y', strtotime($endDate)); ?></span>
                          <svg class="mw-dp-chevron" width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><polyline points="6 9 12 15 18 9"/></svg>
                      </button>
                      <a href="?start=<?php echo htmlspecialchars($nextWeek) . $filterQueryStr; ?>" class="mw-nav-btn" aria-label="Next week">
                          <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><polyline points="9 18 15 12 9 6"/></svg>
                      </a>
                      <a href="?<?php echo ltrim($filterQueryStr, '&'); ?>" class="mw-today-btn">Today</a>
                  <?php endif; ?>
              </div>

              <!-- ── Date Picker Dropdown ── -->
              <div class="mw-datepicker-popup" id="mwDpPopup2" role="dialog" aria-label="Date picker" hidden>
                  <div class="mw-dp-header">
                      <button type="button" class="mw-dp-nav-btn" id="mwDpPrevMonth" aria-label="Previous month">
                          <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><polyline points="15 18 9 12 15 6"/></svg>
                      </button>
                      <span class="mw-dp-month-label" id="mwDpMonthLabel"></span>
                      <button type="button" class="mw-dp-nav-btn" id="mwDpNextMonth" aria-label="Next month">
                          <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><polyline points="9 18 15 12 9 6"/></svg>
                      </button>
                  </div>
                  <div class="mw-dp-weekdays">
                      <span>Mo</span><span>Tu</span><span>We</span><span>Th</span><span>Fr</span><span>Sa</span><span>Su</span>
                  </div>
                  <div class="mw-dp-grid" id="mwDpGrid"></div>
                  <div class="mw-dp-footer">
                      <button type="button" class="mw-dp-today-link" id="mwDpTodayBtn">Jump to Today</button>
                  </div>
              </div>

              <div class="mw-schedule-filters">
                  <!-- Service filter -->
                  <select id="serviceFilter" class="form-control form-control-sm" onchange="applyFilter()">
                      <option value="">All Services</option>
                      <?php foreach ($serviceLabels as $key => $label): ?>
                          <option value="<?php echo htmlspecialchars($key); ?>"
                              <?php echo ($serviceFilter === $key) ? 'selected' : ''; ?>>
                              <?php echo htmlspecialchars($label); ?>
                          </option>
                      <?php endforeach; ?>
                  </select>
                  <!-- Crew filter -->
                  <select id="crewFilter" class="form-control form-control-sm" onchange="applyFilter()">
                      <option value="">All Crews</option>
                      <?php foreach ($staff as $member): ?>
                          <option value="<?php echo (int)$member['id']; ?>"
                              <?php echo ($crewFilter === (int)$member['id']) ? 'selected' : ''; ?>>
                              <?php echo htmlspecialchars($member['full_name']); ?>
                          </option>
                      <?php endforeach; ?>
                  </select>
                  <!-- View toggle -->
                  <div class="mw-dv-view-toggle">
                      <a href="?start=<?php echo htmlspecialchars($weekStart) . $filterQueryStr; ?>"
                         class="mw-dv-toggle-btn <?php echo $view === 'week' ? 'active' : ''; ?>">Week</a>
                      <a href="?view=day&date=<?php echo htmlspecialchars($view === 'day' ? $dayDate : date('Y-m-d')) . $filterQueryStr; ?>"
                         class="mw-dv-toggle-btn <?php echo $view === 'day' ? 'active' : ''; ?>">Day</a>
                  </div>
                  <a href="index.php" class="btn btn-secondary btn-sm">List View</a>
              </div>
          </div>

          <!-- ═══════════════════════════════════════════════
               MISSION CONTROL HEADER (Week View Only)
               Projected revenue, labor, margin, drive time,
               efficiency score, density, stretch target,
               gamification badges — desktop only
               ═══════════════════════════════════════════════ -->
          <div class="mw-mission-control d-none d-lg-block">

              <!-- Top row: primary KPIs -->
              <div class="mw-mc-kpi-row">

                  <div class="mw-mc-kpi mw-mc-kpi-revenue">
                      <div class="mw-mc-kpi-label">Projected Revenue</div>
                      <div class="mw-mc-kpi-value">$<?php echo number_format($mcWeekRevenue, 0); ?></div>
                      <div class="mw-mc-kpi-sub">Target $<?php echo number_format($MC_WEEKLY_TARGET, 0); ?></div>
                  </div>

                  <div class="mw-mc-kpi mw-mc-kpi-labor">
                      <div class="mw-mc-kpi-label">Labor Cost</div>
                      <div class="mw-mc-kpi-value">$<?php echo number_format($mcWeekLabor, 0); ?></div>
                      <div class="mw-mc-kpi-sub"><?php echo number_format(($mcWeekRevenue > 0 ? ($mcWeekLabor / $mcWeekRevenue) * 100 : 0), 0); ?>% of revenue</div>
                  </div>

                  <div class="mw-mc-kpi mw-mc-kpi-margin <?php echo $mcWeekMargin >= 40 ? 'is-green' : ($mcWeekMargin >= 20 ? 'is-amber' : 'is-red'); ?>">
                      <div class="mw-mc-kpi-label">Margin</div>
                      <div class="mw-mc-kpi-value"><?php echo $mcWeekMargin; ?>%</div>
                      <div class="mw-mc-kpi-sub"><?php echo $mcWeekMargin >= 40 ? 'Healthy' : ($mcWeekMargin >= 20 ? 'Watch' : 'Critical'); ?></div>
                  </div>

                  <div class="mw-mc-kpi mw-mc-kpi-drive">
                      <div class="mw-mc-kpi-label">Total Time</div>
                      <div class="mw-mc-kpi-value"><?php echo round($mcTotalTimeMin / 60, 1); ?>h</div>
                      <div class="mw-mc-kpi-sub"><?php echo $mcWeekDuration; ?> work + <?php echo $mcDriveTimeMin; ?> drive</div>
                  </div>

                  <div class="mw-mc-kpi mw-mc-kpi-efficiency">
                      <div class="mw-mc-kpi-label">Route Efficiency</div>
                      <div class="mw-mc-kpi-value" id="mc-efficiency-val"><?php echo $mcEfficiency; ?></div>
                      <div class="mw-mc-kpi-sub">score / 100</div>
                  </div>

                  <div class="mw-mc-kpi mw-mc-kpi-density">
                      <div class="mw-mc-kpi-label">Density Score</div>
                      <div class="mw-mc-kpi-value"><?php echo $mcDensity; ?></div>
                      <div class="mw-mc-kpi-sub"><?php echo number_format($mcAvgStopsPerDay, 1); ?> stops/day</div>
                  </div>

                  <div class="mw-mc-kpi mw-mc-kpi-stretch">
                      <div class="mw-mc-kpi-label">Stretch Target</div>
                      <div class="mw-mc-kpi-value">$<?php echo number_format($mcStretchTarget, 0); ?></div>
                      <div class="mw-mc-kpi-sub">+15% of projected</div>
                  </div>

                  <!-- Gamification panel -->
                  <div class="mw-mc-gamification">
                      <?php if ($mcStreak > 0): ?>
                      <div class="mw-mc-badge mw-mc-badge-streak" title="<?php echo $mcStreak; ?>-day completion streak">
                          <span class="mw-mc-badge-icon">🔥</span>
                          <span class="mw-mc-badge-text"><?php echo $mcStreak; ?> day streak</span>
                      </div>
                      <?php endif; ?>
                      <?php if ($mcBadgeTier): ?>
                      <div class="mw-mc-badge mw-mc-badge-eff mw-mc-badge-<?php echo $mcBadgeTier; ?>" title="Efficiency badge: <?php echo $mcBadgeTier; ?>">
                          <span class="mw-mc-badge-icon"><?php echo $mcBadgeTier === 'gold' ? '🥇' : ($mcBadgeTier === 'silver' ? '🥈' : '🥉'); ?></span>
                          <span class="mw-mc-badge-text"><?php echo ucfirst($mcBadgeTier); ?> Efficiency</span>
                      </div>
                      <?php endif; ?>
                      <div class="mw-mc-badge mw-mc-badge-stops" title="<?php echo $mcCompletedStops; ?> of <?php echo $mcWeekStops; ?> stops done">
                          <span class="mw-mc-badge-icon">✓</span>
                          <span class="mw-mc-badge-text"><?php echo $mcCompletedStops; ?>/<?php echo $mcWeekStops; ?> stops</span>
                      </div>
                  </div>

              </div><!-- /.mw-mc-kpi-row -->

              <!-- Progress bar row: weekly completion + revenue vs target -->
              <div class="mw-mc-progress-row">
                  <div class="mw-mc-prog-block">
                      <div class="mw-mc-prog-label">
                          <span>Weekly Completion</span>
                          <span><?php echo $mcProgressPct; ?>% · <?php echo $mcCompletedStops; ?>/<?php echo $mcWeekStops; ?> stops</span>
                      </div>
                      <div class="mw-mc-prog-track">
                          <div class="mw-mc-prog-fill mw-mc-prog-stops" style="width: <?php echo $mcProgressPct; ?>%"
                               data-target="<?php echo $mcProgressPct; ?>"></div>
                      </div>
                  </div>
                  <div class="mw-mc-prog-block">
                      <div class="mw-mc-prog-label">
                          <span>Revenue vs Target</span>
                          <span>$<?php echo number_format($mcWeekRevenue, 0); ?> / $<?php echo number_format($MC_WEEKLY_TARGET, 0); ?></span>
                      </div>
                      <div class="mw-mc-prog-track">
                          <div class="mw-mc-prog-fill mw-mc-prog-revenue" style="width: <?php echo $mcRevenueProgressPct; ?>%"
                               data-target="<?php echo $mcRevenueProgressPct; ?>"></div>
                      </div>
                  </div>
                  <div class="mw-mc-prog-block mw-mc-prog-block-efficiency">
                      <div class="mw-mc-prog-label">
                          <span>Route Efficiency</span>
                          <span id="mc-eff-label"><?php echo $mcEfficiency; ?>/100</span>
                      </div>
                      <div class="mw-mc-prog-track">
                          <div class="mw-mc-prog-fill mw-mc-prog-eff" id="mc-eff-bar"
                               style="width: <?php echo $mcEfficiency; ?>%"
                               data-target="<?php echo $mcEfficiency; ?>"></div>
                      </div>
                  </div>
              </div><!-- /.mw-mc-progress-row -->

          </div><!-- /.mw-mission-control -->

          <?php if ($view === 'week'): ?>
          <!-- ═══════════════════════════════════════════════
               DESKTOP: Calendar container (hidden on mobile)
               ═══════════════════════════════════════════════ -->
          <div class="mw-calendar-container<?php echo $unscheduledCount > 0 ? ' mw-calendar-container--has-tray' : ''; ?>">

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
              <div class="mw-stop-grid<?php echo $unscheduledCount > 0 ? ' mw-stop-grid--has-tray' : ''; ?>" id="mwStopGrid">

                  <!-- ── Unscheduled Jobs Tray ──────────────────────────── -->
                  <?php if ($unscheduledCount > 0): ?>
                  <div class="mw-tray" id="mwTray">
                      <div class="mw-tray-header" id="mwTrayToggle" title="Toggle unscheduled jobs queue">
                          <span class="mw-tray-icon">📋</span>
                          <span class="mw-tray-title">Queue</span>
                          <span class="mw-tray-count"><?php echo $unscheduledCount; ?></span>
                          <span class="mw-tray-chevron">&#8249;</span>
                      </div>
                      <div class="mw-tray-body" id="mwTrayBody">
                          <?php foreach ($unscheduledVisits as $uv):
                              $uvId = (int)$uv['visit_id'];
                              $uvPattern   = $uv['recurrence_pattern'] ?? 'weekly';
                              $uvInterval  = (int)($uv['recurrence_interval'] ?? 1);
                              $uvPrefDow   = $uv['recurrence_day_of_week'] !== null ? (int)$uv['recurrence_day_of_week'] : null;
                              $uvIsBiweek  = ($uvPattern === 'biweekly')
                                             || ($uvPattern === 'weekly' && $uvInterval >= 2)
                                             || ($uvPattern === 'custom' && strtolower($uv['recurrence_interval_unit'] ?? 'weeks') === 'weeks' && $uvInterval >= 2);
                              $uvRecLabel  = $uvIsBiweek ? '14-day' : ($uvPattern === 'weekly' ? 'Weekly' : ucfirst($uvPattern));
                              $uvScores    = $placementScores[$uvId] ?? [];
                              $uvScoreJson = htmlspecialchars(json_encode($uvScores), ENT_QUOTES);
                          ?>
                          <div class="mw-tray-card"
                               draggable="true"
                               data-visit-id="<?php echo $uvId; ?>"
                               data-plan-id="<?php echo (int)$uv['plan_id']; ?>"
                               data-property-id="<?php echo (int)$uv['property_id']; ?>"
                               data-service-type="<?php echo htmlspecialchars($uv['service_type'] ?? ''); ?>"
                               data-duration="<?php echo (int)($uv['estimated_duration'] ?? 0); ?>"
                               data-revenue="<?php echo round((float)($uv['price_per_visit'] ?? 0), 2); ?>"
                               data-recurrence="<?php echo htmlspecialchars($uvPattern); ?>"
                               data-recurrence-interval="<?php echo $uvInterval; ?>"
                               data-is-biweekly="<?php echo $uvIsBiweek ? '1' : '0'; ?>"
                               data-pref-dow="<?php echo $uvPrefDow !== null ? $uvPrefDow : ''; ?>"
                               data-placement-scores="<?php echo $uvScoreJson; ?>">
                              <div class="mw-tray-card-service" style="border-left:3px solid <?php echo getServiceColorLocal($uv['service_type'] ?? ''); ?>">
                                  <span class="mw-tray-card-type"><?php echo htmlspecialchars(getServiceLabelLocal($uv['service_type'] ?? '')); ?></span>
                                  <?php if ($uvIsBiweek): ?>
                                  <span class="mw-tray-card-recurrence-badge">14-day</span>
                                  <?php endif; ?>
                              </div>
                              <div class="mw-tray-card-address" title="<?php echo htmlspecialchars(($uv['property_address'] ?? '') . ', ' . ($uv['property_city'] ?? '')); ?>">
                                  <?php echo htmlspecialchars($uv['property_address'] ?? ''); ?>
                              </div>
                              <div class="mw-tray-card-meta">
                                  <span class="mw-tray-card-client"><?php echo htmlspecialchars($uv['contact_name'] ?? ''); ?></span>
                                  <span class="mw-tray-card-price">$<?php echo number_format((float)($uv['price_per_visit'] ?? 0), 0); ?></span>
                              </div>
                              <?php if (!empty($uv['scheduled_date'])): ?>
                              <div class="mw-tray-card-date">Due <?php echo date('M j', strtotime($uv['scheduled_date'])); ?></div>
                              <?php endif; ?>
                          </div>
                          <?php endforeach; ?>
                      </div>
                  </div>
                  <?php else: ?>
                  <div class="mw-stop-grid-label"></div>
                  <?php endif; ?>
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
                      <?php
                      // ─── Battle Card variables for this day ────────────────
                      $bcData    = $mcBattleCards[$dateStr] ?? [];
                      $bcMargin  = $bcData['margin']       ?? null;
                      $bcDensity = $bcData['density']      ?? 0;
                      $bcRisk    = $bcData['weather_risk'] ?? 'low';
                      $bcOutlier = $bcData['outlier']      ?? false;
                      $bcRev     = $bcData['revenue']      ?? 0;
                      $bcStops   = $bcData['stops']        ?? 0;
                      $bcDrive   = $bcData['drive_min']    ?? 0;

                      // ─── Feasibility verdict for this day ──────────────────
                      $bcFeas       = $mcFeasibility[$dateStr] ?? ['verdict'=>'empty','issues'=>[],'signals'=>[],'load_pct'=>0];
                      $bcVerdict    = $bcFeas['verdict'];   // go | caution | no-go | empty
                      $bcIssues     = $bcFeas['issues'];
                      $bcLoadPct    = $bcFeas['load_pct'];
                      $bcSignals    = $bcFeas['signals'];
                      $bcLabelMap = ['go' => 'Route GO', 'caution' => 'Caution', 'no-go' => 'No-Go'];
                      $bcIconMap  = ['go' => '&#10003;', 'caution' => '&#9888;', 'no-go' => '&#10005;'];
                      $bcVerdictLabel = $bcLabelMap[$bcVerdict] ?? '';
                      $bcVerdictIcon  = $bcIconMap[$bcVerdict]  ?? '';
                      $bcTooltip = empty($bcIssues)
                          ? ($bcVerdict === 'go' ? "All systems go · {$bcLoadPct}% capacity" : '')
                          : implode(' · ', $bcIssues);

                      // Margin color class for Battle Card
                      $bcMarginClass = '';
                      if ($bcMargin !== null) {
                          $bcMarginClass = $bcMargin >= 40 ? 'bc-margin-green' : ($bcMargin >= 20 ? 'bc-margin-amber' : 'bc-margin-red');
                      }
                      // Heatmap intensity: 0-100 based on density score
                      $bcHeatmapAlpha = round(($bcDensity / 100) * 0.08, 3); // subtle 0–0.08 overlay
                      ?>
                      <div class="mw-day-column mw-battle-card <?php echo $isToday ? 'today' : ''; ?> <?php echo $bcMarginClass; ?>"
                           data-date="<?php echo $dateStr; ?>"
                           data-density="<?php echo $bcDensity; ?>"
                           style="--bc-heat: <?php echo $bcHeatmapAlpha; ?>">

                          <!-- Battle Card header (shown when stops exist) -->
                          <?php if ($bcStops > 0): ?>
                          <div class="mw-bc-header">
                              <div class="mw-bc-header-top">
                                  <span class="mw-bc-rev">$<?php echo number_format($bcRev, 0); ?></span>
                                  <?php if ($bcMargin !== null): ?>
                                  <span class="mw-bc-margin <?php echo $bcMarginClass; ?>"><?php echo $bcMargin; ?>%</span>
                                  <?php endif; ?>
                                  <?php if ($bcOutlier): ?>
                                  <span class="mw-bc-outlier-flag" title="Low-margin job detected">⚠</span>
                                  <?php endif; ?>
                              </div>
                              <?php if ($bcVerdict !== 'empty'): ?>
                              <div class="mw-bc-feasibility mw-bc-feas-<?php echo $bcVerdict; ?>"
                                   title="<?php echo htmlspecialchars($bcTooltip); ?>">
                                  <span class="mw-bc-feas-icon"><?php echo $bcVerdictIcon; ?></span>
                                  <span class="mw-bc-feas-label"><?php echo $bcVerdictLabel; ?></span>
                                  <?php if (!empty($bcIssues)): ?>
                                  <span class="mw-bc-feas-issues"><?php echo count($bcIssues); ?> issue<?php echo count($bcIssues) > 1 ? 's' : ''; ?></span>
                                  <?php else: ?>
                                  <span class="mw-bc-feas-pct"><?php echo $bcLoadPct; ?>%</span>
                                  <?php endif; ?>
                                  <div class="mw-bc-feas-signals">
                                      <span class="mw-bc-sig mw-bc-sig-<?php echo $bcSignals['crew'] ?? 'grey'; ?>" title="Crew assignment">👤</span>
                                      <span class="mw-bc-sig mw-bc-sig-<?php echo $bcSignals['load'] ?? 'grey'; ?>" title="Capacity load">📊</span>
                                      <span class="mw-bc-sig mw-bc-sig-<?php echo $bcSignals['weather'] ?? 'grey'; ?>" title="Forecast weather">🌤</span>
                                      <span class="mw-bc-sig mw-bc-sig-<?php echo $bcSignals['blocked'] ?? 'grey'; ?>" title="Weather-blocked visits">🚫</span>
                                  </div>
                              </div>
                              <?php endif; ?>

                              <div class="mw-bc-header-bottom">
                                  <!-- Density score mini bar -->
                                  <div class="mw-bc-density" title="Density: <?php echo $bcDensity; ?>/100">
                                      <div class="mw-bc-density-bar" style="width: <?php echo $bcDensity; ?>%"></div>
                                  </div>
                                  <!-- Weather risk -->
                                  <span class="mw-bc-weather-risk mw-bc-risk-<?php echo $bcRisk; ?>"
                                        title="Weather risk: <?php echo $bcRisk; ?>">
                                      <?php echo $bcRisk === 'high' ? '⛈' : ($bcRisk === 'medium' ? '🌧' : '☀'); ?>
                                  </span>
                                  <!-- Drive time -->
                                  <span class="mw-bc-drive" title="Est. drive time"><?php echo $bcDrive; ?>m</span>
                              </div>
                              <!-- Mini profit meter -->
                              <?php if ($bcMargin !== null): ?>
                              <div class="mw-bc-profit-meter" title="Margin: <?php echo $bcMargin; ?>%">
                                  <div class="mw-bc-profit-fill" style="width: <?php echo max(0, min(100, $bcMargin)); ?>%"></div>
                              </div>
                              <?php endif; ?>
                          </div>
                          <?php endif; ?>

                          <!-- ── Placement Intelligence Strip ──────────────── -->
                          <!-- Hidden until a tray card is selected/hovered. JS  -->
                          <!-- sets data-score, data-label, data-cycle-note and  -->
                          <!-- toggles the mw-pi-active class on the day column. -->
                          <?php if ($unscheduledCount > 0): ?>
                          <div class="mw-pi-strip" data-date="<?php echo $dateStr; ?>">
                              <div class="mw-pi-bar"><div class="mw-pi-bar-fill"></div></div>
                              <div class="mw-pi-info">
                                  <span class="mw-pi-score"></span>
                                  <span class="mw-pi-label"></span>
                                  <span class="mw-pi-cycle-note"></span>
                              </div>
                          </div>
                          <?php endif; ?>

                          <?php if (empty($dayStops)): ?>
                              <div class="mw-day-empty">No stops</div>
                          <?php else: ?>
                              <?php
                              // Convert to indexed array for distance-to-next calculation
                              $dayStopsArr2 = array_values($dayStops);
                              $totalDayStops = count($dayStopsArr2);
                              ?>
                              <?php foreach ($dayStopsArr2 as $stopIdx => $stop): ?>
                                  <?php
                                      // Build crew IDs list from junction data (falls back to single crew_id)
                                      $crewIds = !empty($stop['crew_ids']) ? $stop['crew_ids'] : ($stop['crew_id'] ? [(int)$stop['crew_id']] : []);
                                      $crewIdsStr = implode(',', $crewIds);
                                      // Build visits JSON for modal quick links
                                      $visitsJson = [];
                                      if (!empty($stop['visits'])) {
                                          foreach ($stop['visits'] as $v) {
                                              $visitsJson[] = [
                                                  'plan_id' => (int)($v['plan_id'] ?? 0),
                                                  'plan_number' => $v['plan_number'] ?? '',
                                                  'service_type' => $v['service_type'] ?? '',
                                              ];
                                          }
                                      }
                                      $clientDisplay2 = $stop['contact_name'] ?? '';
                                      if (!$clientDisplay2) $clientDisplay2 = $stop['company_name'] ?? '';
                                      if (!$clientDisplay2) $clientDisplay2 = $stop['property_name'] ?? '';

                                      // Revenue for this stop
                                      $stopRevenue = 0.0;
                                      foreach (($stop['visits'] ?? []) as $sv) {
                                          $stopRevenue += (float)($sv['price_per_visit'] ?? 0);
                                      }

                                      // Distance-to-next (haversine, km) — only if next stop has coords
                                      $distToNext = null;
                                      if ($stopIdx < $totalDayStops - 1) {
                                          $nextStop = $dayStopsArr2[$stopIdx + 1];
                                          if (!empty($stop['latitude']) && !empty($stop['longitude'])
                                              && !empty($nextStop['latitude']) && !empty($nextStop['longitude'])) {
                                              $lat1 = deg2rad((float)$stop['latitude']);
                                              $lat2 = deg2rad((float)$nextStop['latitude']);
                                              $dLat = $lat2 - $lat1;
                                              $dLng = deg2rad((float)$nextStop['longitude'] - (float)$stop['longitude']);
                                              $a = sin($dLat/2)**2 + cos($lat1)*cos($lat2)*sin($dLng/2)**2;
                                              $distToNext = round(6371 * 2 * asin(sqrt($a)), 1); // km
                                          }
                                      }
                                  ?>
                                  <div class="mw-stop-card <?php echo stopStatusClass($stop['stop_status']); ?>"
                                       draggable="true"
                                       data-stop-id="<?php echo (int)$stop['stop_id']; ?>"
                                       data-stop-date="<?php echo htmlspecialchars($stop['stop_date']); ?>"
                                       data-route-order="<?php echo (int)$stop['route_order']; ?>"
                                       data-crew-id="<?php echo (int)($stop['crew_id'] ?? 0); ?>"
                                       data-crew-ids="<?php echo htmlspecialchars($crewIdsStr); ?>"
                                       data-property-address="<?php echo htmlspecialchars($stop['property_address'] ?? 'Unknown'); ?>"
                                       data-property-id="<?php echo (int)$stop['property_id']; ?>"
                                       data-contact-id="<?php echo (int)($stop['contact_id'] ?? 0); ?>"
                                       data-contact-name="<?php echo htmlspecialchars($clientDisplay2); ?>"
                                       data-visits="<?php echo htmlspecialchars(json_encode($visitsJson)); ?>"
                                       data-revenue="<?php echo round($stopRevenue, 2); ?>"
                                       data-lat="<?php echo htmlspecialchars($stop['latitude'] ?? ''); ?>"
                                       data-lng="<?php echo htmlspecialchars($stop['longitude'] ?? ''); ?>">

                                      <?php
                                      // Determine arrival time display
                                      $arrivalDisplay = '';
                                      if (!empty($stop['estimated_arrival'])) {
                                          $arrivalDisplay = date('g:i A', strtotime($stop['estimated_arrival']));
                                      } elseif (!empty($stop['visits']) && !empty($stop['visits'][0]['scheduled_time_start'])) {
                                          $arrivalDisplay = date('g:i A', strtotime($stop['visits'][0]['scheduled_time_start']));
                                      }
                                      ?>

                                      <?php
                                      // Client display: prefer contact_name, fall back to company, then property_name
                                      $clientDisplay = $stop['contact_name'] ?? '';
                                      if (!$clientDisplay) $clientDisplay = $stop['company_name'] ?? '';
                                      if (!$clientDisplay) $clientDisplay = $stop['property_name'] ?? '';
                                      ?>

                                      <?php if ($arrivalDisplay || $clientDisplay): ?>
                                          <div class="mw-stop-time-client">
                                              <?php if ($arrivalDisplay): ?>
                                                  <span class="mw-stop-time"><?php echo htmlspecialchars($arrivalDisplay); ?></span>
                                              <?php endif; ?>
                                              <?php if ($clientDisplay): ?>
                                                  <span class="mw-stop-client-name"><?php echo htmlspecialchars($clientDisplay); ?></span>
                                              <?php endif; ?>
                                          </div>
                                      <?php endif; ?>

                                      <div class="mw-stop-property"><?php echo htmlspecialchars($stop['property_address'] ?? 'Unknown'); ?></div>

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

                                      <?php
                                      // Display crew names (multi-crew from junction table, fallback to single)
                                      $crewNames = !empty($stop['crew_names']) ? $stop['crew_names'] : ($stop['crew_name'] ? [$stop['crew_name']] : []);
                                      if (!empty($crewNames)):
                                      ?>
                                          <div class="mw-stop-crew"><?php echo htmlspecialchars(implode(', ', $crewNames)); ?></div>
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
                                              <div class="mw-profit-bar-fill" style="width: <?php echo max(0, min(100, $stopMargin)); ?>%; background: <?php echo profitBarColor($stopMargin); ?>" data-margin="<?php echo $stopMargin; ?>"></div>
                                          </div>
                                      <?php elseif (!empty($stop['visits'])): ?>
                                          <div class="mw-profit-bar mw-profit-bar-empty" title="No profitability data yet"></div>
                                      <?php endif; ?>

                                      <!-- Revenue strip -->
                                      <?php if ($stopRevenue > 0): ?>
                                      <div class="mw-stop-revenue-strip">
                                          <span class="mw-stop-rev-icon">$</span>
                                          <span class="mw-stop-rev-amount"><?php echo number_format($stopRevenue, 0); ?></span>
                                          <?php if ($stopHasProfit && $stopMargin !== null): ?>
                                          <span class="mw-stop-rev-margin <?php echo $stopMargin >= 40 ? 'is-green' : ($stopMargin >= 20 ? 'is-amber' : 'is-red'); ?>">
                                              <?php echo $stopMargin; ?>%
                                          </span>
                                          <?php endif; ?>
                                      </div>
                                      <?php endif; ?>

                                      <!-- Distance to next stop -->
                                      <?php if ($distToNext !== null): ?>
                                      <div class="mw-stop-distance" title="Distance to next stop">
                                          <svg width="8" height="8" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><path d="M5 12h14M12 5l7 7-7 7"/></svg>
                                          <?php echo $distToNext; ?> km
                                      </div>
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
          <?php endif; ?>

          <?php if ($view === 'day'): ?>
          <!-- ═══════════════════════════════════════════════
               DESKTOP: Day View (split panel: cards left, map right)
               ═══════════════════════════════════════════════ -->
          <?php
              $dayWeather = $weekWeather[$dayDate] ?? null;
          ?>
          <div class="mw-dv-container">
              <!-- Left panel: stop cards -->
              <div class="mw-dv-cards-panel">
                  <?php if ($dayWeather): ?>
                  <div class="mw-dv-weather">
                      <span class="mw-dv-weather-icon"><?php echo $dayWeather['icon'] ?? '&#9925;'; ?></span>
                      <span class="mw-dv-weather-temp"><?php echo (int)($dayWeather['temp_high'] ?? 0); ?>&deg; / <?php echo (int)($dayWeather['temp_low'] ?? 0); ?>&deg;</span>
                      <span class="mw-dv-weather-cond"><?php echo htmlspecialchars($dayWeather['condition'] ?? ''); ?></span>
                  </div>
                  <?php endif; ?>

                  <?php if (!empty($assignedStops)): ?>
                  <div class="mw-dv-section">
                      <div class="mw-dv-section-header">
                          <span class="mw-dv-section-title">Assigned</span>
                          <span class="mw-dv-section-count"><?php echo count($assignedStops); ?> stop<?php echo count($assignedStops) !== 1 ? 's' : ''; ?></span>
                      </div>
                      <?php foreach ($assignedStops as $stop):
                          $arrival = !empty($stop['estimated_arrival']) ? date('g:i A', strtotime($stop['estimated_arrival'])) : '';
                          $contactName = $dayContactMap[(int)$stop['property_id']] ?? ($stop['contact_name'] ?? '');
                          $crewNames = !empty($stop['crew_names']) ? $stop['crew_names'] : ($stop['crew_name'] ? [$stop['crew_name']] : []);
                          // Build visits JSON for modal
                          $dvVisitsJson = [];
                          if (!empty($stop['visits'])) {
                              foreach ($stop['visits'] as $v) {
                                  $dvVisitsJson[] = ['plan_id' => (int)($v['plan_id'] ?? 0), 'plan_number' => $v['plan_number'] ?? '', 'service_type' => $v['service_type'] ?? ''];
                              }
                          }
                      ?>
                      <div class="mw-dv-card <?php echo stopStatusClass($stop['stop_status'] ?? 'scheduled'); ?>"
                           data-stop-id="<?php echo (int)$stop['stop_id']; ?>"
                           data-stop-date="<?php echo htmlspecialchars($stop['stop_date']); ?>"
                           data-route-order="<?php echo (int)($stop['route_order'] ?? 0); ?>"
                           data-crew-id="<?php echo (int)($stop['crew_id'] ?? 0); ?>"
                           data-crew-ids="<?php echo htmlspecialchars(implode(',', $stop['crew_ids'] ?? [])); ?>"
                           data-property-address="<?php echo htmlspecialchars($stop['property_address'] ?? ''); ?>"
                           data-property-id="<?php echo (int)$stop['property_id']; ?>"
                           data-contact-id="<?php echo (int)($stop['contact_id'] ?? 0); ?>"
                           data-contact-name="<?php echo htmlspecialchars($contactName); ?>"
                           data-visits="<?php echo htmlspecialchars(json_encode($dvVisitsJson)); ?>"
                           data-lat="<?php echo htmlspecialchars($stop['latitude'] ?? ''); ?>"
                           data-lng="<?php echo htmlspecialchars($stop['longitude'] ?? ''); ?>">
                          <button type="button" class="mw-dv-pin-btn" data-stop-id="<?php echo (int)$stop['stop_id']; ?>" title="Pin as 1st stop">
                              <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><path d="M21 10c0 7-9 13-9 13s-9-6-9-13a9 9 0 0118 0z"/><circle cx="12" cy="10" r="3"/></svg>
                          </button>
                          <div class="mw-dv-card-body">
                              <div class="mw-dv-card-top">
                                  <?php if ($arrival): ?>
                                      <span class="mw-dv-card-time"><?php echo htmlspecialchars($arrival); ?></span>
                                  <?php endif; ?>
                                  <span class="mw-dv-card-client"><?php echo htmlspecialchars($contactName); ?></span>
                              </div>
                              <div class="mw-dv-card-address"><?php echo htmlspecialchars($stop['property_address'] ?? 'Unknown'); ?></div>
                              <?php if (!empty($stop['visits'])): ?>
                              <div class="mw-dv-card-services">
                                  <?php foreach ($stop['visits'] as $visit): ?>
                                      <span class="mw-visit-pill" style="border-left-color: <?php echo getServiceColorLocal($visit['service_type'] ?? ''); ?>">
                                          <?php echo htmlspecialchars(getServiceLabelLocal($visit['service_type'] ?? '')); ?>
                                      </span>
                                  <?php endforeach; ?>
                              </div>
                              <?php endif; ?>
                              <?php if (!empty($crewNames)): ?>
                                  <div class="mw-dv-card-crew"><?php echo htmlspecialchars(implode(', ', $crewNames)); ?></div>
                              <?php endif; ?>
                              <?php
                              // Profitability bar
                              $margins = [];
                              foreach (($stop['visits'] ?? []) as $v) {
                                  $pid = $v['plan_id'] ?? 0;
                                  if ($pid && !empty($profitabilityMap[$pid]) && $profitabilityMap[$pid]['has_data']) {
                                      $margins[] = (int)$profitabilityMap[$pid]['margin_pct'];
                                  }
                              }
                              if (!empty($margins)):
                                  $avgMargin = (int)round(array_sum($margins) / count($margins));
                                  $barColor = profitBarColor($avgMargin);
                              ?>
                              <div class="mw-profit-bar">
                                  <div class="mw-profit-bar-fill" style="width: <?php echo min(100, max(5, $avgMargin)); ?>%; background: <?php echo $barColor; ?>;"></div>
                              </div>
                              <?php endif; ?>
                          </div>
                      </div>
                      <?php endforeach; ?>
                  </div>
                  <?php endif; ?>

                  <?php if (!empty($unassignedStops)): ?>
                  <div class="mw-dv-section mw-dv-section-unassigned">
                      <div class="mw-dv-section-header">
                          <span class="mw-dv-section-title">Unassigned</span>
                          <span class="mw-dv-section-count"><?php echo count($unassignedStops); ?> stop<?php echo count($unassignedStops) !== 1 ? 's' : ''; ?></span>
                      </div>
                      <?php foreach ($unassignedStops as $stop):
                          $arrival = !empty($stop['estimated_arrival']) ? date('g:i A', strtotime($stop['estimated_arrival'])) : '';
                          $contactName = $dayContactMap[(int)$stop['property_id']] ?? ($stop['contact_name'] ?? '');
                          // Build visits JSON for modal
                          $unVisitsJson = [];
                          if (!empty($stop['visits'])) {
                              foreach ($stop['visits'] as $v) {
                                  $unVisitsJson[] = ['plan_id' => (int)($v['plan_id'] ?? 0), 'plan_number' => $v['plan_number'] ?? '', 'service_type' => $v['service_type'] ?? ''];
                              }
                          }
                      ?>
                      <div class="mw-dv-card mw-dv-card-unassigned <?php echo stopStatusClass($stop['stop_status'] ?? 'scheduled'); ?>"
                           data-stop-id="<?php echo (int)$stop['stop_id']; ?>"
                           data-stop-date="<?php echo htmlspecialchars($stop['stop_date']); ?>"
                           data-route-order="<?php echo (int)($stop['route_order'] ?? 0); ?>"
                           data-crew-id="0"
                           data-crew-ids=""
                           data-property-address="<?php echo htmlspecialchars($stop['property_address'] ?? ''); ?>"
                           data-property-id="<?php echo (int)$stop['property_id']; ?>"
                           data-contact-id="<?php echo (int)($stop['contact_id'] ?? 0); ?>"
                           data-contact-name="<?php echo htmlspecialchars($contactName); ?>"
                           data-visits="<?php echo htmlspecialchars(json_encode($unVisitsJson)); ?>"
                           data-lat="<?php echo htmlspecialchars($stop['latitude'] ?? ''); ?>"
                           data-lng="<?php echo htmlspecialchars($stop['longitude'] ?? ''); ?>">
                          <div class="mw-dv-card-body">
                              <div class="mw-dv-card-top">
                                  <?php if ($arrival): ?>
                                      <span class="mw-dv-card-time"><?php echo htmlspecialchars($arrival); ?></span>
                                  <?php endif; ?>
                                  <span class="mw-dv-card-client"><?php echo htmlspecialchars($contactName); ?></span>
                              </div>
                              <div class="mw-dv-card-address"><?php echo htmlspecialchars($stop['property_address'] ?? 'Unknown'); ?></div>
                              <?php if (!empty($stop['visits'])): ?>
                              <div class="mw-dv-card-services">
                                  <?php foreach ($stop['visits'] as $visit): ?>
                                      <span class="mw-visit-pill" style="border-left-color: <?php echo getServiceColorLocal($visit['service_type'] ?? ''); ?>">
                                          <?php echo htmlspecialchars(getServiceLabelLocal($visit['service_type'] ?? '')); ?>
                                      </span>
                                  <?php endforeach; ?>
                              </div>
                              <?php endif; ?>
                              <div class="mw-dv-card-crew" style="color: #adb5bd;">Unassigned</div>
                          </div>
                      </div>
                      <?php endforeach; ?>
                  </div>
                  <?php endif; ?>

                  <?php if (empty($assignedStops) && empty($unassignedStops)): ?>
                  <div class="mw-dv-empty">
                      <div class="mw-dv-empty-icon">&#128197;</div>
                      <div class="mw-dv-empty-text">No stops scheduled</div>
                      <div class="mw-dv-empty-sub">for <?php echo date('l, M j', strtotime($dayDate)); ?></div>
                  </div>
                  <?php endif; ?>
              </div>

              <!-- Right panel: embedded Google Map -->
              <div class="mw-dv-map-panel">
                  <div class="mw-dv-map-topbar">
                      <span class="mw-dv-map-title" id="mwDvMapTitle">Route</span>
                      <button type="button" class="mw-dv-map-external" id="mwDvMapExternal" title="Open in Google Maps">
                          <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M18 13v6a2 2 0 01-2 2H5a2 2 0 01-2-2V8a2 2 0 012-2h6"/><polyline points="15 3 21 3 21 9"/><line x1="10" y1="14" x2="21" y2="3"/></svg>
                      </button>
                  </div>
                  <div class="mw-dv-map" id="mwDvMap"></div>
              </div>
          </div>
          <?php endif; ?>

          <!-- ═══ Stop Detail / Crew Assignment Modal ═══ -->
          <div class="modal fade" id="crewAssignModal" tabindex="-1" role="dialog" aria-labelledby="crewAssignModalLabel" aria-hidden="true">
              <div class="modal-dialog modal-dialog-centered" role="document">
                  <div class="modal-content mw-cam-content">
                      <div class="mw-cam-header">
                          <div class="mw-cam-header-left">
                              <div class="mw-cam-client" id="crewAssignClient"></div>
                              <div class="mw-cam-address" id="crewAssignProperty"></div>
                          </div>
                          <div class="mw-cam-header-right">
                              <div class="mw-cam-date" id="crewAssignDate"></div>
                          </div>
                          <button type="button" class="close mw-cam-close" data-dismiss="modal" aria-label="Close">
                              <span aria-hidden="true">&times;</span>
                          </button>
                      </div>
                      <input type="hidden" id="crewAssignStopId">

                      <div class="mw-cam-body">
                          <!-- Service pills -->
                          <div class="mw-cam-services" id="crewAssignServices"></div>

                          <!-- Quick links -->
                          <div class="mw-cam-links" id="crewAssignLinks"></div>

                          <!-- Crew selection -->
                          <div class="mw-cam-crew-section">
                              <div class="mw-cam-crew-label">Assign Crew</div>
                              <div class="mw-crew-checklist" id="crewAssignChecklist">
                                  <?php foreach ($staff as $member): ?>
                                      <label class="mw-crew-check-item">
                                          <input type="checkbox" value="<?php echo (int)$member['id']; ?>"
                                                 data-name="<?php echo htmlspecialchars($member['full_name']); ?>">
                                          <span class="mw-crew-check-name"><?php echo htmlspecialchars($member['full_name']); ?></span>
                                      </label>
                                  <?php endforeach; ?>
                              </div>
                          </div>
                      </div>

                      <div class="mw-cam-footer">
                          <button type="button" class="btn btn-light" data-dismiss="modal">Cancel</button>
                          <button type="button" class="btn btn-primary" id="crewAssignSave">
                              <i data-feather="check" style="width:16px;height:16px;margin-right:4px;vertical-align:-2px;"></i>Save
                          </button>
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
                  <!-- Date nav: ‹ THU / Feb 20 › — tap date to open picker -->
                  <div class="mw-mc-topbar-left">
                      <a href="?view=day&date=<?php echo htmlspecialchars($mobilePrevDay) . $filterQueryStr; ?>"
                         class="mw-mc-date-arrow" aria-label="Previous day">&#8249;</a>
                      <button type="button"
                              class="mw-mc-date-pill<?php echo $mobileIsToday ? ' mw-mc-date-pill-today' : ''; ?>"
                              id="mwMobileDateBtn"
                              title="Pick a date">
                          <span class="mw-mc-date-pill-day"><?php echo htmlspecialchars($todayDayName); ?></span>
                          <span class="mw-mc-date-pill-date"><?php echo htmlspecialchars($todayDateDisplay); ?></span>
                      </button>
                      <!-- Date input injected into body by JS to avoid layout bleed -->
                      <a href="?view=day&date=<?php echo htmlspecialchars($mobileNextDay) . $filterQueryStr; ?>"
                         class="mw-mc-date-arrow" aria-label="Next day">&#8250;</a>
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
                  <a href="?<?php echo ltrim($filterQueryStr, '&'); ?>" class="mw-mc-bottombar-btn mw-mc-bottombar-btn-active">
                      <svg width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="3" y="4" width="18" height="18" rx="2" ry="2"/><line x1="16" y1="2" x2="16" y2="6"/><line x1="8" y1="2" x2="8" y2="6"/><line x1="3" y1="10" x2="21" y2="10"/></svg>
                      <span>Today</span>
                  </a>
                  <button type="button" class="mw-mc-bottombar-btn" id="mwRouteBtn" onclick="MwRouteMap.toggle()">
                      <svg width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polygon points="3 11 22 2 13 21 11 13 3 11"/></svg>
                      <span>Route</span>
                  </button>
                  <a href="/crm/expenses_appstack.php?mode=quick&return=schedule" class="mw-mc-bottombar-btn mw-mc-bottombar-btn-receipt">
                      <svg width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="2" y="3" width="20" height="18" rx="2"/><circle cx="12" cy="12" r="3"/><path d="M2 7h2m16 0h2M2 17h2m16 0h2"/></svg>
                      <span>Receipt</span>
                  </a>
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
 * Mobile date picker — tapping the date pill opens the native date input.
 * The input is created dynamically and appended to <body> to avoid any
 * layout bleed in desktop Chrome's simulated mobile viewport.
 * On change, navigate to ?view=day&date=YYYY-MM-DD preserving filters.
 */
(function() {
    var btn = document.getElementById('mwMobileDateBtn');
    if (!btn) return;

    var currentDate = '<?php echo htmlspecialchars($mobileDate); ?>';

    // Create the hidden input and attach to body (keeps it out of topbar layout)
    var input = document.createElement('input');
    input.type = 'date';
    input.value = currentDate;
    input.style.cssText = 'position:fixed;left:-9999px;top:-9999px;width:1px;height:1px;opacity:0;pointer-events:none;';
    document.body.appendChild(input);

    // When a date is picked, navigate preserving crew/service filters
    input.addEventListener('change', function() {
        var picked = input.value;
        if (!picked) return;
        var params = new URLSearchParams(window.location.search);
        params.set('view', 'day');
        params.set('date', picked);
        params.delete('start');
        window.location.search = params.toString();
    });

    // Tap/click on date pill → open native date picker
    btn.addEventListener('click', function(e) {
        e.preventDefault();
        if (typeof input.showPicker === 'function') {
            try { input.showPicker(); } catch(err) { input.focus(); }
        } else {
            input.focus();
        }
    });
})();

/**
 * Desktop Date Picker
 * Renders a custom calendar dropdown. Week-view picks navigate to
 * the Monday of the chosen week; day-view picks navigate to that day.
 */
(function () {
    var trigger  = document.getElementById('mwDpTrigger2');
    var popup    = document.getElementById('mwDpPopup2');
    var grid     = document.getElementById('mwDpGrid');
    var monthLbl = document.getElementById('mwDpMonthLabel');
    var prevBtn  = document.getElementById('mwDpPrevMonth');
    var nextBtn  = document.getElementById('mwDpNextMonth');
    var todayBtn = document.getElementById('mwDpTodayBtn');
    if (!trigger || !popup) return;

    // Move popup to <body> so no parent overflow/z-index clips it
    document.body.appendChild(popup);
    popup.style.position = 'fixed';

    var view    = trigger.dataset.view;            // 'day' | 'week'
    var current = trigger.dataset.current;         // YYYY-MM-DD (day date or week-start)
    var viewYear, viewMonth;                       // calendar display state

    var MONTHS = ['January','February','March','April','May','June',
                  'July','August','September','October','November','December'];

    // ── Helpers ────────────────────────────────────────────────────
    function parseDate(str) {
        var p = str.split('-'); return new Date(+p[0], +p[1]-1, +p[2]);
    }
    function toISO(d) {
        return d.getFullYear() + '-' +
               String(d.getMonth()+1).padStart(2,'0') + '-' +
               String(d.getDate()).padStart(2,'0');
    }
    function mondayOf(d) {
        var day = d.getDay() || 7;          // treat Sunday as 7
        var mon = new Date(d);
        mon.setDate(d.getDate() - (day - 1));
        return mon;
    }
    function isSameDay(a, b) {
        return a.getFullYear()===b.getFullYear() && a.getMonth()===b.getMonth() && a.getDate()===b.getDate();
    }

    // ── Render calendar grid ────────────────────────────────────────
    function render() {
        monthLbl.textContent = MONTHS[viewMonth] + ' ' + viewYear;
        grid.innerHTML = '';

        var today       = new Date();
        var selDate     = parseDate(current);
        var weekMonday  = view === 'week' ? selDate : mondayOf(selDate);
        var weekSunday  = new Date(weekMonday); weekSunday.setDate(weekMonday.getDate() + 6);

        // First Monday on or before the 1st of the month
        var first = new Date(viewYear, viewMonth, 1);
        var firstMon = mondayOf(first);

        var last = new Date(viewYear, viewMonth + 1, 0); // last calendar day of month
        var cur  = new Date(firstMon);
        for (var iter = 0; iter < 42; iter++) {          // max 6 weeks, guards against infinite loop
            var cell = document.createElement('button');
            cell.type = 'button';
            cell.textContent = cur.getDate();
            cell.className = 'mw-dp-cell';
            var cellIso = toISO(cur);
            cell.dataset.iso = cellIso;

            var inMonth = (cur.getMonth() === viewMonth && cur.getFullYear() === viewYear);
            if (!inMonth)              cell.classList.add('mw-dp-cell-other');
            if (isSameDay(cur, today)) cell.classList.add('mw-dp-cell-today');

            if (view === 'week') {
                var inSel = (cur >= weekMonday && cur <= weekSunday);
                if (inSel) cell.classList.add('mw-dp-cell-in-week');
                if (isSameDay(cur, weekMonday)) cell.classList.add('mw-dp-cell-week-start');
                if (isSameDay(cur, weekSunday)) cell.classList.add('mw-dp-cell-week-end');
            } else {
                if (isSameDay(cur, selDate)) cell.classList.add('mw-dp-cell-selected');
            }

            cell.addEventListener('click', function () { pick(this.dataset.iso); });
            grid.appendChild(cell);

            // Advance, then stop once we've passed month-end and completed the week (Mon=1)
            cur.setDate(cur.getDate() + 1);
            if (cur > last && cur.getDay() === 1) break;
        }
    }

    // ── Navigate ────────────────────────────────────────────────────
    function pick(iso) {
        var params = new URLSearchParams(window.location.search);
        if (view === 'week') {
            var mon = mondayOf(parseDate(iso));
            params.set('start', toISO(mon));
            params.delete('view'); params.delete('date');
        } else {
            params.set('view', 'day');
            params.set('date', iso);
            params.delete('start');
        }
        window.location.search = params.toString();
    }

    // ── Open / close ────────────────────────────────────────────────
    function positionPopup() {
        var rect = trigger.getBoundingClientRect();
        var popupW = 300;
        // Centre under trigger, clamped to viewport
        var left = rect.left + (rect.width / 2) - (popupW / 2);
        left = Math.max(8, Math.min(left, window.innerWidth - popupW - 8));
        popup.style.left = left + 'px';
        popup.style.top  = (rect.bottom + 8) + 'px';
        // Remove the CSS transform that was for static positioning
        popup.style.transform = 'none';
    }
    function open() {
        var d = parseDate(current);
        viewYear  = d.getFullYear();
        viewMonth = d.getMonth();
        render();
        positionPopup();
        popup.hidden = false;
        trigger.setAttribute('aria-expanded', 'true');
        trigger.classList.add('mw-datepicker-open');
    }
    function close() {
        popup.hidden = true;
        trigger.setAttribute('aria-expanded', 'false');
        trigger.classList.remove('mw-datepicker-open');
    }

    trigger.addEventListener('click', function (e) {
        e.stopPropagation();
        popup.hidden ? open() : close();
    });

    prevBtn.addEventListener('click', function () {
        viewMonth--;
        if (viewMonth < 0) { viewMonth = 11; viewYear--; }
        render();
    });
    nextBtn.addEventListener('click', function () {
        viewMonth++;
        if (viewMonth > 11) { viewMonth = 0; viewYear++; }
        render();
    });
    todayBtn.addEventListener('click', function () { pick(toISO(new Date())); });

    // Close on outside click / Escape
    document.addEventListener('click', function (e) {
        if (!popup.hidden && !popup.contains(e.target) && e.target !== trigger) close();
    });
    document.addEventListener('keydown', function (e) {
        if (e.key === 'Escape' && !popup.hidden) close();
    });
})();

/**
 * Apply filters: navigate with crew + service params
 */
function applyFilter() {
    var params = new URLSearchParams(window.location.search);
    var crewId = document.getElementById('crewFilter').value;
    var serviceType = document.getElementById('serviceFilter').value;
    if (crewId) { params.set('crew', crewId); } else { params.delete('crew'); }
    if (serviceType) { params.set('service', serviceType); } else { params.delete('service'); }
    window.location.search = params.toString();
}

/**
 * Crew assignment modal — open on stop card click (desktop only)
 * Uses mousedown/mouseup distance to distinguish clicks from drags.
 * Supports multi-crew selection via checkboxes.
 */
// Service color/label maps (mirrored from PHP)
var serviceColorMap = <?php echo json_encode($serviceColors); ?>;
var serviceLabelMap = <?php echo json_encode($serviceLabels); ?>;

function getServiceColor(type) {
    return serviceColorMap[type] || '#6B7280';
}
function getServiceLabel(type) {
    if (serviceLabelMap[type]) return serviceLabelMap[type];
    return type.replace(/_/g, ' ').replace(/\b\w/g, function(c) { return c.toUpperCase(); });
}

(function() {
    // Only attach on desktop where the calendar grid is visible
    if (window.innerWidth <= 991) return;

    var downX = 0, downY = 0;

    document.querySelectorAll('.mw-stop-card, .mw-dv-card').forEach(function(card) {
        card.addEventListener('mousedown', function(e) {
            // Don't track pin button clicks as modal openers
            if (e.target.closest('.mw-dv-pin-btn')) return;
            downX = e.clientX;
            downY = e.clientY;
        });

        card.addEventListener('mouseup', function(e) {
            // Don't open modal on pin button clicks
            if (e.target.closest('.mw-dv-pin-btn')) return;
            // If mouse moved more than 5px, it was a drag — don't open modal
            var dx = Math.abs(e.clientX - downX);
            var dy = Math.abs(e.clientY - downY);
            if (dx > 5 || dy > 5) return;

            var stopId = card.dataset.stopId;
            var crewIds = (card.dataset.crewIds || '').split(',').filter(function(v) { return v !== ''; });
            var address = card.dataset.propertyAddress || 'Unknown';
            var stopDate = card.dataset.stopDate || '';
            var contactName = card.dataset.contactName || '';
            var contactId = parseInt(card.dataset.contactId || '0', 10);
            var propertyId = parseInt(card.dataset.propertyId || '0', 10);
            var visits = [];
            try { visits = JSON.parse(card.dataset.visits || '[]'); } catch(e) {}

            // Format date for display
            var dateDisplay = stopDate;
            if (stopDate) {
                var d = new Date(stopDate + 'T12:00:00');
                dateDisplay = d.toLocaleDateString('en-US', {
                    weekday: 'long', month: 'short', day: 'numeric'
                });
            }

            document.getElementById('crewAssignStopId').value = stopId;
            document.getElementById('crewAssignClient').textContent = contactName || 'Unknown Client';
            document.getElementById('crewAssignProperty').textContent = address;
            document.getElementById('crewAssignDate').textContent = dateDisplay;

            // Render service pills
            var servicesEl = document.getElementById('crewAssignServices');
            servicesEl.innerHTML = '';
            var seenServices = {};
            visits.forEach(function(v) {
                if (!v.service_type || seenServices[v.service_type]) return;
                seenServices[v.service_type] = true;
                var pill = document.createElement('span');
                pill.className = 'mw-cam-service-pill';
                pill.style.borderLeftColor = getServiceColor(v.service_type);
                pill.textContent = getServiceLabel(v.service_type);
                servicesEl.appendChild(pill);
            });

            // Build quick links
            var linksEl = document.getElementById('crewAssignLinks');
            linksEl.innerHTML = '';

            // Link to plan(s)
            var seenPlans = {};
            visits.forEach(function(v) {
                if (!v.plan_id || seenPlans[v.plan_id]) return;
                seenPlans[v.plan_id] = true;
                var a = document.createElement('a');
                a.href = '/crm/jobs/view.php?id=' + v.plan_id;
                a.className = 'mw-cam-link';
                a.innerHTML = '<svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><polyline points="14 2 14 8 20 8"/><line x1="16" y1="13" x2="8" y2="13"/><line x1="16" y1="17" x2="8" y2="17"/><polyline points="10 9 9 9 8 9"/></svg>' +
                    '<span>View Plan' + (v.plan_number ? ' ' + v.plan_number : '') + '</span>';
                a.target = '_blank';
                linksEl.appendChild(a);
            });

            // Link to client
            if (contactId > 0) {
                var a = document.createElement('a');
                a.href = '/crm/clients_appstack.php?action=view_contact&id=' + contactId;
                a.className = 'mw-cam-link';
                a.innerHTML = '<svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M20 21v-2a4 4 0 0 0-4-4H8a4 4 0 0 0-4 4v2"/><circle cx="12" cy="7" r="4"/></svg>' +
                    '<span>View Client</span>';
                a.target = '_blank';
                linksEl.appendChild(a);
            }

            // Check the right checkboxes
            var checklist = document.getElementById('crewAssignChecklist');
            checklist.querySelectorAll('input[type="checkbox"]').forEach(function(cb) {
                cb.checked = crewIds.indexOf(cb.value) !== -1;
            });

            $('#crewAssignModal').modal('show');
            // Re-render feather icons in modal
            if (typeof feather !== 'undefined') feather.replace();
        });
    });

    // Save button handler
    document.getElementById('crewAssignSave').addEventListener('click', function() {
        var btn = this;
        var stopId = document.getElementById('crewAssignStopId').value;

        // Gather checked crew IDs
        var crewIds = [];
        document.querySelectorAll('#crewAssignChecklist input[type="checkbox"]:checked').forEach(function(cb) {
            crewIds.push(parseInt(cb.value, 10));
        });

        btn.disabled = true;
        btn.textContent = 'Saving...';

        fetch('/crm/api/assign-crew.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({
                stop_id: parseInt(stopId, 10),
                crew_ids: crewIds
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
            var card = document.querySelector('.mw-stop-card[data-stop-id="' + stopId + '"], .mw-dv-card[data-stop-id="' + stopId + '"]');
            if (card) {
                var returnedIds = data.crew_ids || [];
                var returnedNames = data.crew_names || [];
                card.dataset.crewId = returnedIds.length > 0 ? returnedIds[0] : '0';
                card.dataset.crewIds = returnedIds.join(',');

                var crewEl = card.querySelector('.mw-stop-crew, .mw-dv-card-crew');
                if (returnedNames.length > 0) {
                    var displayText = returnedNames.join(', ');
                    if (crewEl) {
                        crewEl.textContent = displayText;
                        crewEl.style.color = '';
                    } else {
                        var newCrew = document.createElement('div');
                        newCrew.className = card.classList.contains('mw-dv-card') ? 'mw-dv-card-crew' : 'mw-stop-crew';
                        newCrew.textContent = displayText;
                        var body = card.querySelector('.mw-dv-card-body') || card;
                        body.appendChild(newCrew);
                    }
                } else {
                    if (crewEl) {
                        crewEl.textContent = 'Unassigned';
                        crewEl.style.color = '#adb5bd';
                    }
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
            // Don't toggle if clicking an interactive element inside the expanded detail
            // (placeholders, + button, pills, drawer controls, links, route buttons)
            // Photo strips are now inside expand-detail so they're safe to exclude from bail-out,
            // but we still need to let placeholder taps through without collapsing
            if (e.target.closest('.mw-mc-action-btn') || e.target.closest('a') ||
                e.target.closest('.mw-mc-pill-interactive') || e.target.closest('.mw-mc-pill-drawer') ||
                e.target.closest('.mw-mc-drawer-btn') || e.target.closest('.mw-mc-drawer-camera-btn') ||
                e.target.closest('.mw-mc-drawer-skip') ||
                e.target.closest('.mw-mc-photo-placeholder') ||
                e.target.closest('.mw-mc-add-photo-btn') ||
                e.target.closest('.mw-mc-photo-thumb')) return;

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
                // Trigger photo strip render so placeholders appear immediately on expand
                if (typeof MwPillWorkflow !== 'undefined' && MwPillWorkflow.renderStripsForCard) {
                    MwPillWorkflow.renderStripsForCard(card);
                }
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

    // ── Re-check on app resume (returning from Google Maps / another app) ──
    // visibilitychange fires when the tab/PWA regains focus after switching apps.
    // maximumAge: 0 forces a fresh GPS fix so we always have current position.
    document.addEventListener('visibilitychange', function() {
        if (document.visibilityState === 'visible') {
            navigator.geolocation.getCurrentPosition(
                promoteToHero,
                function() { /* silent fail */ },
                { enableHighAccuracy: true, timeout: 8000, maximumAge: 0 }
            );
        }
    });

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
<script src="../js/navigation-launcher.js?v=20260225c"></script>
<script src="../js/route-engine.js?v=20260219a"></script>
<script src="../js/schedule-route-map.js?v=20260226b"></script>
<script src="../js/schedule-pill-workflow.js?v=20260226b"></script>
<script src="../js/schedule-drag-drop.js"></script>
<?php if ($view === 'day'): ?>
<script>
var MW_DAY_VIEW_STOPS = <?php echo json_encode($dayViewMapStops); ?>;
</script>
<script src="../js/schedule-day-map.js?v=20260217c"></script>
<?php endif; ?>
<?php if ($view === 'week'): ?>
<script>
// Day header click → navigate to day view
document.querySelectorAll('.mw-calendar-date-cell').forEach(function(cell) {
    cell.style.cursor = 'pointer';
    cell.addEventListener('click', function() {
        var date = cell.dataset.date;
        if (date) {
            var params = new URLSearchParams(window.location.search);
            params.set('view', 'day');
            params.set('date', date);
            params.delete('start');
            window.location.search = params.toString();
        }
    });
});
</script>
<?php endif; ?>

<!-- ═══════════════════════════════════════════════════════
     MISSION CONTROL — Animations & Gamification JS
     ═══════════════════════════════════════════════════════ -->
<script>
(function() {
    'use strict';

    // ── 1. Progress bar entrance animation ──────────────────────────
    // Bars start at 0 width and animate to their data-target on load
    function animateProgressBars() {
        var fills = document.querySelectorAll('.mw-mc-prog-fill');
        fills.forEach(function(el) {
            var target = parseInt(el.dataset.target || '0', 10);
            el.style.width = '0%';
            requestAnimationFrame(function() {
                setTimeout(function() {
                    el.style.width = Math.min(100, target) + '%';
                }, 120);
            });
        });
    }

    // ── 2. KPI counter roll-up animation ────────────────────────────
    function animateKpiValues() {
        var kpis = document.querySelectorAll('.mw-mc-kpi-value');
        kpis.forEach(function(el) {
            var text = el.textContent.trim();
            // Only animate pure numeric or numeric-with-% values
            var numeric = text.replace(/[$,%]/g, '');
            if (isNaN(parseFloat(numeric))) return;
            var target = parseFloat(numeric);
            var prefix = text.indexOf('$') !== -1 ? '$' : '';
            var suffix = text.indexOf('%') !== -1 ? '%' : '';
            var start = 0;
            var duration = 800;
            var startTime = null;
            function step(ts) {
                if (!startTime) startTime = ts;
                var progress = Math.min((ts - startTime) / duration, 1);
                var ease = 1 - Math.pow(1 - progress, 3); // ease-out cubic
                var val = start + (target - start) * ease;
                el.textContent = prefix + (target >= 100 ? Math.round(val).toLocaleString() : Math.round(val)) + suffix;
                if (progress < 1) requestAnimationFrame(step);
            }
            // Slight stagger per element
            var idx = Array.prototype.indexOf.call(kpis, el);
            setTimeout(function() { requestAnimationFrame(step); }, 60 * idx);
        });
    }

    // ── 3. Battle Card density heatmap ──────────────────────────────
    // Each .mw-battle-card has a --bc-heat CSS var set inline by PHP.
    // JS applies the pseudo-background tint using a radial gradient overlay.
    function applyHeatmaps() {
        var cards = document.querySelectorAll('.mw-battle-card');
        cards.forEach(function(card) {
            var alpha = parseFloat(card.style.getPropertyValue('--bc-heat') || '0');
            if (alpha > 0) {
                // Add a subtle radial glow from the top — density warmth indicator
                card.style.backgroundImage =
                    'radial-gradient(ellipse 120% 60% at 50% 0%, rgba(45,134,89,' + alpha + ') 0%, transparent 70%)';
            }
        });
    }

    // ── 4. Live efficiency score update on drag-drop reorder ─────────
    // After the drag-drop engine fires a 'mw:route-updated' event,
    // we recalculate drive time and efficiency and animate the values.
    function refreshEfficiencyDisplay(newEfficiency) {
        var valEl   = document.getElementById('mc-efficiency-val');
        var barEl   = document.getElementById('mc-eff-bar');
        var labelEl = document.getElementById('mc-eff-label');
        if (!valEl || !barEl || !labelEl) return;

        var prev = parseInt(valEl.textContent, 10) || 0;
        var duration = 600;
        var startTime = null;
        function step(ts) {
            if (!startTime) startTime = ts;
            var p = Math.min((ts - startTime) / duration, 1);
            var ease = 1 - Math.pow(1 - p, 3);
            var cur = Math.round(prev + (newEfficiency - prev) * ease);
            valEl.textContent = cur;
            labelEl.textContent = cur + '/100';
            barEl.style.width = Math.min(100, cur) + '%';
            if (p < 1) requestAnimationFrame(step);
        }
        requestAnimationFrame(step);
    }

    // Listen for route-engine updates
    document.addEventListener('mw:route-updated', function(e) {
        if (e.detail && typeof e.detail.efficiency === 'number') {
            refreshEfficiencyDisplay(e.detail.efficiency);
        }
    });

    // ── 5. Confetti on 100% weekly completion ───────────────────────
    // Fires once per session if mcProgressPct === 100
    var MC_PROGRESS = <?php echo (int)$mcProgressPct; ?>;
    var MC_CONFETTI_KEY = 'mw_confetti_<?php echo date('Y-W'); ?>';

    function launchConfetti() {
        if (sessionStorage.getItem(MC_CONFETTI_KEY)) return;
        sessionStorage.setItem(MC_CONFETTI_KEY, '1');

        var canvas = document.createElement('canvas');
        canvas.id = 'mw-confetti-canvas';
        canvas.style.cssText = 'position:fixed;top:0;left:0;width:100%;height:100%;pointer-events:none;z-index:9999';
        document.body.appendChild(canvas);
        var ctx = canvas.getContext('2d');
        canvas.width  = window.innerWidth;
        canvas.height = window.innerHeight;

        var particles = [];
        var colors = ['#2D8659','#7FD858','#F59E0B','#3B82F6','#EC4899','#fff'];
        for (var i = 0; i < 160; i++) {
            particles.push({
                x: Math.random() * canvas.width,
                y: -20 - Math.random() * 120,
                vx: (Math.random() - 0.5) * 4,
                vy: 2 + Math.random() * 4,
                size: 4 + Math.random() * 6,
                color: colors[Math.floor(Math.random() * colors.length)],
                rot: Math.random() * 360,
                rSpeed: (Math.random() - 0.5) * 6,
                alpha: 1,
            });
        }

        var start = null;
        function draw(ts) {
            if (!start) start = ts;
            var elapsed = ts - start;
            ctx.clearRect(0, 0, canvas.width, canvas.height);
            particles.forEach(function(p) {
                p.x += p.vx;
                p.y += p.vy;
                p.rot += p.rSpeed;
                p.vy += 0.05; // gravity
                if (elapsed > 2000) p.alpha = Math.max(0, 1 - (elapsed - 2000) / 1500);
                ctx.save();
                ctx.globalAlpha = p.alpha;
                ctx.translate(p.x, p.y);
                ctx.rotate(p.rot * Math.PI / 180);
                ctx.fillStyle = p.color;
                ctx.fillRect(-p.size / 2, -p.size / 2, p.size, p.size * 0.5);
                ctx.restore();
            });
            if (elapsed < 4000) {
                requestAnimationFrame(draw);
            } else {
                canvas.remove();
            }
        }
        requestAnimationFrame(draw);
    }

    // ── 6. Route optimization animation ─────────────────────────────
    // When stop cards are reordered via drag-drop, animate cards
    // sliding into their new positions using Web Animations API.
    // We hook into the schedule-drag-drop.js completion callback via
    // a custom event that the drag engine already fires.
    document.addEventListener('mw:stops-reordered', function() {
        var columns = document.querySelectorAll('.mw-day-column');
        columns.forEach(function(col) {
            var cards = Array.from(col.querySelectorAll('.mw-stop-card'));
            cards.forEach(function(card, i) {
                card.animate(
                    [{ opacity: 0.5, transform: 'translateY(-4px)' }, { opacity: 1, transform: 'translateY(0)' }],
                    { duration: 280, delay: i * 40, easing: 'ease-out', fill: 'backwards' }
                );
            });
        });

        // Recalculate and animate drive-time savings display
        var totalStops = document.querySelectorAll('.mw-stop-card').length;
        var estDriveMin = Math.max(0, totalStops - 7) * 7; // optimized route slightly better
        var estEff = Math.min(100, Math.round(
            document.querySelectorAll('.mw-stop-card').length > 0
                ? (<?php echo (int)$mcWeekDuration; ?> / (<?php echo (int)$mcWeekDuration; ?> + estDriveMin)) * 100
                : <?php echo (int)$mcEfficiency; ?>
        ));
        refreshEfficiencyDisplay(estEff);
    });

    // ── Init ─────────────────────────────────────────────────────────
    document.addEventListener('DOMContentLoaded', function() {
        animateProgressBars();
        animateKpiValues();
        applyHeatmaps();
        if (MC_PROGRESS >= 100) {
            setTimeout(launchConfetti, 600);
        }
    });

})();
</script>

<?php if ($unscheduledCount > 0): ?>
<script>
(function() {
    'use strict';

    var CSRF    = <?php echo json_encode($csrfToken); ?>;
    var IS_CREW = <?php echo json_encode($isCrew); ?>;

    var stopGrid   = document.getElementById('mwStopGrid');
    var trayToggle  = document.getElementById('mwTrayToggle');
    var calContainer = document.querySelector('.mw-calendar-container');

    // ── Tray collapse / expand ────────────────────────────────────────────────
    // Toggle mw-tray-collapsed on BOTH the stop grid AND the calendar container
    // so that all three header rows (day-names, dates, stop-grid) stay in sync.
    if (trayToggle && stopGrid) {
        function applyTrayCollapse(collapsed) {
            stopGrid.classList.toggle('mw-tray-collapsed', collapsed);
            if (calContainer) calContainer.classList.toggle('mw-tray-collapsed', collapsed);
        }

        // Restore saved state
        if (localStorage.getItem('mw_tray_collapsed') === '1') {
            applyTrayCollapse(true);
        }

        trayToggle.addEventListener('click', function () {
            var isNowCollapsed = stopGrid.classList.toggle('mw-tray-collapsed');
            if (calContainer) calContainer.classList.toggle('mw-tray-collapsed', isNowCollapsed);
            localStorage.setItem('mw_tray_collapsed', isNowCollapsed ? '1' : '0');
        });
    }

    // ── Placement Intelligence ────────────────────────────────────────────────
    // When a tray card is hovered or clicked, show fit scores on each day column.
    // Scores are pre-computed server-side and embedded as data-placement-scores JSON.
    // 14-day biweekly clients show cycle-alignment notes (On cycle / Off cycle).

    var _piActiveCard = null; // currently selected tray card

    var PI_TIER_MAP = {
        'Best':      'pi-best',
        'Good':      'pi-good',
        'OK':        'pi-ok',
        'Poor':      'pi-poor',
        'Off cycle': 'pi-off-cycle',
        'Full':      'pi-full',
    };

    function activatePlacementIntelligence(card) {
        if (_piActiveCard === card) return; // already active
        clearPlacementIntelligence();
        _piActiveCard = card;
        card.classList.add('mw-tray-card--placement-active');

        var scores = {};
        try { scores = JSON.parse(card.dataset.placementScores || '{}'); } catch(e) {}
        var isBiweekly = card.dataset.isBiweekly === '1';

        document.querySelectorAll('.mw-day-column').forEach(function(col) {
            var dateStr = col.dataset.date;
            if (!dateStr || !scores[dateStr]) return;

            var s = scores[dateStr];
            var score = s.score || 0;
            var label = s.label || 'Poor';
            var cycleNote = s.cycle_note || '';
            var tierClass = PI_TIER_MAP[label] || 'pi-poor';

            // Apply tier class to day column for glow
            var colTier = (label === 'Best') ? 'pi-col-best'
                        : (label === 'Good') ? 'pi-col-good'
                        : (label === 'Poor' || label === 'Off cycle' || label === 'Full') ? 'pi-col-poor'
                        : '';
            col.classList.add('mw-pi-active');
            if (colTier) col.classList.add(colTier);

            // Update the strip
            var strip = col.querySelector('.mw-pi-strip[data-date="' + dateStr + '"]');
            if (!strip) return;

            // Clear previous tier classes
            strip.className = 'mw-pi-strip ' + tierClass;

            var fill = strip.querySelector('.mw-pi-bar-fill');
            var scoreEl = strip.querySelector('.mw-pi-score');
            var labelEl = strip.querySelector('.mw-pi-label');
            var cycleEl = strip.querySelector('.mw-pi-cycle-note');

            if (fill)   { fill.style.width = '0%'; setTimeout(function(){ fill.style.width = score + '%'; }, 16); }
            if (scoreEl) scoreEl.textContent = score;
            if (labelEl) labelEl.textContent = label;
            if (cycleEl) {
                cycleEl.textContent = isBiweekly ? cycleNote : '';
                cycleEl.className = 'mw-pi-cycle-note';
                if (cycleNote === 'On cycle ✓') cycleEl.classList.add('cycle-on');
                else if (cycleNote === 'Off cycle') cycleEl.classList.add('cycle-off');
                else if (cycleNote === 'Near cycle') cycleEl.classList.add('cycle-near');
            }
        });
    }

    function clearPlacementIntelligence() {
        if (_piActiveCard) {
            _piActiveCard.classList.remove('mw-tray-card--placement-active');
            _piActiveCard = null;
        }
        document.querySelectorAll('.mw-day-column').forEach(function(col) {
            col.classList.remove('mw-pi-active', 'pi-col-best', 'pi-col-good', 'pi-col-poor');
            var strip = col.querySelector('.mw-pi-strip');
            if (strip) {
                strip.className = 'mw-pi-strip';
                var fill = strip.querySelector('.mw-pi-bar-fill');
                if (fill) fill.style.width = '0%';
            }
        });
    }

    // Hover shows intelligence; move away from tray body clears it
    document.querySelectorAll('.mw-tray-card').forEach(function(card) {
        card.addEventListener('mouseenter', function() {
            activatePlacementIntelligence(card);
        });
        // Click toggles sticky selection (stays visible until another card is picked or Escape)
        card.addEventListener('click', function(e) {
            if (_piActiveCard === card) {
                clearPlacementIntelligence();
            } else {
                activatePlacementIntelligence(card);
            }
        });
    });

    // Hovering the tray body area clears if nothing specific is hovered
    var trayBody2 = document.getElementById('mwTrayBody');
    if (trayBody2) {
        trayBody2.addEventListener('mouseleave', function() {
            // Only clear if no sticky selection
            if (_piActiveCard && !_piActiveCard.matches(':hover')) {
                clearPlacementIntelligence();
            }
        });
    }

    // Escape key clears
    document.addEventListener('keydown', function(e) {
        if (e.key === 'Escape') clearPlacementIntelligence();
    });

    // ── Tray card drag ────────────────────────────────────────────────────────
    document.querySelectorAll('.mw-tray-card').forEach(function (card) {
        card.addEventListener('dragstart', function (e) {
            e.dataTransfer.effectAllowed = 'move';
            e.dataTransfer.setData('text/x-tray-visit', card.dataset.visitId);
            card.classList.add('mw-tray-card--dragging');
            // Activate placement intelligence while dragging so scores stay visible
            activatePlacementIntelligence(card);
            // Highlight day columns as valid drop zones
            document.querySelectorAll('.mw-day-column').forEach(function (col) {
                col.classList.add('mw-tray-drop-target');
            });
        });

        card.addEventListener('dragend', function () {
            card.classList.remove('mw-tray-card--dragging');
            document.querySelectorAll('.mw-day-column').forEach(function (col) {
                col.classList.remove('mw-tray-drop-target');
                col.classList.remove('mw-tray-drop-over');
            });
            clearPlacementIntelligence();
        });
    });

    // ── Day column drop acceptance (tray visits) ──────────────────────────────
    document.querySelectorAll('.mw-day-column').forEach(function (col) {
        col.addEventListener('dragover', function (e) {
            if (e.dataTransfer.types.indexOf('text/x-tray-visit') !== -1) {
                e.preventDefault();
                e.dataTransfer.dropEffect = 'move';
                col.classList.add('mw-tray-drop-over');
            }
        });

        col.addEventListener('dragleave', function (e) {
            // Only remove highlight when truly leaving the column
            if (!col.contains(e.relatedTarget)) {
                col.classList.remove('mw-tray-drop-over');
            }
        });

        col.addEventListener('drop', function (e) {
            var visitId = e.dataTransfer.getData('text/x-tray-visit');
            if (!visitId) return; // handled by the existing stop-card drag-drop
            e.preventDefault();
            e.stopPropagation();
            col.classList.remove('mw-tray-drop-over');

            var date       = col.dataset.date;
            var routeOrder = col.querySelectorAll('.mw-stop-card').length;
            scheduleTrayVisit(parseInt(visitId, 10), date, routeOrder);
        });
    });

    // ── Perform schedule API call ─────────────────────────────────────────────
    function scheduleTrayVisit(visitId, date, routeOrder) {
        // Optimistically dim the tray card
        var card = document.querySelector('.mw-tray-card[data-visit-id="' + visitId + '"]');
        if (card) {
            card.style.opacity = '0.4';
            card.style.pointerEvents = 'none';
        }

        fetch('/crm/api/schedule-visit.php', {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
                'X-CSRF-Token': CSRF
            },
            body: JSON.stringify({
                visit_id:         visitId,
                date:             date,
                route_order:      routeOrder,
                auto_assign_self: IS_CREW
            })
        })
        .then(function (r) { return r.json(); })
        .then(function (data) {
            if (data.success) {
                // Remove the card from the tray
                if (card) card.remove();

                // Update count badge
                var remaining = document.querySelectorAll('.mw-tray-card').length;
                var badge = document.querySelector('.mw-tray-count');
                if (badge) badge.textContent = remaining;

                // If tray is now empty, show the "all done" state
                var trayBody = document.getElementById('mwTrayBody');
                if (trayBody && remaining === 0) {
                    trayBody.innerHTML = '<div class="mw-tray-empty">All jobs scheduled &#10003;</div>';
                }

                // Reload to show the new stop card in the day column
                window.location.reload();
            } else {
                if (card) { card.style.opacity = ''; card.style.pointerEvents = ''; }
                alert('Could not schedule job: ' + (data.error || 'Unknown error'));
            }
        })
        .catch(function () {
            if (card) { card.style.opacity = ''; card.style.pointerEvents = ''; }
            alert('Network error — please try again.');
        });
    }

})();
</script>
<?php endif; ?>

<?php include dirname(__DIR__) . '/includes/appstack_footer.php'; ?>

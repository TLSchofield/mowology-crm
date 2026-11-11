<?php
/**
 * Part of the Job Plan / Visit / Calendar Stop function library.
 * computeDayBattleCard — single-date battle-card + feasibility-verdict
 * computation, extracted from schedule.php so both the page's weekly render
 * loop and reschedule-stop.php (to refresh a day's card after a drag,
 * without a full page reload) can call it for one date at a time.
 *
 * Loaded via app/Modules/Jobs/Services/PlanFunctions.php (aggregator).
 */

/**
 * @return array{
 *   revenue: float, margin: ?int, density: int, weather_risk: string, outlier: bool,
 *   drive_min: int, stops: int, duration_min: int, has_crew_overlap: bool,
 *   verdict: string, issues: string[], signals: array, load_pct: int
 * }
 */
function computeDayBattleCard(string $date): array {
    $laborRate = 25.00; // $/hr fallback when user hourly_rate is unknown

    $dayStops = getCalendarStops($date, $date)[$date] ?? [];

    // ── Revenue / labor / duration ──────────────────────────────────────
    $dayRev = 0.0;
    $dayLabor = 0.0;
    $dayDuration = 0;
    $planIds = [];
    foreach ($dayStops as $stop) {
        foreach (($stop['visits'] ?? []) as $v) {
            $price = (float)($v['price_per_visit'] ?? 0);
            $dur   = (int)($v['estimated_duration'] ?? 0);
            $dayRev      += $price;
            $dayDuration += $dur;
            $dayLabor    += ($dur / 60.0) * $laborRate;
            if (!empty($v['plan_id'])) $planIds[] = (int)$v['plan_id'];
        }
    }
    $planIds = array_values(array_unique($planIds));
    $profitabilityMap = !empty($planIds) ? getStopProfitabilityBatch($planIds) : [];

    $dOh = $dayLabor * 0.30;
    $dCost = $dayLabor + $dOh;
    $margin  = $dayRev > 0 ? (int)round((($dayRev - $dCost) / $dayRev) * 100) : null;
    $density = (int)round(min(100, (count($dayStops) / 6.0) * 100));
    $driveMin = count($dayStops) > 0 ? (max(0, count($dayStops) - 1) * 8 + 10) : 0;

    // ── Weather risk ─────────────────────────────────────────────────────
    $weekForecast = getWeekForecast('Vancouver', 'BC');
    $weather = $weekForecast[$date] ?? [
        'temp_high' => 12, 'temp_low' => 8, 'condition' => 'Unknown', 'precipitation' => 0, 'wind' => 0,
    ];
    $condLow = strtolower($weather['condition'] ?? '');
    $precip  = (float)($weather['precipitation'] ?? 0);
    $wind    = (float)($weather['wind'] ?? 0);
    if (strpos($condLow, 'storm') !== false || strpos($condLow, 'snow') !== false || $precip > 10) {
        $weatherRisk = 'high';
    } elseif ($precip > 3 || $wind > 30) {
        $weatherRisk = 'medium';
    } else {
        $weatherRisk = 'low';
    }

    // ── Outlier: any stop with < 20% margin or missing profitability data ──
    $outlier = false;
    foreach ($dayStops as $stop) {
        foreach (($stop['visits'] ?? []) as $v) {
            $pid = (int)($v['plan_id'] ?? 0);
            if ($pid && isset($profitabilityMap[$pid])) {
                if ($profitabilityMap[$pid]['has_data'] && $profitabilityMap[$pid]['margin_pct'] < 20) {
                    $outlier = true;
                }
            }
        }
    }

    // ── Crew overlap ─────────────────────────────────────────────────────
    $crewCounts = [];
    foreach ($dayStops as $stop) {
        $ids = !empty($stop['crew_ids']) ? $stop['crew_ids'] : ($stop['crew_id'] ? [(int)$stop['crew_id']] : []);
        foreach ($ids as $cid) {
            if ($cid > 0) $crewCounts[$cid] = ($crewCounts[$cid] ?? 0) + 1;
        }
    }
    $hasCrewOverlap = false;
    foreach ($crewCounts as $cnt) {
        if ($cnt > 1) { $hasCrewOverlap = true; break; }
    }

    // ── Feasibility verdict (4 signals: crew / load / weather / weather-blocked) ──
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

    $stopIds = [];
    foreach ($dayStops as $stop) {
        $stopIds[] = (int)$stop['stop_id'];
    }
    $weatherBlockedStops = [];
    if (!empty($stopIds)) {
        $db = getDB();
        $wph = implode(',', array_fill(0, count($stopIds), '?'));
        try {
            $wStmt = $db->prepare("
                SELECT stop_id, MIN(COALESCE(weather_ok, 1)) AS any_blocked
                FROM job_visits
                WHERE stop_id IN ({$wph})
                  AND status NOT IN ('cancelled', 'skipped')
                GROUP BY stop_id
            ");
            $wStmt->execute($stopIds);
            while ($wRow = $wStmt->fetch(PDO::FETCH_ASSOC)) {
                $weatherBlockedStops[(int)$wRow['stop_id']] = ((int)$wRow['any_blocked'] === 0);
            }
        } catch (Throwable $e) {
            // weather_ok column may not exist on older installs — continue
        }
    }

    $issues  = [];
    $signals = [];

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

    $totalDayMin = $dayDuration + $driveMin;
    $loadPct     = $totalDayMin > 0 ? (int)round(($totalDayMin / 480) * 100) : 0;
    if ($loadPct > 100) {
        $issues[] = "Over capacity ({$loadPct}% of 8h)";
        $signals['load'] = 'red';
    } elseif ($loadPct > 85) {
        $issues[] = "Near capacity ({$loadPct}% of 8h)";
        $signals['load'] = 'amber';
    } else {
        $signals['load'] = count($dayStops) > 0 ? 'green' : 'grey';
    }

    $tempHi = (float)($weather['temp_high'] ?? 15);
    $tempLo = (float)($weather['temp_low'] ?? 5);
    $weatherIssues = [];
    if ($precip >= $opsMaxPrecip || strpos($condLow, 'storm') !== false || strpos($condLow, 'thunder') !== false) {
        $weatherIssues[] = "Heavy precip ({$precip}%)";
    } elseif ($precip >= $opsBorderlineL) {
        $weatherIssues[] = "Rain likely ({$precip}%)";
    }
    if ($wind >= $opsMaxWind)   $weatherIssues[] = "High wind ({$wind} km/h)";
    if ($tempLo <= $opsMinTemp) $weatherIssues[] = "Freezing ({$tempLo}°C)";
    if ($tempHi >= $opsMaxTemp) $weatherIssues[] = "Extreme heat ({$tempHi}°C)";
    if (!empty($weatherIssues)) {
        $issues = array_merge($issues, $weatherIssues);
        $signals['weather'] = ($precip >= $opsMaxPrecip || $wind >= $opsMaxWind) ? 'red' : 'amber';
    } else {
        $signals['weather'] = count($dayStops) > 0 ? 'green' : 'grey';
    }

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

    if (empty($dayStops)) {
        $verdict = 'empty';
    } elseif (in_array('red', $signals, true)) {
        $verdict = 'no-go';
    } elseif (in_array('amber', $signals, true)) {
        $verdict = 'caution';
    } else {
        $verdict = 'go';
    }

    return [
        'revenue'          => $dayRev,
        'margin'           => $margin,
        'density'          => $density,
        'weather_risk'     => $weatherRisk,
        'outlier'          => $outlier,
        'drive_min'        => $driveMin,
        'stops'            => count($dayStops),
        'duration_min'     => $dayDuration,
        'has_crew_overlap' => $hasCrewOverlap,
        'verdict'          => $verdict,
        'issues'           => $issues,
        'signals'          => $signals,
        'load_pct'         => $loadPct,
    ];
}

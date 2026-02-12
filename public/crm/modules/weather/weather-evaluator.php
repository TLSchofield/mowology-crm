<?php
/**
 * Weather Evaluator Module
 * /crm/modules/weather/weather-evaluator.php
 *
 * Evaluates hourly forecast data against visit weather rules.
 * Returns OK / NOT_OK / BORDERLINE with reason and worst hour.
 *
 * Usage:
 *   require_once __DIR__ . '/weather-rules.php';
 *   require_once __DIR__ . '/weather-evaluator.php';
 *   $result = evaluateVisitWeather($visitRule, $hourlyForecast, '08:00', '12:00');
 */

declare(strict_types=1);

// ============================================================================
// WEATHER STATUS CONSTANTS
// ============================================================================

define('WEATHER_OK',         'OK');
define('WEATHER_NOT_OK',     'NOT_OK');
define('WEATHER_BORDERLINE', 'BORDERLINE');

// Reason codes
define('REASON_RAIN_RISK',   'RAIN_RISK');
define('REASON_HEAVY_RAIN',  'HEAVY_RAIN');
define('REASON_HIGH_WIND',   'HIGH_WIND');
define('REASON_TOO_COLD',    'TOO_COLD');
define('REASON_TOO_HOT',     'TOO_HOT');
define('REASON_FREEZE',      'FREEZE');

// ============================================================================
// CORE EVALUATION
// ============================================================================

/**
 * Evaluate weather conditions for a visit window.
 *
 * @param array  $visitRule      Aggregated weather rules (from aggregateVisitRules)
 * @param array  $hourlyForecast Hourly forecast blocks for the visit window
 * @param string $startTime      Visit start time HH:MM (for context/logging)
 * @param string $endTime        Visit end time HH:MM
 * @return array Evaluation result:
 *   - status:       OK | NOT_OK | BORDERLINE
 *   - reason:       null | RAIN_RISK | HEAVY_RAIN | HIGH_WIND | TOO_COLD | TOO_HOT | FREEZE
 *   - worst_hour:   array (the worst hourly block)
 *   - summary:      string (human-readable explanation)
 *   - raw_snapshot:  array (full hourly data for the window)
 *   - checks:       array (detailed check results per category)
 */
function evaluateVisitWeather(array $visitRule, array $hourlyForecast, string $startTime, string $endTime): array
{
    $result = [
        'status'       => WEATHER_OK,
        'reason'       => null,
        'worst_hour'   => null,
        'summary'      => 'Weather conditions are acceptable',
        'raw_snapshot' => $hourlyForecast,
        'checks'       => [],
    ];

    $policy = $visitRule['weather_policy'] ?? 'ANY';

    // Policy ANY = always OK (still store snapshot for audit)
    if ($policy === 'ANY') {
        $result['summary'] = 'Service accepts any weather conditions';
        return $result;
    }

    if (empty($hourlyForecast)) {
        $result['status']  = WEATHER_BORDERLINE;
        $result['reason']  = 'NO_DATA';
        $result['summary'] = 'No forecast data available for evaluation';
        return $result;
    }

    // Run all checks
    $precipCheck = checkPrecipitation($visitRule, $hourlyForecast);
    $windCheck   = checkWind($visitRule, $hourlyForecast);
    $tempCheck   = checkTemperature($visitRule, $hourlyForecast);

    $result['checks'] = [
        'precipitation' => $precipCheck,
        'wind'          => $windCheck,
        'temperature'   => $tempCheck,
    ];

    // Determine worst status across all checks
    $worstStatus = WEATHER_OK;
    $worstReason = null;
    $worstHour   = null;
    $summaryParts = [];

    foreach ([$precipCheck, $windCheck, $tempCheck] as $check) {
        if ($check['status'] === WEATHER_NOT_OK) {
            $worstStatus = WEATHER_NOT_OK;
            $worstReason = $check['reason'];
            $worstHour   = $check['worst_hour'];
            $summaryParts[] = $check['summary'];
        } elseif ($check['status'] === WEATHER_BORDERLINE && $worstStatus !== WEATHER_NOT_OK) {
            $worstStatus = WEATHER_BORDERLINE;
            $worstReason = $check['reason'];
            $worstHour   = $check['worst_hour'];
            $summaryParts[] = $check['summary'];
        }
    }

    $result['status']     = $worstStatus;
    $result['reason']     = $worstReason;
    $result['worst_hour'] = $worstHour;

    if (!empty($summaryParts)) {
        $result['summary'] = implode('; ', $summaryParts);
    }

    return $result;
}

// ============================================================================
// INDIVIDUAL CHECKS
// ============================================================================

/**
 * Check precipitation against rules.
 * Uses precip_mm as primary metric, precip_chance_pct as fallback/secondary.
 *
 * @param array $rule    Visit weather rules
 * @param array $hourly  Hourly forecast blocks
 * @return array Check result with status, reason, worst_hour, summary
 */
function checkPrecipitation(array $rule, array $hourly): array
{
    $result = ['status' => WEATHER_OK, 'reason' => null, 'worst_hour' => null, 'summary' => 'Precipitation OK'];

    $maxChancePct = isset($rule['max_precip_chance_pct']) ? (int)$rule['max_precip_chance_pct'] : null;
    $maxMmPerHr   = isset($rule['max_precip_mm_per_hr']) ? (float)$rule['max_precip_mm_per_hr'] : null;

    $borderlineLow  = (int)($rule['borderline_precip_chance_low'] ?? 30);
    $borderlineHigh = (int)($rule['borderline_precip_chance_high'] ?? 50);

    $worstScore = 0;

    foreach ($hourly as $block) {
        $precipMm    = (float)($block['precip_mm'] ?? 0);
        $precipChance = (int)($block['precip_chance_pct'] ?? 0);
        $score = 0;

        // Check mm/hr threshold (primary)
        if ($maxMmPerHr !== null && $precipMm > $maxMmPerHr) {
            $score = 3; // NOT_OK
        }

        // Check chance % threshold
        if ($maxChancePct !== null && $precipChance > $maxChancePct) {
            $score = max($score, 3); // NOT_OK
        } elseif ($maxChancePct !== null && $precipChance >= $borderlineLow && $precipChance <= $borderlineHigh) {
            $score = max($score, 2); // BORDERLINE
        }

        if ($score > $worstScore) {
            $worstScore = $score;
            $result['worst_hour'] = $block;
        }
    }

    if ($worstScore >= 3) {
        $result['status'] = WEATHER_NOT_OK;
        $result['reason'] = REASON_RAIN_RISK;
        $wh = $result['worst_hour'];
        $result['summary'] = sprintf(
            'Rain risk: %d%% chance, %.1fmm at %s (thresholds: %s%% / %smm)',
            $wh['precip_chance_pct'] ?? 0,
            $wh['precip_mm'] ?? 0,
            substr($wh['hour'] ?? '', 11, 5),
            $maxChancePct ?? 'n/a',
            $maxMmPerHr ?? 'n/a'
        );
    } elseif ($worstScore >= 2) {
        $result['status'] = WEATHER_BORDERLINE;
        $result['reason'] = REASON_RAIN_RISK;
        $wh = $result['worst_hour'];
        $result['summary'] = sprintf(
            'Borderline rain: %d%% chance at %s (uncertain band: %d-%d%%)',
            $wh['precip_chance_pct'] ?? 0,
            substr($wh['hour'] ?? '', 11, 5),
            $borderlineLow,
            $borderlineHigh
        );
    }

    return $result;
}

/**
 * Check wind speed against rules.
 *
 * @param array $rule
 * @param array $hourly
 * @return array Check result
 */
function checkWind(array $rule, array $hourly): array
{
    $result = ['status' => WEATHER_OK, 'reason' => null, 'worst_hour' => null, 'summary' => 'Wind OK'];

    $maxWind = isset($rule['max_wind_kph']) ? (float)$rule['max_wind_kph'] : null;
    if ($maxWind === null) {
        return $result;
    }

    $worstWindKph = 0;
    foreach ($hourly as $block) {
        $windKph = (float)($block['wind_kph'] ?? 0);
        if ($windKph > $worstWindKph) {
            $worstWindKph = $windKph;
            $result['worst_hour'] = $block;
        }
    }

    // 90% of threshold = borderline
    $borderlineThreshold = $maxWind * 0.9;

    if ($worstWindKph > $maxWind) {
        $result['status'] = WEATHER_NOT_OK;
        $result['reason'] = REASON_HIGH_WIND;
        $result['summary'] = sprintf(
            'High wind: %.0f km/h at %s (max: %.0f km/h)',
            $worstWindKph,
            substr($result['worst_hour']['hour'] ?? '', 11, 5),
            $maxWind
        );
    } elseif ($worstWindKph > $borderlineThreshold) {
        $result['status'] = WEATHER_BORDERLINE;
        $result['reason'] = REASON_HIGH_WIND;
        $result['summary'] = sprintf(
            'Borderline wind: %.0f km/h at %s (max: %.0f km/h)',
            $worstWindKph,
            substr($result['worst_hour']['hour'] ?? '', 11, 5),
            $maxWind
        );
    }

    return $result;
}

/**
 * Check temperature against rules.
 *
 * @param array $rule
 * @param array $hourly
 * @return array Check result
 */
function checkTemperature(array $rule, array $hourly): array
{
    $result = ['status' => WEATHER_OK, 'reason' => null, 'worst_hour' => null, 'summary' => 'Temperature OK'];

    $minTemp = isset($rule['min_temp_c']) ? (float)$rule['min_temp_c'] : null;
    $maxTemp = isset($rule['max_temp_c']) ? (float)$rule['max_temp_c'] : null;

    if ($minTemp === null && $maxTemp === null) {
        return $result;
    }

    $worstDeviation = 0;

    foreach ($hourly as $block) {
        $temp = (float)($block['temp_c'] ?? 0);
        $deviation = 0;
        $reason = null;

        if ($minTemp !== null && $temp < $minTemp) {
            $deviation = $minTemp - $temp;
            $reason = ($temp <= 0) ? REASON_FREEZE : REASON_TOO_COLD;
        }
        if ($maxTemp !== null && $temp > $maxTemp) {
            $deviation = $temp - $maxTemp;
            $reason = REASON_TOO_HOT;
        }

        if ($deviation > $worstDeviation) {
            $worstDeviation = $deviation;
            $result['worst_hour'] = $block;
            $result['reason'] = $reason;
        }
    }

    if ($worstDeviation > 0) {
        $result['status'] = WEATHER_NOT_OK;
        $wh = $result['worst_hour'];
        $result['summary'] = sprintf(
            '%s: %.1f°C at %s (allowed: %s to %s°C)',
            str_replace('_', ' ', $result['reason']),
            $wh['temp_c'] ?? 0,
            substr($wh['hour'] ?? '', 11, 5),
            $minTemp !== null ? "{$minTemp}°C" : 'n/a',
            $maxTemp !== null ? "{$maxTemp}" : 'n/a'
        );
    } elseif ($worstDeviation === 0.0 && $result['worst_hour'] === null) {
        // Check borderline: within 2°C of threshold
        foreach ($hourly as $block) {
            $temp = (float)($block['temp_c'] ?? 0);
            if ($minTemp !== null && $temp < ($minTemp + 2) && $temp >= $minTemp) {
                $result['status'] = WEATHER_BORDERLINE;
                $result['reason'] = REASON_TOO_COLD;
                $result['worst_hour'] = $block;
                $result['summary'] = sprintf(
                    'Borderline cold: %.1f°C at %s (min: %.1f°C)',
                    $temp,
                    substr($block['hour'] ?? '', 11, 5),
                    $minTemp
                );
                break;
            }
            if ($maxTemp !== null && $temp > ($maxTemp - 2) && $temp <= $maxTemp) {
                $result['status'] = WEATHER_BORDERLINE;
                $result['reason'] = REASON_TOO_HOT;
                $result['worst_hour'] = $block;
                $result['summary'] = sprintf(
                    'Borderline hot: %.1f°C at %s (max: %.1f°C)',
                    $temp,
                    substr($block['hour'] ?? '', 11, 5),
                    $maxTemp
                );
                break;
            }
        }
    }

    return $result;
}

// ============================================================================
// BATCH EVALUATION HELPER
// ============================================================================

/**
 * Evaluate weather for a visit using its database record.
 * Loads the visit, plan, service package, property coords, and forecast.
 *
 * @param int $visitId
 * @return array|null Evaluation result or null on error
 */
function evaluateVisitById(int $visitId): ?array
{
    try {
        $db = getDB();

        // Get visit + plan + property info
        $stmt = $db->prepare("
            SELECT v.id, v.scheduled_date, v.scheduled_time_start, v.scheduled_time_end,
                   v.assigned_crew_id, v.status,
                   p.service_package_id, p.service_type, p.property_id, p.title AS plan_title,
                   prop.latitude, prop.longitude, prop.address
            FROM job_visits v
            JOIN job_plans p ON v.plan_id = p.id
            JOIN properties prop ON p.property_id = prop.id
            WHERE v.id = ?
        ");
        $stmt->execute([$visitId]);
        $visit = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$visit) {
            error_log("evaluateVisitById: Visit {$visitId} not found");
            return null;
        }

        $lat = (float)($visit['latitude'] ?? 0);
        $lon = (float)($visit['longitude'] ?? 0);

        if ($lat === 0.0 && $lon === 0.0) {
            return [
                'status'       => WEATHER_BORDERLINE,
                'reason'       => 'NO_COORDS',
                'worst_hour'   => null,
                'summary'      => 'Property has no GPS coordinates — cannot evaluate weather',
                'raw_snapshot' => [],
                'checks'       => [],
                'visit'        => $visit,
            ];
        }

        // Get weather rules for the service package
        $servicePackageIds = [];
        if (!empty($visit['service_package_id'])) {
            $servicePackageIds[] = (int)$visit['service_package_id'];
        }
        $visitRule = aggregateVisitRules($servicePackageIds);

        // Get hourly forecast for the visit window
        $date      = $visit['scheduled_date'];
        $timeStart = $visit['scheduled_time_start'] ?? '08:00';
        $timeEnd   = $visit['scheduled_time_end'] ?? '17:00';

        // Ensure time format is HH:MM
        if (strlen($timeStart) > 5) $timeStart = substr($timeStart, 0, 5);
        if (strlen($timeEnd) > 5) $timeEnd = substr($timeEnd, 0, 5);

        require_once dirname(dirname(__DIR__)) . '/includes/weather-service.php';
        $hourlyWindow = getHourlyForecastWindow($lat, $lon, $date, $timeStart, $timeEnd);

        // Evaluate
        $evalResult = evaluateVisitWeather($visitRule, $hourlyWindow, $timeStart, $timeEnd);
        $evalResult['visit'] = $visit;
        $evalResult['visit_rule'] = $visitRule;

        return $evalResult;
    } catch (Throwable $e) {
        error_log("evaluateVisitById error: " . $e->getMessage());
        return null;
    }
}

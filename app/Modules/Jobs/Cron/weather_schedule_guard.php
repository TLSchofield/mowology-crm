<?php
/**
 * Weather Schedule Guard — Cron Job
 * /crm/cron/weather_schedule_guard.php
 *
 * Runs daily (recommended: noon) to evaluate upcoming visits against weather forecasts.
 * For each visit: evaluates, snapshots, flags, or auto-reschedules based on rules.
 *
 * Supports both CLI and web POST execution.
 * Web: POST /crm/cron/weather_schedule_guard.php
 * CLI: php weather_schedule_guard.php
 *
 * Deduplicates via weather_action_log unique key to prevent repeat actions.
 */

declare(strict_types=1);

if (!defined('APP_ROOT')) {
    $__dir = __DIR__;
    for ($__i = 0; $__i < 5; $__i++) {
        $__dir = dirname($__dir);
        if (is_file($__dir . '/app/Core/paths.php')) {
            require_once $__dir . '/app/Core/paths.php';
            break;
        }
    }
    unset($__dir, $__i);
}

// Fatal error handler
set_error_handler(function($severity, $message, $file, $line) {
    throw new ErrorException($message, 0, $severity, $file, $line);
});

// Determine execution mode
$isCli = (php_sapi_name() === 'cli');

// Web mode: POST only + auth check
if (!$isCli) {
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        http_response_code(405);
        echo json_encode(['success' => false, 'error' => 'POST method required']);
        exit;
    }

    require_once PUBLIC_ROOT . '/loginAuth/auth.php';
    require_once CRM_INCLUDES . '/functions.php';
    requireLogin();
    $user = getCurrentUser();

    if (($user['role'] ?? '') !== 'admin') {
        http_response_code(403);
        echo json_encode(['success' => false, 'error' => 'Admin access required']);
        exit;
    }

    header('Content-Type: application/json');
} else {
    // CLI mode: bootstrap DB
    require_once PUBLIC_ROOT . '/loginAuth/auth.php';
    require_once CRM_INCLUDES . '/functions.php';
}

// Load modules
require_once CRM_INCLUDES . '/weather-service.php';
require_once CRM_ROOT . '/modules/weather/weather-rules.php';
require_once CRM_ROOT . '/modules/weather/weather-evaluator.php';
require_once CRM_ROOT . '/modules/weather/weather-card.php';
require_once CRM_ROOT . '/modules/scheduling/rescheduler.php';
require_once CRM_ROOT . '/modules/scheduling/swap-suggestions.php';
require_once CRM_ROOT . '/modules/snapshots/snapshot-manager.php';
require_once CRM_ROOT . '/modules/notifications/alert-engine.php';
require_once CRM_INCLUDES . '/plan-functions.php';

function jsonRespond(array $data): void {
    echo json_encode($data, JSON_PRETTY_PRINT);
    exit;
}

try {
    $db = getDB();
    $ops = getWeatherOpsConstraints();
    $lookaheadDays = (int)($ops['lookahead_days'] ?? 2);

    // Get visits in the lookahead window
    $startDate = date('Y-m-d');
    $endDate   = date('Y-m-d', strtotime("+{$lookaheadDays} days"));

    $stmt = $db->prepare("
        SELECT v.id AS visit_id, v.visit_number, v.scheduled_date,
               v.scheduled_time_start, v.scheduled_time_end,
               v.assigned_crew_id, v.status, v.weather_status,
               p.service_package_id, p.service_type, p.property_id, p.title AS plan_title,
               prop.latitude, prop.longitude, prop.address
        FROM job_visits v
        JOIN job_plans p ON v.plan_id = p.id
        JOIN properties prop ON p.property_id = prop.id
        WHERE v.scheduled_date BETWEEN ? AND ?
          AND v.status = 'scheduled'
        ORDER BY v.scheduled_date, v.scheduled_time_start
    ");
    $stmt->execute([$startDate, $endDate]);
    $visits = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $results = [
        'evaluated'      => 0,
        'ok'             => 0,
        'borderline'     => 0,
        'not_ok'         => 0,
        'auto_moved'     => 0,
        'action_list'    => 0,
        'skipped_dedup'  => 0,
        'errors'         => [],
        'details'        => [],
    ];

    $actionItems = [];

    foreach ($visits as $visit) {
        $visitId = (int)$visit['visit_id'];

        // Skip if already evaluated today
        if (hasWeatherActionToday('WEATHER_EVAL', 'visit', $visitId)) {
            $results['skipped_dedup']++;
            continue;
        }

        $lat = (float)($visit['latitude'] ?? 0);
        $lon = (float)($visit['longitude'] ?? 0);

        if ($lat === 0.0 && $lon === 0.0) {
            $results['errors'][] = "Visit {$visit['visit_number']}: No GPS coordinates on property";
            continue;
        }

        // Get weather rules for the service package
        $servicePackageIds = [];
        if (!empty($visit['service_package_id'])) {
            $servicePackageIds[] = (int)$visit['service_package_id'];
        }
        $visitRule = aggregateVisitRules($servicePackageIds);

        // Skip evaluation if policy is ANY
        if (($visitRule['weather_policy'] ?? 'ANY') === 'ANY') {
            // Still store snapshot for audit
            $timeStart = $visit['scheduled_time_start'] ?? '08:00';
            $timeEnd   = $visit['scheduled_time_end'] ?? '17:00';
            if (strlen($timeStart) > 5) $timeStart = substr($timeStart, 0, 5);
            if (strlen($timeEnd) > 5) $timeEnd = substr($timeEnd, 0, 5);

            $hourlyWindow = getHourlyForecastWindow($lat, $lon, $visit['scheduled_date'], $timeStart, $timeEnd);
            $evalResult = evaluateVisitWeather($visitRule, $hourlyWindow, $timeStart, $timeEnd);
            $evalResult['visit_rule'] = $visitRule;

            saveWeatherSnapshot($visitId, $evalResult);
            logWeatherAction('WEATHER_EVAL', 'visit', $visitId, json_encode(['status' => 'OK', 'policy' => 'ANY']));

            $results['evaluated']++;
            $results['ok']++;
            continue;
        }

        // Evaluate weather
        $timeStart = $visit['scheduled_time_start'] ?? '08:00';
        $timeEnd   = $visit['scheduled_time_end'] ?? '17:00';
        if (strlen($timeStart) > 5) $timeStart = substr($timeStart, 0, 5);
        if (strlen($timeEnd) > 5) $timeEnd = substr($timeEnd, 0, 5);

        $hourlyWindow = getHourlyForecastWindow($lat, $lon, $visit['scheduled_date'], $timeStart, $timeEnd);
        $evalResult = evaluateVisitWeather($visitRule, $hourlyWindow, $timeStart, $timeEnd);
        $evalResult['visit_rule'] = $visitRule;

        // Save snapshot
        saveWeatherSnapshot($visitId, $evalResult);

        // Generate weather card
        $snapshot = $evalResult;
        $snapshot['hourly_window'] = $evalResult['raw_snapshot'] ?? [];
        $snapshot['evaluated_at'] = date('Y-m-d H:i:s');
        $cardPath = generateWeatherCard($snapshot, $visit['visit_number'], $visit['scheduled_date'],
            $timeStart . ' - ' . $timeEnd);
        if ($cardPath) {
            updateWeatherCardPath($visitId, $cardPath);
        }

        // Log evaluation
        logWeatherAction('WEATHER_EVAL', 'visit', $visitId, json_encode([
            'status' => $evalResult['status'],
            'reason' => $evalResult['reason'],
        ]));

        $results['evaluated']++;

        $detail = [
            'visit_id'     => $visitId,
            'visit_number' => $visit['visit_number'],
            'date'         => $visit['scheduled_date'],
            'status'       => $evalResult['status'],
            'reason'       => $evalResult['reason'],
            'summary'      => $evalResult['summary'],
        ];

        if ($evalResult['status'] === 'OK') {
            $results['ok']++;
        } elseif ($evalResult['status'] === 'BORDERLINE') {
            $results['borderline']++;

            if ((int)($visitRule['require_manual_if_uncertain'] ?? 1) === 1) {
                // Add to action list
                $results['action_list']++;
                $actionItems[] = [
                    'visit_id'   => $visitId,
                    'status'     => 'BORDERLINE',
                    'reason'     => $evalResult['reason'],
                    'summary'    => $evalResult['summary'],
                    'visit_data' => $visit,
                ];
            }
            // else treat as OK
        } elseif ($evalResult['status'] === 'NOT_OK') {
            $results['not_ok']++;

            // Attempt auto-reschedule if enabled
            if ((int)($visitRule['auto_reschedule'] ?? 0) === 1 && ($ops['auto_reschedule_enabled'] ?? false)) {
                $alternateSlot = findAlternateSlot($visitId, $visitRule, array_merge($visit, [
                    'latitude' => $lat,
                    'longitude' => $lon,
                ]));

                if ($alternateSlot) {
                    $moveResult = executeReschedule($visitId, $alternateSlot['date'], $alternateSlot['time_start'], 0, 'Auto-reschedule: ' . ($evalResult['reason'] ?? 'weather'));

                    if ($moveResult['success']) {
                        $results['auto_moved']++;
                        $detail['auto_moved_to'] = $alternateSlot['date'] . ' ' . $alternateSlot['time_start'];

                        $actionItems[] = [
                            'visit_id'        => $visitId,
                            'status'          => 'NOT_OK',
                            'reason'          => $evalResult['reason'],
                            'summary'         => $evalResult['summary'],
                            'visit_data'      => $visit,
                            'suggested_slot'  => $alternateSlot,
                            'auto_rescheduled'=> true,
                        ];
                    } else {
                        // Auto-move failed, add to action list
                        $results['action_list']++;
                        $detail['auto_move_failed'] = $moveResult['error'];
                        $actionItems[] = [
                            'visit_id'       => $visitId,
                            'status'         => 'NOT_OK',
                            'reason'         => $evalResult['reason'],
                            'summary'        => $evalResult['summary'],
                            'visit_data'     => $visit,
                            'suggested_slot' => $alternateSlot,
                        ];
                    }
                } else {
                    // No alternate slot found
                    $results['action_list']++;
                    $actionItems[] = [
                        'visit_id'   => $visitId,
                        'status'     => 'NOT_OK',
                        'reason'     => $evalResult['reason'],
                        'summary'    => $evalResult['summary'],
                        'visit_data' => $visit,
                    ];
                }
            } else {
                // Manual action required
                $results['action_list']++;
                $suggestedSlot = findAlternateSlot($visitId, $visitRule, array_merge($visit, [
                    'latitude' => $lat,
                    'longitude' => $lon,
                ]));
                $actionItems[] = [
                    'visit_id'       => $visitId,
                    'status'         => 'NOT_OK',
                    'reason'         => $evalResult['reason'],
                    'summary'        => $evalResult['summary'],
                    'visit_data'     => $visit,
                    'suggested_slot' => $suggestedSlot,
                ];
            }
        }

        $results['details'][] = $detail;
    }

    // Send alerts for action items
    if (!empty($actionItems)) {
        $alertResult = sendWeatherAlerts($actionItems);
        $results['alerts'] = $alertResult;
    }

    // ========================================================
    // Salt Operations: Check 7-day forecast for freezing/snow
    // ========================================================
    $saltResults = runSaltAlerts($db);
    $results['salt'] = $saltResults;

    $results['success'] = true;
    $results['run_at'] = date('Y-m-d H:i:s');
    $results['lookahead'] = "{$startDate} to {$endDate}";
    $results['total_visits'] = count($visits);

    if ($isCli) {
        echo json_encode($results, JSON_PRETTY_PRINT) . "\n";
    } else {
        jsonRespond($results);
    }

} catch (Throwable $e) {
    $error = [
        'success' => false,
        'error'   => $e->getMessage(),
        'file'    => $e->getFile(),
        'line'    => $e->getLine(),
    ];

    error_log("Weather Schedule Guard fatal error: " . $e->getMessage());

    if ($isCli) {
        echo json_encode($error, JSON_PRETTY_PRINT) . "\n";
        exit(1);
    } else {
        http_response_code(500);
        jsonRespond($error);
    }
}

/**
 * Run salt operations alert check.
 * Loads salt_ops_config, checks 7-day forecast for freezing/snow conditions,
 * and sends SMS alerts to crew at the configured lead times (24hr, 48hr, 7-day).
 *
 * @param PDO $db
 * @return array Summary of salt alerts sent
 */
function runSaltAlerts(PDO $db): array
{
    $saltResults = ['checked' => false, 'alerts_sent' => 0, 'skipped' => 0, 'errors' => []];

    try {
        // Load salt ops config
        $stmt = $db->prepare("SELECT setting_value FROM ops_settings WHERE setting_key = 'salt_ops_config'");
        $stmt->execute();
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$row) {
            $saltResults['skipped_reason'] = 'No salt config found';
            return $saltResults;
        }

        $config = json_decode($row['setting_value'], true);
        if (!is_array($config)) {
            $saltResults['skipped_reason'] = 'Invalid salt config';
            return $saltResults;
        }

        $saltResults['checked'] = true;
        $triggerTemp = (float)($config['salt_trigger_temp_c'] ?? 0);
        $checkSnow = $config['salt_on_snow'] ?? true;
        $checkFreezing = $config['salt_on_freezing_rain'] ?? true;
        $checkIce = $config['salt_on_ice_pellets'] ?? true;
        $alert7day = $config['alert_7day'] ?? false;
        $alert48hr = $config['alert_48hr'] ?? true;
        $alert24hr = $config['alert_24hr'] ?? true;

        // Get 7-day forecast
        $weekForecast = getWeekForecast('Vancouver', 'BC');

        // Determine which days need salting
        $saltDays = [];
        foreach ($weekForecast as $date => $day) {
            $low = (float)($day['temp_low'] ?? 99);
            $cond = strtolower($day['condition'] ?? '');
            $needsSalt = ($low <= $triggerTemp);

            if (!$needsSalt && $checkSnow && strpos($cond, 'snow') !== false) $needsSalt = true;
            if (!$needsSalt && $checkFreezing && strpos($cond, 'freezing') !== false) $needsSalt = true;
            if (!$needsSalt && $checkIce && strpos($cond, 'ice') !== false) $needsSalt = true;

            if ($needsSalt) {
                $saltDays[$date] = $day;
            }
        }

        if (empty($saltDays)) {
            $saltResults['salt_days'] = 0;
            return $saltResults;
        }

        $saltResults['salt_days'] = count($saltDays);

        // Get all active crew who have SMS enabled
        $crewStmt = $db->prepare("
            SELECT id, phone, full_name
            FROM users
            WHERE is_active = 1 AND IFNULL(receive_weather_sms, 1) = 1 AND phone IS NOT NULL AND phone != ''
        ");
        $crewStmt->execute();
        $crewMembers = $crewStmt->fetchAll(PDO::FETCH_ASSOC);

        if (empty($crewMembers)) {
            $saltResults['skipped_reason'] = 'No crew with SMS enabled';
            return $saltResults;
        }

        $today = new DateTime();

        foreach ($saltDays as $date => $day) {
            $forecastDate = new DateTime($date);
            $daysOut = (int)$today->diff($forecastDate)->days;

            // Determine which alert window applies
            $timing = null;
            if ($daysOut <= 1 && $alert24hr) {
                $timing = '24hr';
            } elseif ($daysOut <= 2 && $alert48hr) {
                $timing = '48hr';
            } elseif ($daysOut <= 7 && $alert7day) {
                $timing = '7day';
            }

            if (!$timing) {
                continue; // No alert window matches
            }

            // Dedup: check if salt alert already sent for this date
            $dedupKey = 'SALT_ALERT_' . $date . '_' . $timing;
            if (hasWeatherActionToday('SALT_ALERT', 'forecast', 0)) {
                // Already sent salt alerts today
                $saltResults['skipped']++;
                continue;
            }

            $low = $day['temp_low'] ?? '?';
            $condition = ucfirst($day['condition'] ?? 'Unknown');
            $dateLabel = date('M j', strtotime($date));

            // Send to each crew member
            foreach ($crewMembers as $crew) {
                $result = sendSaltSmsAlert((int)$crew['id'], $dateLabel, (string)$low, $condition, $timing);
                if ($result['success']) {
                    $saltResults['alerts_sent']++;
                } else {
                    if (strpos($result['error'] ?? '', 'Quiet hours') !== false) {
                        $saltResults['skipped']++;
                    } else {
                        $saltResults['errors'][] = $crew['full_name'] . ': ' . ($result['error'] ?? 'unknown');
                    }
                }
            }

            // Log that salt alerts were sent for this date
            logWeatherAction('SALT_ALERT', 'forecast', 0, json_encode([
                'date' => $date,
                'timing' => $timing,
                'low_temp' => $low,
                'condition' => $condition,
                'crew_notified' => count($crewMembers),
            ]));
        }

    } catch (Throwable $e) {
        $saltResults['errors'][] = 'Salt alert error: ' . $e->getMessage();
        error_log('Salt alert error: ' . $e->getMessage());
    }

    return $saltResults;
}

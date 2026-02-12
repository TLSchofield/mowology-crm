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

    require_once dirname(__DIR__) . '/../loginAuth/auth.php';
    require_once dirname(__DIR__) . '/includes/functions.php';
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
    require_once dirname(__DIR__) . '/../loginAuth/auth.php';
    require_once dirname(__DIR__) . '/includes/functions.php';
}

// Load modules
require_once dirname(__DIR__) . '/includes/weather-service.php';
require_once dirname(__DIR__) . '/modules/weather/weather-rules.php';
require_once dirname(__DIR__) . '/modules/weather/weather-evaluator.php';
require_once dirname(__DIR__) . '/modules/weather/weather-card.php';
require_once dirname(__DIR__) . '/modules/scheduling/rescheduler.php';
require_once dirname(__DIR__) . '/modules/scheduling/swap-suggestions.php';
require_once dirname(__DIR__) . '/modules/snapshots/snapshot-manager.php';
require_once dirname(__DIR__) . '/modules/notifications/alert-engine.php';
require_once dirname(__DIR__) . '/includes/plan-functions.php';

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
               prop.latitude, prop.longitude, prop.address_line1
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

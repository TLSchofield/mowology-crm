<?php
/**
 * Salt Run Manual Trigger API
 *
 * POST actions:
 *   preview  — Fetch live weather + list properties that would be triggered. No writes.
 *   trigger  — Execute: capture weather decision, create any missing emergency visits,
 *              alert all SMS-enabled crew, email admin summary.
 *
 * This bypasses the daily cron dedup and quiet-hours checks because the admin
 * is making a deliberate manual decision (sudden cold snap, unexpected icy conditions).
 *
 * Requires admin role + CSRF token.
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

require_once PUBLIC_ROOT . '/loginAuth/auth.php';
require_once CRM_INCLUDES . '/functions.php';
require_once APP_ROOT    . '/Services/CrmFunctions.php';

requireLogin();
$user = getCurrentUser();

header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'error' => 'POST required']);
    exit;
}

if (($user['role'] ?? '') !== 'admin') {
    http_response_code(403);
    echo json_encode(['success' => false, 'error' => 'Admin role required']);
    exit;
}

if (!verifyCSRFToken($_POST['csrf_token'] ?? '')) {
    http_response_code(403);
    echo json_encode(['success' => false, 'error' => 'Invalid CSRF token']);
    exit;
}

$action      = trim($_POST['action'] ?? '');
$serviceDate = trim($_POST['service_date'] ?? date('Y-m-d', strtotime('+1 day')));
$manualTemp  = isset($_POST['manual_temp']) && $_POST['manual_temp'] !== '' ? (float)$_POST['manual_temp'] : null;
$manualNotes = trim($_POST['manual_notes'] ?? '');

if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $serviceDate)) {
    echo json_encode(['success' => false, 'error' => 'Invalid service_date format (YYYY-MM-DD)']);
    exit;
}

$db = getDB();

// ── Load salt ops config ───────────────────────────────────────────────────
$configRow = $db->prepare("SELECT setting_value FROM ops_settings WHERE setting_key = 'salt_ops_config'");
$configRow->execute();
$configRaw = $configRow->fetch(\PDO::FETCH_ASSOC);
$config      = $configRaw ? (json_decode($configRaw['setting_value'], true) ?: []) : [];
$triggerTemp = (float)($config['salt_trigger_temp_c'] ?? 0);

// ── Fetch live weather ─────────────────────────────────────────────────────
require_once CRM_INCLUDES . '/weather-service.php';

$forecast = null;
$weatherError = null;
try {
    $weekForecast = getWeekForecastFresh('Vancouver', 'BC');
    $forecast     = $weekForecast[$serviceDate] ?? null;
} catch (\Throwable $e) {
    $weatherError = 'Weather API error: ' . $e->getMessage();
}

// If manual temp override is provided, use it regardless of API
$effectiveTemp   = $manualTemp ?? ($forecast ? (float)($forecast['temp_low'] ?? 99) : null);
$effectiveCond   = $manualTemp !== null ? ('Manual override' . ($manualNotes ? ': ' . $manualNotes : '')) : ($forecast['condition'] ?? 'Unknown');
$isManualOverride = $manualTemp !== null;

// ── Find all active winter-service plans ──────────────────────────────────
$planStmt = $db->prepare("
    SELECT DISTINCT jp.id AS plan_id, jp.plan_number, jp.property_id,
                    jp.assigned_crew_id AS default_crew_id,
                    jp.title AS plan_title,
                    pr.address AS property_address, pr.city AS property_city,
                    pr.latitude AS property_lat, pr.longitude AS property_lng
    FROM job_plans jp
    JOIN plan_line_items pli ON pli.plan_id = jp.id
    JOIN properties pr ON pr.id = jp.property_id
    WHERE jp.status IN ('active', 'approved')
      AND pli.service_type IN ('snow_removal', 'salt_application')
    ORDER BY pr.address ASC
");
$planStmt->execute();
$winterPlans = $planStmt->fetchAll(\PDO::FETCH_ASSOC);

// For each plan: check if a visit already exists on serviceDate
$properties = [];
foreach ($winterPlans as $plan) {
    $existStmt = $db->prepare("
        SELECT id, visit_number, status, assigned_crew_id
        FROM job_visits
        WHERE plan_id = ? AND scheduled_date = ?
        LIMIT 1
    ");
    $existStmt->execute([$plan['plan_id'], $serviceDate]);
    $existingVisit = $existStmt->fetch(\PDO::FETCH_ASSOC);

    $properties[] = [
        'plan_id'          => (int)$plan['plan_id'],
        'plan_number'      => $plan['plan_number'],
        'property_id'      => (int)$plan['property_id'],
        'property_address' => trim($plan['property_address'] . ', ' . $plan['property_city']),
        'plan_title'       => $plan['plan_title'],
        'default_crew_id'  => $plan['default_crew_id'] ? (int)$plan['default_crew_id'] : null,
        'existing_visit'   => $existingVisit ?: null,
        'will_create_visit'=> $existingVisit === false,
    ];
}

// ── Get crew list for SMS ─────────────────────────────────────────────────
$crewStmt = $db->prepare("
    SELECT id, full_name, phone, IFNULL(receive_weather_sms, 1) AS receive_weather_sms
    FROM users
    WHERE is_active = 1 AND phone IS NOT NULL AND phone != ''
      AND is_vehicle_tablet = 0
    ORDER BY full_name
");
$crewStmt->execute();
$crewList = $crewStmt->fetchAll(\PDO::FETCH_ASSOC);
$smsEnabledCrew = array_filter($crewList, function($c) { return (int)$c['receive_weather_sms'] === 1; });

// ── PREVIEW (no writes) ────────────────────────────────────────────────────
if ($action === 'preview') {
    echo json_encode([
        'success'          => true,
        'service_date'     => $serviceDate,
        'effective_temp'   => $effectiveTemp,
        'effective_cond'   => $effectiveCond,
        'trigger_temp'     => $triggerTemp,
        'is_manual_override' => $isManualOverride,
        'weather_error'    => $weatherError,
        'forecast'         => $forecast,
        'properties'       => $properties,
        'property_count'   => count($properties),
        'visits_existing'  => count(array_filter($properties, function($p) { return $p['existing_visit'] !== null; })),
        'visits_to_create' => count(array_filter($properties, function($p) { return $p['will_create_visit']; })),
        'crew_sms_count'   => count($smsEnabledCrew),
        'crew_list'        => array_values($smsEnabledCrew),
    ]);
    exit;
}

// ── TRIGGER ───────────────────────────────────────────────────────────────
if ($action === 'trigger') {
    require_once APP_ROOT . '/Modules/Jobs/Services/PlanFunctions.php';
    require_once APP_ROOT . '/Modules/Jobs/Cron/weather_schedule_guard.php';
    require_once CRM_ROOT . '/modules/notifications/alert-engine.php';

    $results = [
        'success'           => true,
        'service_date'      => $serviceDate,
        'effective_temp'    => $effectiveTemp,
        'effective_cond'    => $effectiveCond,
        'is_manual_override'=> $isManualOverride,
        'visits_created'    => 0,
        'visits_linked'     => 0,
        'decisions_captured'=> 0,
        'sms_sent'          => 0,
        'sms_failed'        => 0,
        'sms_results'       => [],
        'errors'            => [],
    ];

    // EC source metadata
    $ecIdentifier = getEnvironmentCanadaIdentifier('Vancouver', 'BC') ?? 'bc-74';
    $ecSourceUrl  = "https://api.weather.gc.ca/collections/citypageweather-realtime/items?f=json&lang=en-CA&identifier={$ecIdentifier}";
    $decisionAt   = date('Y-m-d H:i:s');

    // Raw payload captures everything about this decision moment
    $rawPayload = json_encode([
        'source'              => $isManualOverride ? 'Manual override by admin' : 'Environment Canada',
        'source_url'          => $ecSourceUrl,
        'ec_identifier'       => $ecIdentifier,
        'captured_at_utc'     => gmdate('Y-m-d H:i:s') . ' UTC',
        'trigger_date'        => $serviceDate,
        'forecast_day'        => $forecast,
        'manual_override'     => $isManualOverride,
        'manual_temp_c'       => $manualTemp,
        'manual_notes'        => $manualNotes,
        'triggered_by_user_id'=> (int)($user['id'] ?? 0),
        'triggered_by_name'   => $user['full_name'] ?? 'Admin',
        'global_trigger_threshold_c' => $triggerTemp,
    ]);

    foreach ($properties as &$prop) {
        $visitId = null;

        // Reuse existing visit or create an emergency one
        if ($prop['existing_visit']) {
            $visitId = (int)$prop['existing_visit']['id'];
            $results['visits_linked']++;
        } else {
            // Create emergency visit
            try {
                // Get the next visit sequence for this plan
                $seqStmt = $db->prepare("SELECT COUNT(*) FROM job_visits WHERE plan_id = ?");
                $seqStmt->execute([$prop['plan_id']]);
                $seq = (int)$seqStmt->fetchColumn() + 1;
                $visitNumber = generateVisitNumber($prop['plan_number'], $seq);

                $db->prepare("
                    INSERT INTO job_visits
                        (plan_id, visit_number, scheduled_date, status, assigned_crew_id, created_at)
                    VALUES (?, ?, ?, 'scheduled', ?, NOW())
                ")->execute([
                    $prop['plan_id'],
                    $visitNumber,
                    $serviceDate,
                    $prop['default_crew_id'],
                ]);
                $visitId = (int)$db->lastInsertId();
                $prop['created_visit_id'] = $visitId;
                $prop['created_visit_number'] = $visitNumber;
                $results['visits_created']++;
            } catch (\Throwable $e) {
                $results['errors'][] = 'Visit create error for ' . $prop['property_address'] . ': ' . $e->getMessage();
                continue;
            }
        }

        // Capture weather decision — INSERT IGNORE so re-triggering is safe
        try {
            $inserted = $db->prepare("
                INSERT IGNORE INTO salt_weather_decisions
                    (visit_id, property_id, decision_at, trigger_date,
                     overnight_low_c, trigger_threshold_c, weather_condition,
                     data_source, source_station, source_url, raw_api_response)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
            ")->execute([
                $visitId,
                $prop['property_id'],
                $decisionAt,
                $serviceDate,
                $effectiveTemp ?? 0,
                $triggerTemp,
                $effectiveCond,
                $isManualOverride ? 'Manual override' : 'Environment Canada',
                'Vancouver / BC — station ' . $ecIdentifier,
                $ecSourceUrl,
                $rawPayload,
            ]);
            if ($inserted) $results['decisions_captured']++;
        } catch (\Throwable $e) {
            $results['errors'][] = 'Decision capture error: ' . $e->getMessage();
        }
    }
    unset($prop);

    // ── SMS alerts to crew (bypass quiet hours — this is urgent/manual) ────
    $dateLabel = date('M j', strtotime($serviceDate));
    $tempStr   = $effectiveTemp !== null ? number_format($effectiveTemp, 1) : '?';
    $condStr   = $effectiveCond;

    foreach ($smsEnabledCrew as $crew) {
        try {
            $db2 = getDB();
            $crewRow = $db2->prepare("SELECT phone FROM users WHERE id = ?");
            $crewRow->execute([$crew['id']]);
            $crewPhone = $crewRow->fetchColumn();

            if (!$crewPhone) {
                $results['sms_results'][] = ['crew' => $crew['full_name'], 'status' => 'skip', 'reason' => 'No phone'];
                continue;
            }

            require_once CRM_INCLUDES . '/messaging.php';
            $msg = "URGENT: Salt run required " . $dateLabel . ". Forecast: " . $tempStr . "C, " . substr($condStr, 0, 40) . ". Call (778) 846-9273.";
            $smsResult = sendSms($crewPhone, $msg);

            if ($smsResult['success'] ?? false) {
                $results['sms_sent']++;
                $results['sms_results'][] = ['crew' => $crew['full_name'], 'status' => 'sent'];
            } else {
                $results['sms_failed']++;
                $results['sms_results'][] = ['crew' => $crew['full_name'], 'status' => 'failed', 'reason' => $smsResult['error'] ?? 'unknown'];
            }
        } catch (\Throwable $e) {
            $results['sms_failed']++;
            $results['sms_results'][] = ['crew' => $crew['full_name'], 'status' => 'error', 'reason' => $e->getMessage()];
        }
    }

    // ── Admin email summary ────────────────────────────────────────────────
    try {
        $settingsStmt = $db->prepare("SELECT company_email FROM business_settings WHERE id = 1");
        $settingsStmt->execute();
        $biz = $settingsStmt->fetch(\PDO::FETCH_ASSOC);
        $adminEmail = $biz['company_email'] ?? '';

        if ($adminEmail) {
            require_once CRM_INCLUDES . '/messaging.php';
            $propLines = '';
            foreach ($properties as $p) {
                $created = isset($p['created_visit_number']) ? ' <em>(new visit ' . htmlspecialchars($p['created_visit_number']) . ' created)</em>' : ' <em>(existing visit)</em>';
                $propLines .= '<li>' . htmlspecialchars($p['property_address']) . $created . '</li>';
            }
            $overrideNote = $isManualOverride ? '<p style="background:#FFF3E0;border-left:4px solid #F57C00;padding:10px;margin:12px 0;"><strong>Manual temperature override:</strong> ' . htmlspecialchars($tempStr) . '°C entered by ' . htmlspecialchars($user['full_name'] ?? 'Admin') . '. ' . htmlspecialchars($manualNotes) . '</p>' : '';

            $emailBody = '
<div style="font-family:Arial,sans-serif;max-width:600px;">
<div style="background:#0D3B2E;padding:16px 20px;">
  <span style="color:#7FD858;font-size:18px;font-weight:bold;">Mowology</span>
  <span style="color:#a0c8b8;font-size:12px;margin-left:12px;">Manual Salt Run Triggered</span>
</div>
<div style="padding:20px;background:#fff;">
  <h2 style="color:#1565C0;font-size:16px;margin-top:0;">❄️ Salt Run Manually Triggered</h2>
  <p>A manual salt run was triggered by <strong>' . htmlspecialchars($user['full_name'] ?? 'Admin') . '</strong> for <strong>' . htmlspecialchars(date('l, F j, Y', strtotime($serviceDate))) . '</strong>.</p>
  ' . $overrideNote . '
  <table style="width:100%;border-collapse:collapse;margin:12px 0;font-size:13px;">
    <tr><td style="padding:6px 10px;background:#f5f5f5;font-weight:bold;width:180px;">Service date</td><td style="padding:6px 10px;">' . htmlspecialchars(date('l, F j, Y', strtotime($serviceDate))) . '</td></tr>
    <tr><td style="padding:6px 10px;background:#f5f5f5;font-weight:bold;">Forecast temp</td><td style="padding:6px 10px;">' . htmlspecialchars($tempStr) . '°C</td></tr>
    <tr><td style="padding:6px 10px;background:#f5f5f5;font-weight:bold;">Condition</td><td style="padding:6px 10px;">' . htmlspecialchars($condStr) . '</td></tr>
    <tr><td style="padding:6px 10px;background:#f5f5f5;font-weight:bold;">Properties</td><td style="padding:6px 10px;">' . count($properties) . '</td></tr>
    <tr><td style="padding:6px 10px;background:#f5f5f5;font-weight:bold;">Crew SMS sent</td><td style="padding:6px 10px;">' . $results['sms_sent'] . ' / ' . count($smsEnabledCrew) . '</td></tr>
    <tr><td style="padding:6px 10px;background:#f5f5f5;font-weight:bold;">Emergency visits created</td><td style="padding:6px 10px;">' . $results['visits_created'] . '</td></tr>
  </table>
  <h3 style="font-size:13px;color:#444;">Properties included:</h3>
  <ul style="font-size:12px;color:#333;line-height:1.8;">' . $propLines . '</ul>
  <p style="font-size:12px;color:#666;margin-top:16px;">Weather decisions have been captured for all properties. PDF reports can be generated from the <a href="https://mowology.ca/crm/salt/dashboard.php" style="color:#2D8659;">Salt Dashboard</a>.</p>
</div>
<div style="background:#f5f5f5;padding:10px 20px;font-size:11px;color:#999;">Mowology Landscaping &bull; (778) 846-9273 &bull; mowology.ca</div>
</div>';

            sendCrmEmail($adminEmail, '❄️ Manual Salt Run Triggered — ' . date('M j', strtotime($serviceDate)) . ' (' . count($properties) . ' properties)', $emailBody);
        }
    } catch (\Throwable $e) {
        $results['errors'][] = 'Admin email error: ' . $e->getMessage();
    }

    // Audit log
    if ((int)($user['id'] ?? 0) > 0) {
        try {
            $db->prepare("
                INSERT INTO visit_audit_log (visit_id, user_id, action, payload_json)
                VALUES (0, ?, 'SALT_MANUAL_TRIGGER', ?)
            ")->execute([
                (int)$user['id'],
                json_encode([
                    'service_date'     => $serviceDate,
                    'effective_temp'   => $effectiveTemp,
                    'is_manual_override' => $isManualOverride,
                    'properties_count' => count($properties),
                    'visits_created'   => $results['visits_created'],
                    'sms_sent'         => $results['sms_sent'],
                ]),
            ]);
        } catch (\Throwable $e) {
            // non-critical
        }
    }

    echo json_encode($results);
    exit;
}

http_response_code(400);
echo json_encode(['success' => false, 'error' => 'Unknown action: ' . $action]);

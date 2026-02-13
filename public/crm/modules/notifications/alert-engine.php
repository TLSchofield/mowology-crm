<?php
/**
 * Weather Alert Engine Module
 * /crm/modules/notifications/alert-engine.php
 *
 * Sends weather-related notifications via email and SMS.
 * Uses dedup via weather_action_log to prevent repeat alerts.
 *
 * Dependencies:
 *   - /crm/includes/messaging.php (sendEmail, sendSms, hasSmConsent)
 *   - /crm/modules/scheduling/rescheduler.php (logWeatherAction, hasWeatherActionToday)
 */

declare(strict_types=1);

/**
 * Send weather alerts for a batch of action items.
 * Deduplicates by checking weather_action_log before sending.
 *
 * @param array $actionItems Array of action item arrays:
 *   [{visit_id, status, reason, summary, suggested_slot, visit_data}]
 * @return array Summary: ['sent' => int, 'skipped' => int, 'errors' => []]
 */
function sendWeatherAlerts(array $actionItems): array
{
    require_once dirname(dirname(__DIR__)) . '/includes/messaging.php';

    $sent = 0;
    $skipped = 0;
    $errors = [];

    foreach ($actionItems as $item) {
        $visitId = (int)($item['visit_id'] ?? 0);

        // Dedup: skip if alert already sent today for this visit
        if (hasWeatherActionToday('ALERT_SENT', 'visit', $visitId)) {
            $skipped++;
            continue;
        }

        $status  = $item['status'] ?? 'NOT_OK';
        $summary = $item['summary'] ?? 'Weather conditions may affect this visit.';
        $visitData = $item['visit_data'] ?? [];

        // Build notification message
        $visitNumber = $visitData['visit_number'] ?? "Visit #{$visitId}";
        $date        = $visitData['scheduled_date'] ?? 'Unknown date';
        $property    = $visitData['address'] ?? 'Unknown property';
        $crewId      = $visitData['assigned_crew_id'] ?? null;

        // Email to admin
        $emailResult = sendWeatherEmailAlert($visitNumber, $date, $property, $status, $summary, $item);
        if (!$emailResult['success']) {
            $errors[] = "Email failed for {$visitNumber}: " . ($emailResult['error'] ?? 'unknown');
        }

        // SMS to crew member (if auto-rescheduled and crew has consent)
        if (!empty($item['auto_rescheduled']) && $crewId) {
            $smsResult = sendWeatherSmsAlert($crewId, $visitNumber, $date, $status, $summary, $item);
            if (!$smsResult['success']) {
                $errors[] = "SMS failed for crew {$crewId}: " . ($smsResult['error'] ?? 'unknown');
            }
        }

        // Log alert sent
        logWeatherAction('ALERT_SENT', 'visit', $visitId, json_encode([
            'status'  => $status,
            'summary' => $summary,
            'method'  => 'email' . (!empty($item['auto_rescheduled']) ? '+sms' : ''),
        ]));

        $sent++;
    }

    return ['sent' => $sent, 'skipped' => $skipped, 'errors' => $errors];
}

/**
 * Send an email alert about a weather-affected visit.
 *
 * @param string $visitNumber
 * @param string $date
 * @param string $property
 * @param string $status
 * @param string $summary
 * @param array  $item Full action item for extra context
 * @return array ['success' => bool, 'error' => string|null]
 */
function sendWeatherEmailAlert(string $visitNumber, string $date, string $property, string $status, string $summary, array $item): array
{
    // Get admin email from business settings
    try {
        $db = getDB();
        $stmt = $db->prepare("SELECT company_email FROM business_settings WHERE id = 1");
        $stmt->execute();
        $settings = $stmt->fetch(PDO::FETCH_ASSOC);
        $adminEmail = $settings['company_email'] ?? '';
    } catch (Throwable $e) {
        $adminEmail = '';
    }

    if (empty($adminEmail)) {
        return ['success' => false, 'error' => 'No admin email configured'];
    }

    $statusEmoji = $status === 'NOT_OK' ? '🔴' : '🟡';
    $subject = "{$statusEmoji} Weather Alert: {$visitNumber} on {$date}";

    $suggestedSlot = $item['suggested_slot'] ?? null;
    $suggestedHtml = '';
    if ($suggestedSlot) {
        $suggestedHtml = "<p><strong>Suggested new slot:</strong> {$suggestedSlot['date']} at {$suggestedSlot['time_start']}</p>";
    }

    $autoRescheduled = !empty($item['auto_rescheduled']);
    $actionHtml = $autoRescheduled
        ? '<p style="color:#2D8659;"><strong>✓ Auto-rescheduled</strong> to the suggested slot.</p>'
        : '<p><a href="https://mowology.ca/crm/ops/weather_actions.php">Review in Weather Actions →</a></p>';

    $htmlBody = "
        <div style='font-family:Arial,sans-serif;max-width:600px;'>
            <h2 style='color:#333;'>Weather Alert: {$visitNumber}</h2>
            <table style='width:100%;border-collapse:collapse;'>
                <tr><td style='padding:6px;color:#666;'>Visit:</td><td style='padding:6px;'><strong>{$visitNumber}</strong></td></tr>
                <tr><td style='padding:6px;color:#666;'>Date:</td><td style='padding:6px;'>{$date}</td></tr>
                <tr><td style='padding:6px;color:#666;'>Property:</td><td style='padding:6px;'>{$property}</td></tr>
                <tr><td style='padding:6px;color:#666;'>Status:</td><td style='padding:6px;'><strong>{$status}</strong></td></tr>
                <tr><td style='padding:6px;color:#666;'>Reason:</td><td style='padding:6px;'>{$summary}</td></tr>
            </table>
            {$suggestedHtml}
            {$actionHtml}
            <hr style='border:none;border-top:1px solid #eee;margin:20px 0;'>
            <p style='color:#999;font-size:12px;'>Mowology Weather Guard — automated weather monitoring</p>
        </div>
    ";

    return sendEmail($adminEmail, $subject, $htmlBody);
}

/**
 * Send an SMS alert to a crew member about a rescheduled visit.
 *
 * @param int    $crewId
 * @param string $visitNumber
 * @param string $date
 * @param string $status
 * @param string $summary
 * @param array  $item
 * @return array ['success' => bool, 'error' => string|null]
 */
function sendWeatherSmsAlert(int $crewId, string $visitNumber, string $date, string $status, string $summary, array $item): array
{
    try {
        $db = getDB();
        $stmt = $db->prepare("SELECT phone, full_name, IFNULL(receive_weather_sms, 1) AS receive_weather_sms FROM users WHERE id = ?");
        $stmt->execute([$crewId]);
        $crew = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$crew || empty($crew['phone'])) {
            return ['success' => false, 'error' => 'Crew member has no phone number'];
        }

        if (!$crew['receive_weather_sms']) {
            return ['success' => false, 'error' => 'Crew member has weather SMS alerts disabled'];
        }

        // Quiet hours check — load salt ops config
        if (isInQuietHours($db, $crewId)) {
            return ['success' => false, 'error' => 'Quiet hours — crew not clocked in'];
        }

        $newSlot = $item['suggested_slot'] ?? null;
        $msg = "Weather alert: {$visitNumber} on {$date}";
        if ($newSlot && !empty($item['auto_rescheduled'])) {
            $msg .= " moved to {$newSlot['date']}";
        } else {
            $msg .= " - {$status}. Check schedule.";
        }

        return sendSms($crew['phone'], $msg);
    } catch (Throwable $e) {
        error_log("sendWeatherSmsAlert error: " . $e->getMessage());
        return ['success' => false, 'error' => $e->getMessage()];
    }
}

/**
 * Check if the current time is in quiet hours AND the crew member is NOT clocked in.
 * Returns true if SMS should be suppressed (it's quiet hours and they're off the clock).
 *
 * @param PDO $db
 * @param int $crewId
 * @return bool True = suppress SMS
 */
function isInQuietHours(PDO $db, int $crewId): bool
{
    try {
        // Load salt ops config for quiet hours settings
        $stmt = $db->prepare("SELECT setting_value FROM ops_settings WHERE setting_key = 'salt_ops_config'");
        $stmt->execute();
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$row) {
            return false; // No config = no quiet hours
        }

        $config = json_decode($row['setting_value'], true);
        if (!is_array($config)) {
            return false;
        }

        $quietStart = $config['quiet_hour_start'] ?? '20:00';
        $allowClocked = $config['quiet_unless_clocked_in'] ?? true;

        // Check if current time is after quiet hour start (e.g. after 20:00)
        $now = date('H:i');
        if ($now < $quietStart) {
            return false; // Not in quiet hours yet
        }

        // It's quiet hours — check if exception applies
        if ($allowClocked) {
            // Check if crew is clocked in
            $clockStmt = $db->prepare("
                SELECT id FROM time_clock_entries
                WHERE user_id = ? AND clock_out IS NULL AND status = 'active'
                LIMIT 1
            ");
            $clockStmt->execute([$crewId]);
            if ($clockStmt->fetch()) {
                return false; // Clocked in — OK to send
            }
        }

        // In quiet hours and not clocked in (or exception disabled)
        return true;
    } catch (Throwable $e) {
        error_log("isInQuietHours error: " . $e->getMessage());
        return false; // On error, don't suppress — let the SMS through
    }
}

/**
 * Send a salt operation email alert to admin.
 * Notifies about upcoming freezing/snow conditions that require salting.
 *
 * @param string $date       Full date (YYYY-MM-DD)
 * @param string $dayName    Day name (e.g. "Monday")
 * @param string $dateLabel  Short date (e.g. "Feb 14")
 * @param string $lowTemp    Low temperature
 * @param string $highTemp   High temperature
 * @param string $condition  Weather condition
 * @param string $timing     Alert timing label (24hr, 48hr, 7day)
 * @param int    $crewCount  Number of crew being notified
 * @return array ['success' => bool, 'error' => string|null]
 */
function sendSaltEmailAlert(string $date, string $dayName, string $dateLabel, string $lowTemp, string $highTemp, string $condition, string $timing, int $crewCount): array
{
    require_once dirname(dirname(__DIR__)) . '/includes/messaging.php';

    try {
        $db = getDB();
        $stmt = $db->prepare("SELECT company_email FROM business_settings WHERE id = 1");
        $stmt->execute();
        $settings = $stmt->fetch(PDO::FETCH_ASSOC);
        $adminEmail = $settings['company_email'] ?? '';
    } catch (Throwable $e) {
        $adminEmail = '';
    }

    if (empty($adminEmail)) {
        return ['success' => false, 'error' => 'No admin email configured'];
    }

    $timingLabels = [
        '24hr' => '24-Hour Warning',
        '48hr' => '48-Hour Heads-Up',
        '7day' => '7-Day Forecast Alert',
    ];
    $timingLabel = $timingLabels[$timing] ?? $timing;

    $subject = "❄️ Salt Alert ({$timingLabel}): {$dayName} {$dateLabel} — {$lowTemp}°C, {$condition}";

    $htmlBody = "
        <div style='font-family:Arial,sans-serif;max-width:600px;'>
            <h2 style='color:#1565c0;'>❄️ Salt Operations Alert</h2>
            <p style='font-size:16px;color:#333;'><strong>{$timingLabel}</strong> — salting conditions expected.</p>
            <table style='width:100%;border-collapse:collapse;margin:16px 0;'>
                <tr style='background:#e3f2fd;'>
                    <td style='padding:10px;font-weight:600;'>Date</td>
                    <td style='padding:10px;'>{$dayName}, {$dateLabel}</td>
                </tr>
                <tr>
                    <td style='padding:10px;font-weight:600;'>Forecast</td>
                    <td style='padding:10px;'>{$condition}</td>
                </tr>
                <tr style='background:#e3f2fd;'>
                    <td style='padding:10px;font-weight:600;'>Temperature</td>
                    <td style='padding:10px;'>High: {$highTemp}°C / Low: {$lowTemp}°C</td>
                </tr>
                <tr>
                    <td style='padding:10px;font-weight:600;'>Crew Notified</td>
                    <td style='padding:10px;'>{$crewCount} crew member(s) sent SMS</td>
                </tr>
            </table>
            <p><a href='https://mowology.ca/crm/admin/ops_weather.php' style='color:#2D8659;'>View Weather Ops Settings →</a></p>
            <hr style='border:none;border-top:1px solid #eee;margin:20px 0;'>
            <p style='color:#999;font-size:12px;'>Mowology Weather Guard — automated salt operations monitoring</p>
        </div>
    ";

    return sendEmail($adminEmail, $subject, $htmlBody);
}

/**
 * Send a salt operation SMS alert to a specific crew member.
 * Used by the salt alert cron to notify crew about upcoming freezing conditions.
 *
 * @param int    $crewId
 * @param string $date    Forecast date (e.g. "Feb 14")
 * @param string $lowTemp Low temperature
 * @param string $condition Weather condition
 * @param string $timing  Alert timing label (e.g. "24hr", "48hr", "7day")
 * @return array ['success' => bool, 'error' => string|null]
 */
function sendSaltSmsAlert(int $crewId, string $date, string $lowTemp, string $condition, string $timing): array
{
    try {
        $db = getDB();
        $stmt = $db->prepare("SELECT phone, full_name, IFNULL(receive_weather_sms, 1) AS receive_weather_sms FROM users WHERE id = ?");
        $stmt->execute([$crewId]);
        $crew = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$crew || empty($crew['phone'])) {
            return ['success' => false, 'error' => 'Crew member has no phone number'];
        }

        if (!$crew['receive_weather_sms']) {
            return ['success' => false, 'error' => 'Crew member has weather SMS alerts disabled'];
        }

        // Quiet hours check
        if (isInQuietHours($db, $crewId)) {
            return ['success' => false, 'error' => 'Quiet hours — crew not clocked in'];
        }

        require_once dirname(dirname(__DIR__)) . '/includes/messaging.php';

        $msg = "Salt alert ({$timing}): {$date} forecast {$lowTemp}C, {$condition}. Prep salt.";

        return sendSms($crew['phone'], $msg);
    } catch (Throwable $e) {
        error_log("sendSaltSmsAlert error: " . $e->getMessage());
        return ['success' => false, 'error' => $e->getMessage()];
    }
}

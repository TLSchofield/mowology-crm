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
        // Check if users table has a phone column (not yet added)
        $colCheck = $db->query("SHOW COLUMNS FROM users LIKE 'phone'")->fetch();
        if (!$colCheck) {
            return ['success' => false, 'error' => 'Users table does not have a phone column yet'];
        }

        $stmt = $db->prepare("SELECT phone, full_name FROM users WHERE id = ?");
        $stmt->execute([$crewId]);
        $crew = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$crew || empty($crew['phone'])) {
            return ['success' => false, 'error' => 'Crew member has no phone number'];
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

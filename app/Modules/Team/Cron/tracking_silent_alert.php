<?php
/**
 * Silent Tracking Alert — every 10 minutes
 *
 * Finds employees who are clocked in with location tracking enabled but whose
 * phone has reported nothing for N minutes, then:
 *   - tells the EMPLOYEE (push + SMS) — opening the app restarts tracking
 *   - tells the OFFICE (push to admins/managers + email) with the likely cause
 *
 * Why: a swiped-away app, a revoked permission or a dead phone all used to look
 * like "parked". For salting and snow removal a tracking gap found weeks later,
 * during a claim, cannot be repaired — it has to be caught on the day.
 *
 * Settings (time_clock_settings): tracking_silent_minutes (15),
 *   tracking_silent_cooldown_minutes (60), tracking_silent_alert_enabled (1),
 *   tracking_silent_sms_enabled (0 — crew SMS is opt-in; push + office email are not).
 *
 * CLI:  /usr/local/bin/php /home/mowology/public_html/app/Modules/Team/Cron/tracking_silent_alert.php
 * Web:  /crm/cron/tracking_silent_alert.php (admin only, via _guard.php)
 * cPanel schedule: every 10 minutes (minute field: 0,10,20,30,40,50)
 */

declare(strict_types=1);

$__dir = __DIR__;
for ($__i = 0; $__i < 5; $__i++) {
    $__dir = dirname($__dir);
    if (is_file($__dir . '/app/Core/paths.php')) {
        require_once $__dir . '/app/Core/paths.php';
        break;
    }
}
unset($__dir, $__i);

if (php_sapi_name() === 'cli') {
    require_once APP_ROOT . '/Core/CronLock.php';
    \App\Core\CronLock::acquire('tracking_silent_alert');
} else {
    header('Content-Type: application/json');
}

require_once CRM_INCLUDES . '/functions.php';
require_once CRM_INCLUDES . '/messaging.php';
require_once CRM_INCLUDES . '/timeclock-functions.php';
require_once APP_ROOT . '/Modules/Team/Services/TrackingHealthService.php';

$db  = getDB();
$log = [];
$say = static function (string $msg) use (&$log): void {
    $log[] = $msg;
    if (php_sapi_name() === 'cli') { echo '[' . date('H:i:s') . "] {$msg}\n"; }
};

try {
    if (getTimeClockSetting('tracking_silent_alert_enabled', '1') !== '1') {
        $say('Disabled via tracking_silent_alert_enabled.');
        if (php_sapi_name() !== 'cli') { echo json_encode(['success' => true, 'silent' => [], 'log' => $log]); }
        exit;
    }

    $silentMinutes   = (int)getTimeClockSetting('tracking_silent_minutes', (string)TrackingHealthService::DEFAULT_SILENT_MINUTES);
    $cooldownMinutes = (int)getTimeClockSetting('tracking_silent_cooldown_minutes', (string)TrackingHealthService::DEFAULT_COOLDOWN_MINUTES);

    // Tenant identity comes from business_settings — never hardcoded.
    $biz = [];
    try { $biz = $db->query("SELECT * FROM business_settings WHERE id = 1")->fetch(PDO::FETCH_ASSOC) ?: []; } catch (Throwable $e) {}
    $officePhone = trim((string)($biz['company_phone'] ?? $biz['phone'] ?? '')) ?: '(778) 846-9273';
    $officeEmail = trim((string)($biz['company_email'] ?? ''));
    $sender      = trim((string)($biz['company_name'] ?? '')) ?: 'Office';

    $officeIds = array_map('intval', array_column(
        $db->query("SELECT id FROM users WHERE is_active = 1 AND role IN ('admin', 'manager')")->fetchAll(PDO::FETCH_ASSOC), 'id'
    ));

    $push = static function (array $userIds, string $title, string $body) use ($say): void {
        if (!$userIds) { return; }
        try {
            require_once APP_ROOT . '/Services/Push/ApnsService.php';
            require_once APP_ROOT . '/Services/Push/PushDispatcher.php';
            PushDispatcher::notifyUsers($userIds, $title, $body, ['screen' => 'schedule', 'type' => 'tracking_silent']);
        } catch (Throwable $e) {
            $say('  push failed: ' . $e->getMessage());
        }
    };

    $notify = static function (array $row, string $cause, int $minutes) use ($db, $push, $say, $officeIds, $officeEmail, $officePhone, $sender): void {
        $userId = (int)$row['user_id'];
        $name   = (string)($row['full_name'] ?? "Employee #{$userId}");
        $first  = trim(explode(' ', $name)[0]);
        $say("  SILENT {$minutes} min — #{$userId} {$name}. {$cause}");

        // Employee: opening the app is the fix (it resumes tracking on foreground).
        $push([$userId], 'Location tracking has stopped',
              "You're clocked in but we haven't heard from your phone in {$minutes} min. Open the app to resume.");

        $phoneStmt = $db->prepare("SELECT phone FROM users WHERE id = ?");
        $phoneStmt->execute([$userId]);
        $phone = trim((string)$phoneStmt->fetchColumn());
        // Crew SMS is opt-in (tracking_silent_sms_enabled): until the Android tracking rework
        // ships, its gaps are frequent and an hourly text would just teach people to ignore it.
        if ($phone !== '' && getTimeClockSetting('tracking_silent_sms_enabled', '0') === '1') {
            // SMS rules: plain text, <=160 chars, NO URLs, office number included.
            $sms = "Hi {$first}, you're clocked in but location tracking stopped {$minutes} min ago. Please open the app to restart it. Questions? Call {$officePhone}";
            if (mb_strlen($sms) > 160) {
                $sms = "Hi {$first}, location tracking has stopped. Please open the app to restart it. Call {$officePhone}";
            }
            $res = sendSms($phone, $sms, $sender);
            $say('    sms ' . (!empty($res['success']) ? 'sent' : 'FAILED'));
        }

        // Office: who, how long, and the most likely cause.
        $push(array_values(array_diff($officeIds, [$userId])), 'Tracking silent',
              "{$name}: no location for {$minutes} min while clocked in. {$cause}");

        if ($officeEmail !== '') {
            $html = '<p><strong>' . htmlspecialchars($name) . '</strong> is clocked in but their phone has sent no location for <strong>'
                  . (int)$minutes . ' minutes</strong>.</p><p>Likely cause: ' . htmlspecialchars($cause) . '</p>'
                  . '<p>They have been notified by push and SMS and asked to open the app, which restarts tracking. '
                  . 'If this visit needs a location record (salting, snow), confirm with them now rather than later.</p>';
            sendCrmEmail($officeEmail, "Tracking silent: {$name} ({$minutes} min)", $html, null, $sender);
        }
    };

    $found = (new TrackingHealthService($db))->sweep(time(), $silentMinutes, $cooldownMinutes, $notify);
    $say(count($found) . ' silent of those clocked in; ' . count(array_filter($found, static fn ($f) => $f['alerted'])) . ' alerted this run.');

    if (php_sapi_name() !== 'cli') {
        echo json_encode(['success' => true, 'silent' => $found, 'log' => $log]);
    }
} catch (Throwable $e) {
    error_log('[tracking_silent_alert] ' . $e->getMessage());
    $say('ERROR: ' . $e->getMessage());
    if (php_sapi_name() !== 'cli') {
        http_response_code(500);
        echo json_encode(['success' => false, 'error' => 'Cron failed — see server log']);
    }
}

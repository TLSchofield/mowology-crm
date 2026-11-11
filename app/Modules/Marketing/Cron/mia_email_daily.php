<?php
/**
 * Mia — email hub, daily (MiaEmailHubService::daily):
 *   1. Metro Vancouver watering stage (WaterRestrictionService) — a failed fetch keeps the last value.
 *   2. Propose calendar campaigns now due (a proposal only — Tim approves on Mia's card).
 *   3. Queue reminders / last calls of approved campaigns to non-responders (MiaSequenceService).
 *   4. Relearn each contact's best send time (SendTimeService).
 *   5. Keep each calendar campaign's results by year (mia_calendar_results).
 * Sends nothing itself: the campaign sender (every 15 minutes) sends what's queued and due.
 *
 * CLI:  /usr/local/bin/php /home/mowology/public_html/app/Modules/Marketing/Cron/mia_email_daily.php
 * Web:  /crm/cron/mia_email_daily.php (admin only, via _guard.php)
 * cPanel schedule: daily at 5:20 am — 20 5 * * *
 * Registry key: mia_email_daily (public/crm/database_appstack.php) — recordCronRun() on exit.
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
$__cronStart = microtime(true);
require_once APP_ROOT . '/Core/config.php';

$__cli = php_sapi_name() === 'cli';
if ($__cli) {
    require_once APP_ROOT . '/Core/CronLock.php';
    \App\Core\CronLock::acquire('mia_email_daily');
} else {
    header('Content-Type: application/json');
}

require_once CRM_INCLUDES . '/functions.php';
require_once APP_ROOT . '/Services/CrmFunctions.php'; // recordCronRun()
require_once APP_ROOT . '/Modules/Marketing/Services/MiaEmailHubService.php';

try {
    $lines = (new MiaEmailHubService(getDB()))->daily();
    $failed = (bool)array_filter($lines, fn($l) => strpos($l, 'FAILED') !== false);
    $summary = implode(' | ', $lines);
    if (function_exists('recordCronRun')) {
        recordCronRun('mia_email_daily', $failed ? 'warning' : 'success', $summary, (int)round((microtime(true) - $__cronStart) * 1000), null, !$__cli);
    }
    if ($__cli) {
        echo '[' . date('H:i:s') . '] ' . implode("\n", $lines) . "\n";
    } else {
        echo json_encode(['success' => true, 'summary' => $summary, 'steps' => $lines]);
    }
} catch (Throwable $e) {
    error_log('mia_email_daily: ' . $e->getMessage());
    if (function_exists('recordCronRun')) {
        recordCronRun('mia_email_daily', 'error', 'failed', (int)round((microtime(true) - $__cronStart) * 1000), $e->getMessage(), !$__cli);
    }
    if ($__cli) {
        echo 'ERROR: ' . $e->getMessage() . "\n";
        exit(1);
    }
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => 'mia_email_daily failed — see the error log']);
}

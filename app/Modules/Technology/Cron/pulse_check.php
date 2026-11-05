<?php
/**
 * Pulse — is the app working right now? Every 5 minutes (Ittai, 2026-10-28).
 *
 * Checks that each head's card builds, the sign-in and public pages load, the database answers, AI calls are
 * succeeding, nothing is stuck in a queue, and the web server and PHP are not throwing errors (last 15 minutes of the
 * access and error logs). Each result is stored in pulse_checks (migration 1404). Two failing runs in a row put ONE
 * alert in Charlie's urgent email (about 15 minutes later, once per incident) and show red on Ada's System Health
 * page under "Live checks". Read-only apart from pulse_checks: no AI call, sends nothing, changes no client record.
 *
 * CLI:  /usr/local/bin/php /home/mowology/public_html/app/Modules/Technology/Cron/pulse_check.php
 * Web:  /crm/cron/pulse_check.php (admin only, via _guard.php)
 * cPanel schedule: every 5 minutes (*\/5 * * * *)
 * Registry key: pulse_check (public/crm/database_appstack.php) — recordCronRun() on every exit.
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
    \App\Core\CronLock::acquire('pulse_check');
} else {
    header('Content-Type: application/json');
}

require_once APP_ROOT . '/Services/CrmFunctions.php';

$__finish = static function (string $status, string $summary, ?string $error = null) use ($__cronStart, $__cli): void {
    if (function_exists('recordCronRun')) {
        recordCronRun('pulse_check', $status, $summary,
            (int)round((microtime(true) - $__cronStart) * 1000), $error, !$__cli);
    }
    if ($__cli) {
        echo '[' . date('H:i:s') . "] {$summary}" . ($error ? " — {$error}" : '') . "\n";
    } else {
        if ($status === 'error') http_response_code(500);
        echo json_encode(['success' => $status !== 'error', 'summary' => $summary]);
    }
};

try {
    $db = getDB();
    try {
        $db->query("SELECT 1 FROM pulse_checks LIMIT 0");
    } catch (Throwable $e) {
        $__finish('warning', 'Migration 1404 not run yet — nothing to do');
        exit;
    }
    require_once APP_ROOT . '/Modules/Technology/Services/PulseService.php';
    $pulse = new PulseService($db);
    $res = $pulse->run();
    $bad = [];
    foreach ($res as $key => $r) {
        if (in_array($r['status'], ['warn', 'fail'], true)) $bad[] = $key . ' ' . $r['status'];
    }
    $summary = count($res) . ' checks' . ($bad ? ' — ' . implode(', ', $bad) : ' — all good');
    // The cron itself succeeded; what Pulse found is reported through Ada and Charlie, not as a failed cron.
    $__finish('success', $summary);
} catch (Throwable $e) {
    error_log('pulse_check: ' . $e->getMessage());
    $__finish('error', 'pulse_check failed', $e->getMessage());
    if ($__cli) {
        exit(1);
    }
}

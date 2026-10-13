<?php
/**
 * security_posture — Ada's nightly security-posture checks (2026-10-13).
 * Runs every check in SecurityPostureService, stores the verdicts in security_posture_checks
 * (shown on System Health under "Security"). A NEW red reaches Tim once, through Charlie's urgent
 * email (CharlieUrgentService::find → SecurityPostureService::urgent), not from here.
 * Ada only checks: nothing here changes a setting, credential, cron or Stripe configuration.
 *
 * CLI:  /usr/local/bin/php /home/mowology/public_html/app/Modules/Technology/Cron/security_posture.php
 * Web:  /crm/cron/security_posture.php (admin only, POST + CSRF via _guard.php)
 * cPanel schedule: daily 03:40 (40 3 * * *)
 * Registry key: security_posture (public/crm/database_appstack.php) — recordCronRun() on every exit.
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
    \App\Core\CronLock::acquire('security_posture');
} else {
    header('Content-Type: application/json');
}
@set_time_limit(280);
require_once APP_ROOT . '/Services/CrmFunctions.php';
require_once APP_ROOT . '/Modules/Technology/Services/SecurityPostureService.php';

$__finish = static function (string $status, string $summary, ?string $error = null) use ($__cronStart, $__cli): void {
    recordCronRun('security_posture', $status, $summary, (int)round((microtime(true) - $__cronStart) * 1000), $error, !$__cli);
    if ($__cli) {
        echo '[' . date('H:i:s') . "] {$summary}" . ($error ? " — {$error}" : '') . "\n";
    } else {
        if ($status === 'error') http_response_code(500);
        echo json_encode(['success' => $status !== 'error', 'summary' => $summary]);
    }
};

try {
    $db = getDB();
    if (!SecurityPostureService::ready($db)) {
        $__finish('error', 'Security checks not run', 'Table security_posture_checks missing — run migration 1347.');
        exit;
    }
    $svc = new SecurityPostureService($db);
    $stored = $svc->store($svc->run());
    $n = ['red' => [], 'amber' => [], 'unknown' => [], 'green' => []];
    foreach ($stored as $c) $n[$c['state']][] = substr($c['system'], 8);
    $summary = count($stored) . ' checks: ' . count($n['red']) . ' red' . ($n['red'] ? ' (' . implode(', ', $n['red']) . ')' : '')
             . ', ' . count($n['amber']) . ' amber' . ($n['amber'] ? ' (' . implode(', ', $n['amber']) . ')' : '')
             . ', ' . count($n['unknown']) . ' can\'t tell, ' . count($n['green']) . ' green.';
    // The job worked; what it found is on the desk. 'success' even with reds (they are findings, not job failures).
    $__finish('success', $summary);
} catch (Throwable $e) {
    error_log('security_posture failed: ' . $e->getMessage());
    $__finish('error', 'Security checks failed', $e->getMessage());
}

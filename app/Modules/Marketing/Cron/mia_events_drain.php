<?php
/**
 * Mia — events outbox drain (Phase 3.2)
 *
 * Looks for completed visits with approved, consented photos, queues one event per visit, then turns each event into at most
 * one proposal in the owner's queue. The photo gates are checked again at the moment of deciding: if they fail, nothing is queued.
 *
 * Nothing is published or sent: a proposal is a question for the owner.
 *
 * CLI:  /usr/local/bin/php /home/mowology/public_html/app/Modules/Marketing/Cron/mia_events_drain.php
 * Web:  /crm/cron/mia_events_drain.php (admin only, via _guard.php)
 * cPanel crontab: append  >/dev/null 2>&1  (the run is recorded in the Cron Jobs tab; output would be e-mailed).
 * cPanel schedule: every 15 minutes — 0,15,30,45 * * * *
 * Registry key: mia_events_drain (public/crm/database_appstack.php). The registry entry does NOT schedule cPanel:
 * add the crontab line there too.
 * Needs migrations: 1400, 1403, 1404.
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
if (!defined('APP_ROOT')) {                                   // paths.php not found: say so instead of dying on an undefined constant
    error_log('[mia_events_drain] app/Core/paths.php not found above ' . __FILE__);
    if (php_sapi_name() !== 'cli') { header('Content-Type: application/json'); http_response_code(500); }
    echo json_encode(['success' => false, 'summary' => 'Cron could not find app/Core/paths.php']) . "\n";
    exit(1);
}
$__cronStart = microtime(true);
require_once APP_ROOT . '/Core/config.php';

$__cli = php_sapi_name() === 'cli';
if ($__cli) {
    require_once APP_ROOT . '/Core/CronLock.php';
    \App\Core\CronLock::acquire('mia_events_drain');
} else {
    header('Content-Type: application/json');
}
@set_time_limit(120);

require_once APP_ROOT . '/Services/CrmFunctions.php';

$__done = false;
$__finish = static function (string $status, string $summary, ?string $error = null) use ($__cronStart, $__cli, &$__done): void {
    $__done = true;
    try {
        if (function_exists('recordCronRun')) {
        recordCronRun('mia_events_drain', $status, $summary,
            (int)round((microtime(true) - $__cronStart) * 1000), $error, !$__cli);
        }
    } catch (Throwable $recordFailure) {
        error_log('[mia_events_drain] could not record the run: ' . $recordFailure->getMessage());
    }
    if ($__cli) {
        // cPanel e-mails any output, so a normal run is silent: its summary is in the Cron Jobs tab (cron_runs).
        if ($status !== 'success') {
            echo '[' . date('H:i:s') . "] {$status}: {$summary}" . ($error ? " — {$error}" : '') . "\n";
        }
    } else {
        if ($status === 'error') http_response_code(500);
        echo json_encode(['success' => $status !== 'error', 'summary' => $summary]);
    }
};

// A PHP fatal (missing class, parse error in a service) cannot be caught: record it with its file and line instead of
// leaving the Cron Jobs tab saying "Never run".
register_shutdown_function(static function () use ($__finish, &$__done): void {
    $e = error_get_last();
    if (!$__done && $e !== null && in_array($e['type'], [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR, E_RECOVERABLE_ERROR], true)) {
        $__finish('error', 'Cron crashed', substr($e['message'], 0, 300) . ' at ' . basename($e['file']) . ':' . $e['line']);
    }
});

try {
    require_once APP_ROOT . '/Modules/Marketing/Services/MiaEventService.php';
    $db = getDB();
    $missing = [];
    foreach (MiaEventService::TABLES as $t) {
        try { $db->query("SELECT 1 FROM {$t} LIMIT 0"); } catch (Throwable $e) { $missing[] = $t; }
    }
    if ($missing) {
        $__finish('warning', 'Migration not run yet (' . implode(', ', $missing) . ') — nothing to do');
        exit;
    }
    $r = MiaEventService::drainAll($db, new DateTimeImmutable('now'));
    $__finish($r['status'], $r['summary'], $r['status'] === 'error' ? implode('; ', $r['errors']) : null);
    if ($__cli && $r['status'] === 'error') { exit(1); }
} catch (Throwable $e) {
    error_log('[mia_events_drain] ' . $e->getMessage());
    $__finish('error', 'Cron failed', get_class($e) . ': ' . substr($e->getMessage(), 0, 200) . ' at ' . basename($e->getFile()) . ':' . $e->getLine());
    if ($__cli) { exit(1); }
}

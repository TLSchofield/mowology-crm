<?php
declare(strict_types=1);

/**
 * app/Services/Push/Cron/push-drain.php
 *
 * APNs Push Queue Drain — cron job
 *
 * Sends pending push notifications from the push_queue table to Apple's
 * APNs servers. Run every 5 minutes via cPanel cron:
 *
 *   *\/5 * * * * /usr/local/bin/php /home/mowology/public_html/app/Services/Push/Cron/push-drain.php >> /home/mowology/logs/push-drain.log 2>&1
 *
 * Safe to run while the web app is live. Designed to complete within 60s.
 * Each run processes up to 50 queued notifications.
 *
 * Required secrets.php constants:
 *   define('APNS_TEAM_ID',     'AB12CD34EF');
 *   define('APNS_KEY_ID',      'ABCDE12345');
 *   define('APNS_PRIVATE_KEY', "-----BEGIN PRIVATE KEY-----\n...\n-----END PRIVATE KEY-----");
 *   // define('APNS_SANDBOX', true);   // Uncomment for sandbox/TestFlight builds
 */

// ── Bootstrap ─────────────────────────────────────────────────────────────────
if (!defined('APP_ROOT')) {
    $__dir = __DIR__;
    for ($__i = 0; $__i < 6; $__i++) {
        $__dir = dirname($__dir);
        if (is_file($__dir . '/app/Core/paths.php')) {
            require_once $__dir . '/app/Core/paths.php';
            break;
        }
    }
    unset($__dir, $__i);
}

if (!defined('APP_ROOT')) {
    fwrite(STDERR, "[push-drain] FATAL: Could not locate app/Core/paths.php\n");
    exit(1);
}

require_once APP_ROOT . '/Core/config.php';
require_once APP_ROOT . '/Services/Push/ApnsService.php';
require_once APP_ROOT . '/Services/Push/PushDispatcher.php';
// recordCronRun() lives here; the CLI bootstrap (config.php only) doesn't load it.
require_once APP_ROOT . '/Services/CrmFunctions.php';

// ── Guard: only run as CLI ────────────────────────────────────────────────────
if (php_sapi_name() !== 'cli') {
    http_response_code(403);
    echo json_encode(['error' => 'CLI only']);
    exit(1);
}

// ── Run ───────────────────────────────────────────────────────────────────────
$start  = microtime(true);
// Every run is recorded in cron_runs (Ada, 2026-10-15: this cron never was, so its runs couldn't be checked).
$logRun = static function (string $status, string $summary, ?string $error = null) use ($start): void {
    if (!function_exists('recordCronRun')) return;
    try {
        recordCronRun('push_drain', $status, $summary, (int)round((microtime(true) - $start) * 1000), $error, false);
    } catch (\Throwable $e) {
        error_log('[push-drain] could not record the run — ' . $e->getMessage());
    }
};

try {
    $counts = PushDispatcher::drainQueue();
} catch (\Throwable $e) {
    $logRun('error', 'push-drain failed', $e->getMessage());
    throw $e; // same behaviour as before: the failure still surfaces in the cron log
}
$ms     = round((microtime(true) - $start) * 1000);
// Every 5 minutes: a run that sent nothing is recorded at most once an hour (cron_runs is never pruned).
$quiet = (int)$counts['sent'] === 0 && (int)$counts['failed'] === 0 && (int)$counts['expired'] === 0;
if (!$quiet || !(function_exists('cronRecentSuccess') && cronRecentSuccess('push_drain', 55))) $logRun((int)$counts['failed'] > 0 ? 'warning' : 'success',
    "sent={$counts['sent']} failed={$counts['failed']} expired={$counts['expired']}",
    (int)$counts['failed'] > 0 ? $counts['failed'] . ' push(es) failed — see push_queue' : null);

$ts = date('Y-m-d H:i:s');
echo "[{$ts}] push-drain: sent={$counts['sent']} failed={$counts['failed']} expired={$counts['expired']} ({$ms}ms)\n";

exit(0);

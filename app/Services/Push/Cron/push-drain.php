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

// ── Guard: only run as CLI ────────────────────────────────────────────────────
if (php_sapi_name() !== 'cli') {
    http_response_code(403);
    echo json_encode(['error' => 'CLI only']);
    exit(1);
}

// ── Run ───────────────────────────────────────────────────────────────────────
$start  = microtime(true);
$counts = PushDispatcher::drainQueue();
$ms     = round((microtime(true) - $start) * 1000);

$ts = date('Y-m-d H:i:s');
echo "[{$ts}] push-drain: sent={$counts['sent']} failed={$counts['failed']} expired={$counts['expired']} ({$ms}ms)\n";

exit(0);

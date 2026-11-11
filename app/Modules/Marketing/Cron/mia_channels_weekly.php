<?php
/**
 * Mia — channels, weekly: tag the media library (import crew photos, vision pass within its
 * daily cap, learn from engagement), draft this week's Google post (season theme), draft social posts from
 * crew before/after photos, import Google reviews (only once the GBP API is approved and
 * connected) and read Search Console (28 days vs the 28 before) for the website report.
 *
 * Drafts only. Nothing is posted anywhere: Tim approves every post and reply on Mia's card.
 * Once per ISO week (ops_settings mia_channels_week); ?force=1 (web) or --force (CLI) re-runs.
 *
 * CLI:  /usr/local/bin/php /home/mowology/public_html/app/Modules/Marketing/Cron/mia_channels_weekly.php
 * Web:  /crm/cron/mia_channels_weekly.php (admin only, via _guard.php)
 * cPanel schedule: Mondays 6:10 am — 10 6 * * 1
 * Registry key: mia_channels_weekly (public/crm/database_appstack.php).
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
    \App\Core\CronLock::acquire('mia_channels_weekly');
} else {
    header('Content-Type: application/json');
}
@set_time_limit(180);

require_once CRM_INCLUDES . '/functions.php';
require_once CRM_INCLUDES . '/messaging.php';
require_once APP_ROOT . '/Services/CrmFunctions.php';
require_once APP_ROOT . '/Modules/Marketing/Services/MiaChannelsService.php';

$__finish = static function (string $status, string $summary, ?string $error = null) use ($__cronStart, $__cli): void {
    if (function_exists('recordCronRun')) {
        recordCronRun('mia_channels_weekly', $status, $summary,
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
    $ch = new MiaChannelsService(getDB());
    if (!$ch->ready()) {
        $__finish('warning', 'Migration 1200 not run yet — nothing to do');
        exit;
    }
    $force = $__cli ? in_array('--force', $argv ?? [], true) : !empty($_GET['force']);
    $r = $ch->prepareWeek(new DateTimeImmutable('today'), $force);
    if (!empty($r['skipped'])) {
        $__finish('success', 'Already prepared this week');
        exit;
    }
    $__finish($r['web'] === 'error' ? 'warning' : 'success', sprintf(
        'Media: %s; Google post drafted: %d; social drafts: %d; reviews imported: %d; website report: %s',
        $r['media'] ?? 'skipped', $r['gbp_post'], $r['social'], $r['reviews'], $r['web']
    ));
} catch (Throwable $e) {
    error_log('[mia_channels_weekly] ' . $e->getMessage());
    $__finish('error', 'Cron failed', $e->getMessage());
}

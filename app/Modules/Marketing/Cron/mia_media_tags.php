<?php
/**
 * Mia — media library, daily: copy new crew photos into the library and tag them from context
 * (service, season, before/after pair, coarse area, property type, consent), run the vision pass
 * on up to the daily cap (ops_settings media_vision_daily_cap, default 40; Claude
 * claude-sonnet-5-5, ANTHROPIC_API_KEY) for subject, quality and privacy, and fold post
 * engagement back into the per-tag-combination scores the picker uses.
 *
 * CLI:  /usr/local/bin/php /home/mowology/public_html/app/Modules/Marketing/Cron/mia_media_tags.php
 * Web:  /crm/cron/mia_media_tags.php (admin only, via _guard.php)
 * cPanel schedule: daily 5:40 am — 40 5 * * *
 * Registry key: mia_media_tags (public/crm/database_appstack.php).
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
    \App\Core\CronLock::acquire('mia_media_tags');
} else {
    header('Content-Type: application/json');
}
@set_time_limit(180);

require_once CRM_INCLUDES . '/functions.php';
require_once CRM_INCLUDES . '/messaging.php';
require_once APP_ROOT . '/Services/CrmFunctions.php';
require_once APP_ROOT . '/Modules/Marketing/Services/MediaTagService.php';

$__finish = static function (string $status, string $summary, ?string $error = null) use ($__cronStart, $__cli): void {
    if (function_exists('recordCronRun')) {
        recordCronRun('mia_media_tags', $status, $summary,
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
    $svc = new MediaTagService(getDB());
    if (!$svc->ready()) {
        $__finish('warning', 'Migration 1202 not run yet — nothing to do');
        exit;
    }
    $b = $svc->backfill(300);
    $v = $svc->visionPass(MediaTagService::VISION_DAILY_CAP);
    $l = $svc->learn();
    $__finish('success', sprintf('%d crew photos added, %d library items tagged, %d seen by vision (%d failed, %d left today), %d tag combos scored',
        $b['imported'], $b['tagged'], $v['done'], $v['failed'], $v['cap_left'], $l));
} catch (Throwable $e) {
    error_log('[mia_media_tags] ' . $e->getMessage());
    $__finish('error', 'Cron failed', $e->getMessage());
}
